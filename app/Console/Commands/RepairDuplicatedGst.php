<?php

namespace App\Console\Commands;

use App\Services\IfrsPosting;
use Carbon\Carbon;
use IFRS\Models\Entity;
use IFRS\Models\LineItem;
use IFRS\Models\ReportingPeriod;
use IFRS\Models\Vat;
use IFRS\Transactions\JournalEntry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Repair the duplicated applied vats created by the package's
 * double-save bug: line items were saved once explicitly after addVat()
 * and again by the transaction's saveLineItems(), and the second
 * applyVats() firstOrCreate missed the stored (rounded) tax amount
 * whenever the GST component carried more than 4 decimal places —
 * inserting an identical applied-vat row. Every duplicated application
 * also posted its VAT legs twice, so the ledger over-claimed input GST
 * (Dr 430) or over-owed output GST (Cr 2200) by the duplicated amount.
 *
 * For each affected line: keep one applied-vat row, delete the
 * duplicates, and post one correcting journal per (transaction, VAT
 * account) that reverses the excess legs — Dr/Cr on the VAT account
 * flipped, the counter leg back on the line's own account. Correcting
 * journals carry the original transaction_no as reference and its date,
 * so periods stay clean and the audit trail ties back.
 *
 * Idempotent: reruns find nothing once every line has a single applied
 * vat. --dry-run lists the repairs without changing anything.
 */
class RepairDuplicatedGst extends Command
{
    protected $signature = 'ifrs:repair-duplicated-gst
                            {--dry-run : Show what would be repaired without making changes}';

    protected $description = 'Remove duplicated applied vats and post correcting journals for the excess GST legs';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        // Line items with more than one applied vat of the same Vat — the
        // rows are identical, so any beyond the first are duplicates.
        $duplicated = DB::table('ifrs_applied_vats as av')
            ->join('ifrs_line_items as li', 'li.id', '=', 'av.line_item_id')
            ->where('li.vat_inclusive', 1)
            ->groupBy('av.line_item_id', 'av.vat_id')
            ->havingRaw('COUNT(*) > 1')
            ->get([
                'av.line_item_id as line_item_id',
                'av.vat_id as vat_id',
                DB::raw('MIN(av.id) as keep_id'),
                DB::raw('MIN(av.amount) as tax'),
                DB::raw('COUNT(*) as duplicates'),
            ]);

        if ($duplicated->isEmpty()) {
            $this->info('No duplicated applied vats found.');

            return self::SUCCESS;
        }

        $lineItems = DB::table('ifrs_line_items')->whereIn('id', $duplicated->pluck('line_item_id'))->get()->keyBy('id');
        $transactions = DB::table('ifrs_transactions')->whereIn('id', $lineItems->pluck('transaction_id'))->get()->keyBy('id');
        $vatAccounts = Vat::whereIn('id', $duplicated->pluck('vat_id'))->get()->keyBy('id');

        // One repair per (transaction, vat account): the correcting journal
        // nets every excess leg sharing that VAT account.
        $repairs = $duplicated
            ->groupBy(fn ($row) => $lineItems[$row->line_item_id]->transaction_id.'|'.$row->vat_id)
            ->map(function ($rows, $key) use ($lineItems, $transactions, $vatAccounts) {
                [$transactionId, $vatId] = explode('|', $key);
                $transaction = $transactions[$transactionId];
                $vatAccount = $vatAccounts[$vatId]->account_id;

                // The original VAT legs' entry type on the VAT account
                // decides the correction's side: a Dr leg needs a Cr back.
                $vatLegType = DB::table('ifrs_ledgers')
                    ->where('transaction_id', $transactionId)
                    ->where('post_account', $vatAccount)
                    ->value('entry_type');

                return [
                    'transaction' => $transaction,
                    'entity' => Entity::find($transaction->entity_id),
                    'vat_account' => $vatAccount,
                    'credited' => $vatLegType === 'D',
                    // Excess tax per line account, summed across the lines
                    // sharing this VAT account in the one journal.
                    'lines' => $rows->groupBy(fn ($row) => $lineItems[$row->line_item_id]->account_id)
                        ->map(fn ($byAccount, $accountId) => [
                            'account_id' => (int) $accountId,
                            'amount' => $byAccount->sum(fn ($row) => ($row->duplicates - 1) * $row->tax),
                        ])->values()->all(),
                    'delete_ids' => $rows->flatMap(fn ($row) => DB::table('ifrs_applied_vats')
                        ->where('line_item_id', $row->line_item_id)
                        ->where('vat_id', $row->vat_id)
                        ->where('id', '!=', $row->keep_id)
                        ->pluck('id'))->all(),
                ];
            })->values();

        foreach ($repairs as $repair) {
            $transaction = $repair['transaction'];
            $this->line(sprintf(
                '%s %s: correct %s on account %s (%s), delete %d duplicated applied-vat row(s)',
                $transaction->transaction_no,
                $transaction->transaction_date,
                '$'.number_format(collect($repair['lines'])->sum('amount'), 4),
                $repair['vat_account'],
                $repair['credited'] ? 'credit the VAT account' : 'debit the VAT account',
                count($repair['delete_ids'])
            ));
        }

        if ($dryRun) {
            $this->info('Dry run — nothing changed.');

            return self::SUCCESS;
        }

        foreach ($repairs as $repair) {
            try {
                DB::transaction(function () use ($repair) {
                    $transaction = $repair['transaction'];

                    // The correction carries the original date so its period
                    // stays clean — unless that period is closed (prior
                    // financial year), in which case the correction posts
                    // in the current open period instead, as a prior-period
                    // error correction normally would.
                    $date = Carbon::parse($transaction->transaction_date);
                    if (ReportingPeriod::getPeriod($date, $repair['entity'])->status === ReportingPeriod::CLOSED) {
                        $date = now();
                    }

                    $journal = new JournalEntry([
                        'transaction_date' => IfrsPosting::transactionDate($date, $repair['entity']),
                        'account_id' => $repair['vat_account'],
                        'credited' => $repair['credited'],
                        'entity_id' => $transaction->entity_id,
                        'narration' => 'Correction of duplicated GST application on '.$transaction->transaction_no,
                        'reference' => $transaction->transaction_no,
                    ]);

                    foreach ($repair['lines'] as $line) {
                        $journal->addLineItem(LineItem::create([
                            'account_id' => $line['account_id'],
                            'amount' => $line['amount'],
                            'quantity' => 1,
                            'vat_inclusive' => false,
                            'entity_id' => $transaction->entity_id,
                        ]));
                    }

                    $journal->post();

                    DB::table('ifrs_applied_vats')->whereIn('id', $repair['delete_ids'])->delete();
                });
            } catch (\Throwable $e) {
                $this->error(sprintf(
                    'Failed to repair %s: %s',
                    $repair['transaction']->transaction_no,
                    $e->getMessage()
                ));

                continue;
            }
        }

        $repaired = DB::table('ifrs_applied_vats')
            ->selectRaw('line_item_id, COUNT(*) c')
            ->groupBy('line_item_id')
            ->havingRaw('COUNT(*) > 1')
            ->get()
            ->count();

        $this->info($repaired === 0
            ? sprintf('Repaired %d transaction(s).', $repairs->count())
            : sprintf('Done — %d line(s) still duplicated, see errors above.', $repaired));

        return self::SUCCESS;
    }
}

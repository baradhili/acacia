<?php

namespace App\Services;

use App\Models\BasSettlement;
use App\Models\BillPayment;
use Carbon\Carbon;
use IFRS\Models\Account;
use IFRS\Models\Entity;
use IFRS\Models\LineItem;
use IFRS\Models\ReportingPeriod;
use IFRS\Transactions\JournalEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Records BAS settlements: the ATO payment (or refund) that nets GST
 * Payable against GST Receivable and clears both accounts.
 *
 * The unsettled position is the accounts' as-at balances — not
 * per-quarter movement — so one settlement catches up any number of
 * missed quarters, even across closed financial years (the year close
 * carries the balances and balanceAt() reads the whole ledger), and
 * claiming late (the sub-$10k deferral) is simply settling at a later
 * date. The BAS report itself stays movement-based; settlements are
 * the balance-side action.
 *
 * Pay (X > Y):    Dr GST Payable X / Cr GST Receivable Y / Cr Bank X-Y
 * Refund (Y > X): Cr GST Receivable Y / Dr GST Payable X / Dr Bank Y-X
 * An exact offset nets to a zero bank leg; with nothing unsettled the
 * settlement refuses.
 */
class BasSettlementService
{
    public function __construct(protected PeriodLockService $locks) {}

    /**
     * The GST accounts the entity's Vats post to — the same resolution
     * ReportController::ledgerGst uses: the output Vat (G) posts to the
     * payable account, the input Vat (I) to the receivable account, and
     * legacy databases where purchases fall back to the output Vat
     * share one account (settled on its own, receivable null).
     *
     * @return array{payable: ?Account, receivable: ?Account}
     */
    public function gstAccounts(Entity $entity): array
    {
        $inputCode = config('subscriptions.purchase_gst_vat_code', 'I');

        $roleByAccount = [];
        $vats = DB::table('ifrs_vats')
            ->where('entity_id', $entity->id)
            ->whereNotNull('account_id')
            ->get(['code', 'account_id']);

        foreach ($vats as $vat) {
            $role = $vat->code === $inputCode ? 'receivable' : 'payable';
            $roleByAccount[$vat->account_id] = isset($roleByAccount[$vat->account_id]) && $roleByAccount[$vat->account_id] !== $role
                ? 'shared'
                : $role;
        }

        if ($vats->isNotEmpty()) {
            $purchaseVat = BillPayment::purchaseGstVat($entity);
            if ($purchaseVat && $purchaseVat->account_id !== null && $purchaseVat->code !== $inputCode) {
                $roleByAccount[$purchaseVat->account_id] = 'shared';
            }
        }

        $byRole = ['payable' => null, 'receivable' => null, 'shared' => null];
        foreach ($roleByAccount as $accountId => $role) {
            $account = Account::where('entity_id', $entity->id)->where('id', $accountId)->first();
            if ($account) {
                $byRole[$role] = $account;
            }
        }

        if ($byRole['shared'] !== null) {
            return ['payable' => $byRole['shared'], 'receivable' => null];
        }

        return ['payable' => $byRole['payable'], 'receivable' => $byRole['receivable']];
    }

    /**
     * The unsettled GST position at an as-at date: the GST accounts'
     * balances (balanceAt is debit-positive, so the payable credit
     * balance is negated). Everything never settled to date, across
     * quarters and closed years.
     *
     * @return array{payable: float, receivable: float, net: float}
     */
    public function position(?Carbon $asAt = null): array
    {
        $entity = IfrsPosting::resolveEntity();

        return $this->positionFor($entity, $this->gstAccounts($entity), ($asAt ?? now())->copy()->endOfDay());
    }

    /**
     * @param  array{payable: ?Account, receivable: ?Account}  $accounts
     * @return array{payable: float, receivable: float, net: float}
     */
    protected function positionFor(Entity $entity, array $accounts, Carbon $asAt): array
    {
        $payable = $accounts['payable']
            ? max(0.0, -round(OpeningBalances::balanceAt($accounts['payable'], $entity, $asAt), 2))
            : 0.0;
        $receivable = $accounts['receivable']
            ? max(0.0, round(OpeningBalances::balanceAt($accounts['receivable'], $entity, $asAt), 2))
            : 0.0;

        return ['payable' => $payable, 'receivable' => $receivable, 'net' => round($payable - $receivable, 2)];
    }

    /**
     * Record a settlement: net the GST account balances as at as_at,
     * post the clearing journal dated settled_at, and persist the
     * snapshot row — in one transaction. The bank date is typically in
     * the month after the covered quarter (BAS lodgement lag).
     *
     * @param  array{as_at: mixed, settled_at: mixed, reference?: ?string, notes?: ?string}  $data
     */
    public function settle(array $data): BasSettlement
    {
        $entity = IfrsPosting::resolveEntity();
        $asAt = Carbon::parse($data['as_at'])->startOfDay();
        $settledAt = Carbon::parse($data['settled_at'])->startOfDay();

        $this->assertDatePostable($settledAt, $entity, 'bank date');

        $accounts = $this->gstAccounts($entity);
        if (! $accounts['payable'] && ! $accounts['receivable']) {
            throw new \InvalidArgumentException('No GST accounts are configured — seed the chart of accounts.');
        }

        ['payable' => $payable, 'receivable' => $receivable, 'net' => $net] = $this->positionFor($entity, $accounts, $asAt->copy()->endOfDay());

        if ($payable < 0.005 && $receivable < 0.005) {
            throw new \InvalidArgumentException("There is no unsettled GST as at {$asAt->format('d M Y')}.");
        }

        $direction = $net >= 0 ? BasSettlement::DIRECTION_PAY : BasSettlement::DIRECTION_REFUND;

        return DB::transaction(function () use ($entity, $accounts, $asAt, $settledAt, $payable, $receivable, $net, $direction, $data) {
            $journal = $this->postSettlementJournal(
                $entity,
                $accounts,
                $payable,
                $receivable,
                $net,
                $direction,
                $settledAt,
                $asAt,
            );

            $settlement = BasSettlement::create([
                'entity_id' => $entity->id,
                'as_at' => $asAt->toDateString(),
                'settled_at' => $settledAt->toDateString(),
                'gst_payable' => $payable,
                'gst_receivable' => $receivable,
                'net_amount' => $net,
                'bank_amount' => abs($net),
                'direction' => $direction,
                'ifrs_transaction_id' => $journal->id,
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);

            Log::info('BAS settlement posted', [
                'settlement_id' => $settlement->id,
                'as_at' => $asAt->toDateString(),
                'payable' => $payable,
                'receivable' => $receivable,
                'net' => $net,
                'transaction_id' => $journal->id,
            ]);

            return $settlement;
        });
    }

    /**
     * Mirror a settlement's journal back out (a recorded mistake) and
     * mark the settlement reversed, restoring the GST balances. The
     * reversal keeps the original transaction date — a period closed
     * since posting refuses with a clear error instead.
     */
    public function reverse(BasSettlement $settlement): BasSettlement
    {
        if ($settlement->isReversed()) {
            throw new \InvalidArgumentException('This settlement has already been reversed.');
        }

        if (! $settlement->ifrs_transaction_id) {
            throw new \InvalidArgumentException('This settlement has no posted journal to reverse.');
        }

        $reversalId = IfrsPosting::reverseTransaction(
            $settlement->ifrs_transaction_id,
            'Reversal of BAS settlement — '.$settlement->label(),
            'BAS-SETT-'.$settlement->as_at->format('Ymd').'-REV',
            throw: true,
        );

        $settlement->forceFill([
            'reversal_transaction_id' => $reversalId,
            'reversed_at' => now(),
        ])->save();

        Log::info('BAS settlement reversed', [
            'settlement_id' => $settlement->id,
            'reversal_id' => $reversalId,
        ]);

        return $settlement;
    }

    /**
     * Completed BAS quarter ends for the form's quick picks (most
     * recent last): consecutive three-month blocks of the FY from
     * entity.year_start, same arithmetic as the BAS report. Spans the
     * previous FY too, so a catch-up settlement can pick any recent
     * quarter end.
     *
     * @return list<array{end: Carbon, label: string}>
     */
    public function quarterEnds(Entity $entity, ?Carbon $asOf = null): array
    {
        $asOf ??= now();
        $service = new FiscalYearService;
        $quarters = [];

        foreach ([ReportingPeriod::year($asOf, $entity) - 1, ReportingPeriod::year($asOf, $entity)] as $fy) {
            ['start' => $start] = $service->bounds($entity, $fy);

            foreach ([0, 1, 2, 3] as $i) {
                $end = $start->copy()->addMonths($i * 3 + 2)->endOfMonth();
                if ($end->copy()->endOfDay()->lessThan($asOf)) {
                    $quarters[] = [
                        'end' => $end->startOfDay(),
                        'label' => sprintf('Q%d FY%d (ended %s)', $i + 1, $fy, $end->format('d M Y')),
                    ];
                }
            }
        }

        return $quarters;
    }

    /**
     * Post the clearing journal — the DividendService::postJournal
     * recipe with two lines: the main account takes one side alone
     * (pay: Dr GST Payable; refund: Cr GST Receivable) and the
     * remaining GST/bank legs take the other.
     *
     * @param  array{payable: ?Account, receivable: ?Account}  $accounts
     */
    protected function postSettlementJournal(
        Entity $entity,
        array $accounts,
        float $payable,
        float $receivable,
        float $net,
        string $direction,
        Carbon $settledAt,
        Carbon $asAt,
    ): JournalEntry {
        $bank = Account::where('entity_id', $entity->id)
            ->where('code', config('australian.bas.bank_account_code', 320))
            ->first();
        if (! $bank) {
            throw new \InvalidArgumentException('The operating bank account is not configured.');
        }

        [$main, $credited] = $direction === BasSettlement::DIRECTION_PAY
            ? [$accounts['payable'], false]
            : [$accounts['receivable'], true];

        // The opposite-side legs: clear the other GST account and move
        // the net amount to/from the bank. Zero legs drop out (e.g. an
        // exact offset posts no bank movement).
        $legs = [];
        if ($direction === BasSettlement::DIRECTION_PAY) {
            if ($receivable > 0 && $accounts['receivable']) {
                $legs[] = [$accounts['receivable'], $receivable];
            }
            if ($net > 0) {
                $legs[] = [$bank, $net];
            }
        } else {
            if ($payable > 0 && $accounts['payable']) {
                $legs[] = [$accounts['payable'], $payable];
            }
            $legs[] = [$bank, abs($net)];
        }

        IfrsPosting::ensureReportingPeriod($settledAt, $entity);

        $journal = new JournalEntry([
            'transaction_date' => IfrsPosting::transactionDate($settledAt, $entity),
            'account_id' => $main->id,
            'credited' => $credited,
            'entity_id' => $entity->id,
            // Bank can be a line item; without an explicit currency the
            // package defaults from the MAIN account only and the bank
            // line fails the single-currency check at addLineItem().
            'currency_id' => $entity->currency_id,
            'narration' => 'BAS settlement — GST to '.$asAt->format('d M Y'),
            'reference' => 'BAS-SETT-'.$asAt->format('Ymd'),
        ]);

        foreach ($legs as [$account, $amount]) {
            // Persisted before addLineItem(): unsaved items share a null
            // id and the package silently drops all but the first.
            $line = LineItem::create([
                'account_id' => $account->id,
                'amount' => $amount,
                'quantity' => 1,
                'entity_id' => $entity->id,
            ]);
            $journal->addLineItem($line);
        }

        $journal->post();

        return $journal;
    }

    /**
     * Refuse posting into a closed IFRS year or a locked app period —
     * the same guards DividendService/PrepaymentService apply.
     */
    protected function assertDatePostable($date, Entity $entity, string $label): void
    {
        $date = Carbon::parse($date);

        if ($this->locks->isDateLocked($date)) {
            throw new \InvalidArgumentException("The {$label} falls in a locked period ({$date->toDateString()}).");
        }

        if ($this->locks->isDateBlocked($date, $entity)) {
            throw new \InvalidArgumentException(
                $this->locks->dateBlockedMessage($date, $entity)
                ?? "The {$label} falls in a closed financial year ({$date->toDateString()})."
            );
        }
    }
}

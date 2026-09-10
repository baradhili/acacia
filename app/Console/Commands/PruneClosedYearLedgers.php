<?php

namespace App\Console\Commands;

use App\Models\EntitySetting;
use App\Services\FiscalYearService;
use App\Services\IfrsPosting;
use App\Services\OpeningBalances;
use Carbon\Carbon;
use IFRS\Models\Account;
use IFRS\Models\Balance;
use IFRS\Models\Entity;
use IFRS\Models\ReportingPeriod;
use IFRS\Models\Transaction;
use IFRS\Scopes\EntityScope;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Prune the double-entry trail of closed financial years older than
 * the retention window (entity_settings.retention_years, default 7 —
 * configurable on the Administration page and per run via --years).
 *
 * Before anything is deleted, an opening-balance snapshot is written
 * at the prune boundary (the end of the newest pruned year) unless
 * one already stands there — a year-end close writes the same-dated
 * set — so OpeningBalances::balanceAt() keeps returning the same
 * figures for every date after the boundary. Business documents
 * (invoices, bills, payments) are retained: they carry the GST/audit
 * references; this clears the heavyweight ledger rows.
 */
class PruneClosedYearLedgers extends Command
{
    public const DEFAULT_RETENTION_YEARS = 7;

    protected $signature = 'ledger:prune
                            {--dry-run : Report what would be pruned without deleting}
                            {--years= : Override the configured retention window for this run}';

    protected $description = 'Prune ledger transactions in closed years past the retention window';

    public function handle(FiscalYearService $fy): int
    {
        $entity = IfrsPosting::resolveEntity();
        if (! $entity) {
            $this->error('No IFRS entity configured.');

            return Command::FAILURE;
        }

        $years = (int) ($this->option('years') ?? EntitySetting::retentionYears($entity));
        $cutoff = now()->subYears($years);
        $boundaryYear = $this->prunableThrough($entity, $fy, $cutoff);

        if ($boundaryYear === null) {
            $this->info("No closed financial years ended more than {$years} years ago — nothing to prune.");

            return Command::SUCCESS;
        }

        $boundary = $fy->bounds($entity, $boundaryYear - 1)['end']->copy()->endOfDay();

        $staleIds = Transaction::withoutGlobalScope(EntityScope::class)
            ->where('entity_id', $entity->id)
            ->whereDate('transaction_date', '<=', $boundary->toDateString())
            ->pluck('id');

        if ($staleIds->isEmpty()) {
            $this->info("No ledger transactions on or before {$boundary->toDateString()} — nothing to prune.");

            return Command::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $this->info(sprintf(
            '%s %d ledger transaction(s) dated on or before %s (closed years ended ≤ %d, retention %d years).',
            $dryRun ? 'Would prune' : 'Pruning',
            $staleIds->count(),
            $boundary->toDateString(),
            $boundaryYear,
            $years,
        ));

        if ($dryRun) {
            return Command::SUCCESS;
        }

        $this->ensureBoundarySnapshot($entity, $fy, $boundaryYear, $boundary);

        DB::transaction(function () use ($entity, $staleIds, $boundaryYear) {
            DB::table('ifrs_ledgers')->whereIn('transaction_id', $staleIds)->delete();
            DB::table('ifrs_assignments')->whereIn('transaction_id', $staleIds)->delete();
            DB::table('ifrs_line_items')->whereIn('transaction_id', $staleIds)->delete();
            DB::table('ifrs_transactions')->whereIn('id', $staleIds)->delete();

            Log::info('Pruned closed-year ledger transactions', [
                'entity_id' => $entity->id,
                'through_year' => $boundaryYear,
                'transactions' => $staleIds->count(),
            ]);
        });

        $this->info('Done. Balances after the boundary are unchanged (opening snapshot in force).');

        return Command::SUCCESS;
    }

    /**
     * The end-year of the newest closed FY that ended before the
     * cut-off — everything on or before its year end is prunable.
     * Null when no closed year is old enough.
     */
    protected function prunableThrough(Entity $entity, FiscalYearService $fy, Carbon $cutoff): ?int
    {
        $clockYear = $fy->clockYear($entity);

        for ($endYear = $clockYear; $endYear > $clockYear - 30; $endYear--) {
            $period = ReportingPeriod::withoutGlobalScope(EntityScope::class)
                ->where('entity_id', $entity->id)
                ->where('calendar_year', $endYear)
                ->first();

            if ($period?->status !== ReportingPeriod::CLOSED) {
                continue;
            }

            $end = $fy->bounds($entity, $endYear - 1)['end'];
            if ($end->endOfDay()->lessThan($cutoff)) {
                return $endYear;
            }
        }

        return null;
    }

    /**
     * Write an opening-balance snapshot dated the boundary into the
     * following year's period — the same shape the year-end close
     * writes — unless a set already stands there (a close-generated
     * set is dated the same day and already covers balanceAt()).
     */
    protected function ensureBoundarySnapshot(Entity $entity, FiscalYearService $fy, int $boundaryYear, Carbon $boundary): void
    {
        $nextPeriod = $fy->reportingPeriod($entity, $boundaryYear + 1);

        $existing = Balance::withoutGlobalScope(EntityScope::class)
            ->where('entity_id', $entity->id)
            ->where('reporting_period_id', $nextPeriod->id)
            ->exists();

        if ($existing) {
            $this->line("An opening-balance set already stands after {$boundary->toDateString()} — reusing it.");

            return;
        }

        // Measure every closing balance BEFORE writing any row: the
        // first row would shift the snapshot position for the rest.
        $accounts = Account::withoutGlobalScope(EntityScope::class)
            ->where('entity_id', $entity->id)
            ->whereNotIn('account_type', FiscalYearService::PNL_ACCOUNT_TYPES)
            ->orderBy('code')
            ->get();

        $closing = [];
        foreach ($accounts as $account) {
            $balance = round(OpeningBalances::balanceAt($account, $entity, $boundary), 2);
            if (abs($balance) >= 0.01) {
                $closing[] = [$account, $balance];
            }
        }

        foreach ($closing as [$account, $balance]) {
            (new Balance([
                'entity_id' => $entity->id,
                'account_id' => $account->id,
                'reporting_period_id' => $nextPeriod->id,
                'currency_id' => $account->currency_id,
                'transaction_type' => Transaction::JN,
                'transaction_date' => $boundary->copy()->startOfDay(),
                'balance_type' => $balance > 0 ? Balance::DEBIT : Balance::CREDIT,
                'balance' => abs($balance),
                'reference' => "PRUNE-{$boundaryYear}-OB",
            ]))->save();
        }

        $this->line(sprintf('Opening snapshot written at %s (%d account(s)).', $boundary->toDateString(), count($closing)));
    }
}

<?php

namespace App\Console\Commands;

use App\Models\FiscalPeriod;
use App\Services\IfrsPosting;
use Illuminate\Console\Command;

/**
 * Lock-only period locking kept for backward compatibility. The full
 * year-end close (closing entries, reporting-period status, workflow)
 * lives in fiscal-year:close — this delegate only ever locks rows.
 */
class CloseFiscalYear extends Command
{
    protected $signature = 'period:close-fiscal-year
                            {year : The fiscal year to close (e.g., 2025)}
                            {--reason= : Reason for closing the period}
                            {--dry-run : Show what would be done without making changes}';

    protected $description = 'Lock all periods for a fiscal year (deprecated: use fiscal-year:close for the full year-end close)';

    public function handle(): int
    {
        $this->warn('Deprecated: this command only locks periods. Use "fiscal-year:close" for the full year-end close (closing entries to Retained Earnings, reporting-period CLOSED, locks).');

        $year = (int) $this->argument('year');
        $reason = $this->option('reason') ?? "Fiscal year {$year} closed for year-end";
        $dryRun = (bool) $this->option('dry-run');

        $entity = IfrsPosting::resolveEntity();
        $startMonth = $entity->year_start ?? 7;

        $periods = FiscalPeriod::where('year', $year)->get();

        if ($periods->isEmpty()) {
            $this->info("No periods found for year {$year}. Creating monthly periods...");

            if (!$dryRun) {
                $periods = collect(FiscalPeriod::createMonthlyPeriodsForYear($year, $startMonth));
            }
        }

        $this->info("Processing {$periods->count()} periods for fiscal year {$year}...");

        $locked = 0;
        $alreadyLocked = 0;

        foreach ($periods as $period) {
            if ($period->isLocked()) {
                $this->warn("  {$period->name} - Already locked");
                $alreadyLocked++;
            } else {
                if ($dryRun) {
                    $this->warn("  {$period->name} - Would lock");
                } else {
                    $period->lock($reason);
                    $this->info("  {$period->name} - Locked");
                }
                $locked++;
            }
        }

        $this->newLine();
        $this->info('Summary:');
        $this->info("  Locked: {$locked}");
        $this->info("  Already locked: {$alreadyLocked}");

        if ($dryRun) {
            $this->warn('  (Dry run - no changes made)');
        }

        return Command::SUCCESS;
    }
}

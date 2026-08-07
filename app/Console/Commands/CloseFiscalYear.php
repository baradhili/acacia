<?php

namespace App\Console\Commands;

use App\Models\FiscalPeriod;
use App\Services\PeriodLockService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class CloseFiscalYear extends Command
{
    protected $signature = 'period:close-fiscal-year
                            {year : The fiscal year to close (e.g., 2025)}
                            {--reason= : Reason for closing the period}
                            {--dry-run : Show what would be done without making changes}';

    protected $description = 'Lock all periods for a fiscal year';

    public function handle(): int
    {
        $year = (int) $this->argument('year');
        $reason = $this->option('reason') ?? "Fiscal year {$year} closed for year-end";
        $dryRun = $this->option('dry-run');

        $service = new PeriodLockService();

        // Get or create periods for the year
        $periods = FiscalPeriod::where('year', $year)->get();

        if ($periods->isEmpty()) {
            $this->info("No periods found for year {$year}. Creating monthly periods...");
            
            if (!$dryRun) {
                $periods = FiscalPeriod::createMonthlyPeriodsForYear($year);
                $periods = collect($periods);
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
        $this->info("Summary:");
        $this->info("  Locked: {$locked}");
        $this->info("  Already locked: {$alreadyLocked}");

        if ($dryRun) {
            $this->warn("  (Dry run - no changes made)");
        }

        return Command::SUCCESS;
    }
}

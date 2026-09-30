<?php

namespace App\Console\Commands;

use App\Services\FiscalYearService;
use App\Services\IfrsPosting;
use Illuminate\Console\Command;

/**
 * Undoes fiscal-year:close for one year. FiscalYearService::reopen()
 * mirrors each closing entry back out (ref FY-CLOSE-{year}-REV; both
 * sides stay in the ledger, net zero, excluded from report movement),
 * reopens the IFRS ReportingPeriod, unlocks the year's FiscalPeriods
 * and restores the FY+1 opening set the close superseded — leaving
 * the year editable and re-closable. Prompts to confirm. Unlike
 * period:unlock this also lifts the IFRS CLOSED status.
 */
class FiscalYearReopen extends Command
{
    protected $signature = 'fiscal-year:reopen
                            {year : Fiscal year to reopen}';

    protected $description = 'Reopen a closed financial year: reverse the closing entries, reopen the reporting period and unlock the year';

    public function handle(FiscalYearService $service): int
    {
        $entity = IfrsPosting::resolveEntity();
        if (! $entity) {
            $this->error('No IFRS entity found.');

            return Command::FAILURE;
        }

        $year = (int) $this->argument('year');

        if (! $this->confirm("Reopen FY {$year}? The closing entries will be reversed and the year becomes editable again.")) {
            return Command::SUCCESS;
        }

        try {
            $service->reopen($entity, $year);
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            $this->error($e->getMessage());

            return Command::FAILURE;
        }

        $this->info("FY {$year} reopened — closing entries reversed, reporting period OPEN, app periods unlocked.");

        return Command::SUCCESS;
    }
}

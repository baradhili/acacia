<?php

namespace App\Console\Commands;

use App\Services\FiscalYearService;
use App\Services\IfrsPosting;
use Illuminate\Console\Command;

class FiscalYearReopen extends Command
{
    protected $signature = 'fiscal-year:reopen
                            {year : Fiscal year to reopen}';

    protected $description = 'Reopen a closed financial year: reverse the closing entries, reopen the reporting period and unlock the year';

    public function handle(FiscalYearService $service): int
    {
        $entity = IfrsPosting::resolveEntity();
        if (!$entity) {
            $this->error('No IFRS entity found.');

            return Command::FAILURE;
        }

        $year = (int) $this->argument('year');

        if (!$this->confirm("Reopen FY {$year}? The closing entries will be reversed and the year becomes editable again.")) {
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

<?php

namespace App\Console\Commands;

use App\Services\FiscalYearService;
use App\Services\IfrsPosting;
use Illuminate\Console\Command;

class FiscalYearCloseCommand extends Command
{
    protected $signature = 'fiscal-year:close
                            {year? : Fiscal year to close (defaults to the most recently ended FY)}
                            {--force : Bypass the approval workflow and failed blocking checklist items}';

    protected $description = 'Execute the year-end close: post closing entries to Retained Earnings, mark the reporting period CLOSED and lock the year';

    public function handle(FiscalYearService $service): int
    {
        $entity = IfrsPosting::resolveEntity();
        if (!$entity) {
            $this->error('No IFRS entity found.');

            return Command::FAILURE;
        }

        $year = (int) ($this->argument('year') ?? $service->currentYear($entity) - 1);

        try {
            $record = $service->close($entity, $year, (bool) $this->option('force'));
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            $this->error($e->getMessage());

            return Command::FAILURE;
        }

        $totals = $record->trial_totals;
        $this->info("FY {$year} closed.");
        $this->line('FY net profit:            ' . number_format($totals['fy_net_profit'], 2));
        $this->line('Prior-years catch-up:     ' . number_format($totals['prior_years_catch_up'], 2));
        $this->line('Net to Retained Earnings: ' . number_format($totals['net_to_retained_earnings'], 2));
        $this->line('Closing transactions:     ' . ($record->closing_transaction_ids
            ? implode(', ', $record->closing_transaction_ids)
            : 'none (no P&L balances to close)'));

        if ($this->option('force')) {
            $this->warn('Closed with --force: the approval workflow and/or blocking checklist items were bypassed.');
        }

        $this->newLine();
        $this->line('To undo: php artisan fiscal-year:reopen ' . $year);

        return Command::SUCCESS;
    }
}

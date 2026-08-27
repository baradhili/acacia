<?php

namespace App\Console\Commands;

use App\Services\FiscalYearService;
use App\Services\IfrsPosting;
use Illuminate\Console\Command;

class FiscalYearTrial extends Command
{
    protected $signature = 'fiscal-year:trial
                            {year? : Fiscal year to trial close (defaults to the most recently ended FY)}';

    protected $description = 'Compute a trial year-end close (checklist + proposed closing entries) without touching the ledger';

    public function handle(FiscalYearService $service): int
    {
        $entity = IfrsPosting::resolveEntity();
        if (!$entity) {
            $this->error('No IFRS entity found.');

            return Command::FAILURE;
        }

        $year = (int) ($this->argument('year') ?? $service->currentYear($entity) - 1);

        try {
            $trial = $service->storeTrial($entity, $year);
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            $this->error($e->getMessage());

            return Command::FAILURE;
        }

        $this->info("Trial close — FY {$trial['year']} ({$trial['start']->format('d M Y')} → {$trial['end']->format('d M Y')})");
        $this->newLine();

        $this->info('Pre-close checklist:');
        foreach ($trial['checklist'] as $item) {
            $mark = $item['pass'] ? '<fg=green>✓</>' : ($item['blocking'] ? '<fg=red>✗</>' : '<fg=yellow>!</>');
            $this->line("  {$mark} {$item['label']} — {$item['detail']}");
        }
        $this->newLine();

        if (empty($trial['lines'])) {
            $this->warn('No P&L account balances to close.');
        } else {
            $this->info('Proposed closing entries (to ' . $trial['retained_earnings']['code'] . ' ' . $trial['retained_earnings']['name'] . '):');
            $this->table(
                ['Code', 'Account', 'Close', 'Amount', 'FY movement', 'Prior years'],
                array_map(fn ($line) => [
                    $line['code'],
                    $line['name'],
                    strtoupper($line['close_side']),
                    number_format($line['amount'], 2),
                    number_format($line['fy_movement'], 2),
                    number_format($line['prior_years'], 2),
                ], $trial['lines'])
            );
            $this->newLine();
        }

        $this->info('FY net profit:                ' . number_format($trial['fy_net_profit'], 2));
        $this->info('Prior-years catch-up:         ' . number_format($trial['prior_years_catch_up'], 2));
        $this->info('Net to Retained Earnings:     ' . number_format($trial['net_to_retained_earnings'], 2));
        $this->newLine();

        if (!$trial['checklist_passes']) {
            $this->warn('Blocking checklist items failed — resolve them before requesting approval.');
        }

        $record = $trial['record'];
        $this->line("Snapshot saved to workflow record #{$record->id} (status: {$record->status}).");
        $this->line('Next: submit FY ' . $year . ' for approval from the Financial Years page (or fiscal-year:close after approval).');

        return Command::SUCCESS;
    }
}

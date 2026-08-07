<?php

namespace App\Console\Commands;

use App\Models\FiscalPeriod;
use Illuminate\Console\Command;

class UnlockPeriod extends Command
{
    protected $signature = 'period:unlock
                            {id : The period ID to unlock}
                            {--force : Skip confirmation}';

    protected $description = 'Unlock a fiscal period';

    public function handle(): int
    {
        $periodId = (int) $this->argument('id');
        $force = $this->option('force');

        $period = FiscalPeriod::find($periodId);

        if (!$period) {
            $this->error("Period not found: {$periodId}");
            return Command::FAILURE;
        }

        if (!$period->isLocked()) {
            $this->warn("Period '{$period->name}' is not locked.");
            return Command::SUCCESS;
        }

        $this->info("Period: {$period->name}");
        $this->info("Locked by: {$period->lockedBy?->name ?? 'Unknown'}");
        $this->info("Locked at: {$period->locked_at}");
        $this->info("Reason: {$period->lock_reason}");

        if (!$force && !$this->confirm('Are you sure you want to unlock this period?')) {
            $this->warn('Operation cancelled.');
            return Command::FAILURE;
        }

        $period->unlock();

        $this->info("Period '{$period->name}' has been unlocked.");

        return Command::SUCCESS;
    }
}

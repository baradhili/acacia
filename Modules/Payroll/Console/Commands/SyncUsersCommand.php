<?php

namespace Modules\Payroll\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Modules\Payroll\Observers\UserObserver;

/**
 * Backfill: create a linked employee record for every staff-side user
 * missing one (users created before the UserObserver shipped). Safe to
 * re-run — users that already have a payee, and portal clients, are
 * skipped.
 */
class SyncUsersCommand extends Command
{
    protected $signature = 'payroll:sync-users';

    protected $description = 'Create a linked employee record for every staff-side user missing one';

    public function handle(): int
    {
        $created = 0;
        $skipped = 0;

        foreach (User::orderBy('id')->get() as $user) {
            if (UserObserver::ensureEmployeeFor($user)) {
                $created++;
                $this->line("  Created payee for {$user->name}");
            } else {
                $skipped++;
            }
        }

        $this->info("Synced: {$created} payee(s) created, {$skipped} user(s) already had one or are portal clients.");

        return Command::SUCCESS;
    }
}

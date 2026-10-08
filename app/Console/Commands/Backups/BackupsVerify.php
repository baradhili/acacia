<?php

namespace App\Console\Commands\Backups;

use App\Services\Backups\ArchiveInventory;
use App\Services\Backups\IntegrityService;
use Illuminate\Console\Command;

/**
 * The daily integrity sweep (Tao heads 6 + 7): re-hash every
 * inventoried archive against its recorded checksum (silent rot of
 * the backups themselves, missing copies) and take a fresh source
 * snapshot with a change report against the previous one. Anomalies
 * are visible on the Backups page; failures of the sweep itself are
 * logged by spatie's notifications and this command's exit code.
 */
class BackupsVerify extends Command
{
    protected $signature = 'backups:verify';

    protected $description = 'Verify archive checksums and snapshot source-data integrity';

    public function handle(ArchiveInventory $inventory, IntegrityService $integrity): int
    {
        $counts = $inventory->reconcile();
        $verification = $inventory->verify();

        $snapshot = $integrity->snapshot();

        $this->info(sprintf(
            'Archives: %d ok, %d corrupt, %d missing (%d newly inventoried).',
            $verification['ok'],
            $verification['corrupt'],
            $verification['missing'],
            count($counts['new']),
        ));

        // Not an exit-code failure — the Backups page carries the
        // persistent warning and the fix — but the sweep must say it
        // could not see those archives at all.
        if ($verification['unreachable'] > 0) {
            $this->warn(sprintf(
                '%d archive(s) sit on disks unreachable under PHP\'s open_basedir restriction and were not verified.',
                $verification['unreachable'],
            ));
        }

        $this->line('Source snapshot: '.$snapshot->summary);

        return ($verification['corrupt'] > 0 || $verification['missing'] > 0)
            ? Command::FAILURE
            : Command::SUCCESS;
    }
}

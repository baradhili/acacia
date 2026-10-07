<?php

namespace App\Services\Backups;

use App\Models\BackupArchive;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

/**
 * Shared zip plumbing for the restore legs: open a backup archive
 * (with the configured password when the archives are encrypted) and
 * pull out the textual database dump spatie stored under db-dumps/.
 */
class BackupZip
{
    /**
     * The db-dumps/*.sql member as a string.
     */
    public static function readDatabaseDump(string $zipPath): string
    {
        $zip = new ZipArchive;

        if (@$zip->open($zipPath) !== true) {
            throw new RuntimeException('Cannot open the backup archive (corrupt zip, or encrypted with a different password).');
        }

        // setPassword only works on an opened archive; reading an
        // encrypted member without it returns false, not an exception.
        if ($password = config('backup.backup.password')) {
            $zip->setPassword($password);
        }

        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);

                if (str_starts_with($name, 'db-dumps/') && str_ends_with($name, '.sql')) {
                    $sql = $zip->getFromIndex($i);

                    if ($sql === false || $sql === '') {
                        throw new RuntimeException('Cannot read the database dump from the archive — check BACKUP_ARCHIVE_PASSWORD.');
                    }

                    return $sql;
                }
            }

            throw new RuntimeException('No database dump found in the archive.');
        } finally {
            $zip->close();
        }
    }

    /**
     * A local filesystem path for the archive: the real path on local
     * disks, or a streamed temp copy for remote destinations.
     */
    public static function materialise(BackupArchive $archive): string
    {
        $disk = Storage::disk($archive->disk);

        if (method_exists($disk, 'path')) {
            return $disk->path($archive->path);
        }

        $temp = tempnam(sys_get_temp_dir(), 'erp-backup-zip-');
        $stream = $disk->readStream($archive->path);
        $target = fopen($temp, 'w+b');

        try {
            stream_copy_to_stream($stream, $target);
        } finally {
            fclose($stream);
            fclose($target);
        }

        return $temp;
    }
}

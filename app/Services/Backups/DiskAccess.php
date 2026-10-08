<?php

namespace App\Services\Backups;

/**
 * Which backup destination disks PHP can actually reach. A local disk
 * whose root sits outside the paths PHP's open_basedir restriction
 * allows cannot even be constructed — Flysystem's local adapter stats
 * the root the moment the disk is resolved — so every touch of such a
 * disk (inventory, backup run, restore test) used to die as an
 * ErrorException from is_dir()/mkdir() deep in the adapter. The
 * checks here are pure config/ini string comparisons run *before* any
 * disk is resolved, so callers can skip the disk and explain what to
 * fix instead of crashing.
 */
class DiskAccess
{
    /**
     * The configured destination disks whose local roots are outside
     * the paths open_basedir allows — the set the admin page warns
     * about and the runner refuses to start against.
     *
     * @return list<array{disk: string, root: string, allowed: string}>
     */
    public function unreachableDestinationDisks(): array
    {
        $unreachable = [];

        foreach ($this->destinationDisks() as $diskName) {
            if ($this->diskIsReachable($diskName)) {
                continue;
            }

            $unreachable[] = [
                'disk' => $diskName,
                'root' => (string) config("filesystems.disks.{$diskName}.root"),
                'allowed' => $this->openBasedir(),
            ];
        }

        return $unreachable;
    }

    /**
     * Whether resolving Storage::disk($diskName) can work at all.
     * Non-local drivers (the s3 offsite) never touch the PHP
     * filesystem, so open_basedir is not their concern.
     */
    public function diskIsReachable(string $diskName): bool
    {
        if (config("filesystems.disks.{$diskName}.driver") !== 'local') {
            return true;
        }

        return $this->isWithinBasedir((string) config("filesystems.disks.{$diskName}.root"));
    }

    /**
     * The restriction string as configured, for messages that explain
     * what a blocked root fell outside of.
     */
    public function allowedPaths(): string
    {
        return $this->openBasedir();
    }

    /**
     * Component-wise prefix match mirroring php's open_basedir
     * semantics: an allowed /var/www/erp admits /var/www/erp/storage
     * but not the sibling /var/www/erp-evil. The restriction the app
     * actually runs under is the default; the parameter exists so the
     * matching rules can be pinned by tests on unrestricted hosts.
     * Anything the check cannot decide (no restriction, a relative
     * entry or root the ini would resolve against the cwd) reads as
     * reachable — a missed warning, never a false one.
     */
    public function isWithinBasedir(string $path, ?string $restriction = null): bool
    {
        $restriction ??= $this->openBasedir();

        if ($restriction === '' || ! str_starts_with($path, DIRECTORY_SEPARATOR)) {
            return true;
        }

        $path = rtrim($path, DIRECTORY_SEPARATOR);

        foreach (explode(PATH_SEPARATOR, $restriction) as $base) {
            $base = rtrim(trim($base), DIRECTORY_SEPARATOR);

            if ($base === '') {
                return true; // a "/" entry allows everything
            }

            if (! str_starts_with($base, DIRECTORY_SEPARATOR)) {
                return true; // relative entry — resolved per-cwd, not string-checkable
            }

            if ($path === $base || str_starts_with($path, $base.DIRECTORY_SEPARATOR)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    protected function destinationDisks(): array
    {
        return array_values(array_filter(array_map(
            'trim',
            (array) config('backup.backup.destination.disks'),
        )));
    }

    protected function openBasedir(): string
    {
        return (string) ini_get('open_basedir');
    }
}

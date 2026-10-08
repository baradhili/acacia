<?php

namespace Tests\Unit\Backups;

use App\Services\Backups\DiskAccess;
use PHPUnit\Framework\TestCase;

/**
 * The open_basedir prefix rules DiskAccess guards the backup
 * destinations with. The check is a pure ini/config string
 * comparison, so it is pinned here against an explicit restriction —
 * hosts running the suite without open_basedir could not exercise it
 * through the ini. When the check cannot decide (no restriction, a
 * relative entry, a relative root) it must read as reachable: the
 * guards warn about blocked disks, they never false-alarm.
 */
class DiskAccessTest extends TestCase
{
    protected string $restriction;

    protected function setUp(): void
    {
        $this->restriction = implode(PATH_SEPARATOR, ['/var/www/erp', '/tmp']);
    }

    public function test_paths_under_an_allowed_directory_are_admitted(): void
    {
        $guard = new DiskAccess;

        $this->assertTrue($guard->isWithinBasedir('/var/www/erp', $this->restriction));
        $this->assertTrue($guard->isWithinBasedir('/var/www/erp/', $this->restriction));
        $this->assertTrue($guard->isWithinBasedir('/var/www/erp/storage/app/backups', $this->restriction));
        $this->assertTrue($guard->isWithinBasedir('/tmp/erp-backups', $this->restriction));
    }

    public function test_paths_outside_or_merely_sharing_the_prefix_string_are_rejected(): void
    {
        $guard = new DiskAccess;

        // Component boundary: /var/www/erp-evil shares the prefix
        // string but is a sibling, not a child.
        $this->assertFalse($guard->isWithinBasedir('/var/www/erp-evil/backups', $this->restriction));
        $this->assertFalse($guard->isWithinBasedir('/var/backups', $this->restriction));
        $this->assertFalse($guard->isWithinBasedir('/home/bret/backups', $this->restriction));
    }

    public function test_undecidable_restrictions_admit_everything(): void
    {
        $guard = new DiskAccess;

        $this->assertTrue($guard->isWithinBasedir('/home/bret/backups', ''));
        // A relative entry resolves against the cwd at runtime — not
        // string-checkable, so the whole restriction reads as open.
        $this->assertTrue($guard->isWithinBasedir('/home/bret/backups', '.'.PATH_SEPARATOR.sys_get_temp_dir()));
        // Relative roots flysystem resolves against the cwd — same
        // undecidable case.
        $this->assertTrue($guard->isWithinBasedir('storage/app/backups', $this->restriction));
    }
}

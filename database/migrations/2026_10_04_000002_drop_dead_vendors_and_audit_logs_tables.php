<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Drops two tables the 2026-10 dead-code pass removed from the
 * squashed schema: vendors (a mirror of suppliers no controller,
 * route, relation or test ever referenced) and audit_logs (the audit
 * pipeline writes the syslog channel; nothing ever persisted rows).
 * Existing installs still carry both from the squash. Each drops
 * only when empty — both were writerless, so a non-empty table means
 * someone used it manually, and deleting live rows is its owner's
 * decision, not this migration's.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['vendors', 'audit_logs'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->count() === 0) {
                Schema::dropIfExists($table);
            }
        }
    }

    public function down(): void
    {
        // The tables are gone from the schema of record; restoring
        // them would recreate dead structures. Roll forward only.
    }
};

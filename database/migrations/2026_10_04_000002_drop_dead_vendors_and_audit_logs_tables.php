<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Drops two tables the 2026-10 dead-code pass removed from the
 * squashed schema: vendors (a mirror of suppliers no controller,
 * route, relation or test ever referenced) and audit_logs (the audit
 * pipeline writes the syslog channel; nothing ever persisted rows).
 * Existing installs still carry both from the squash — this clears
 * them so every database matches the squashed schema again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('vendors');
        Schema::dropIfExists('audit_logs');
    }

    public function down(): void
    {
        // The tables are gone from the schema of record; restoring
        // them would recreate dead structures. Roll forward only.
    }
};

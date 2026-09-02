<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Time entries gain a direct client target so they are no longer
 * project-only: an entry can belong to a project, to a client
 * directly (ad-hoc client work), or to neither (internal time).
 * Client resolution previously walked project->client; the column
 * is denormalised (forced from the project when one is set) so
 * reports group on a single column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('time_entries', function (Blueprint $table) {
            $table->foreignId('client_id')
                ->nullable()
                ->after('purchase_order_id')
                ->constrained()
                ->nullOnDelete();
        });

        // Correlated subquery (not UPDATE...JOIN) so it runs on both
        // MySQL and SQLite.
        DB::table('time_entries')
            ->whereNotNull('project_id')
            ->whereNull('client_id')
            ->update([
                'client_id' => DB::raw('(SELECT client_id FROM projects WHERE projects.id = time_entries.project_id)'),
            ]);
    }

    public function down(): void
    {
        Schema::table('time_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('client_id');
        });
    }
};

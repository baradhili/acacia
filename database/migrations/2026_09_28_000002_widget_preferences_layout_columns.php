<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Align the layout columns with the semantics WidgetLayout reads:
     * a NULL position_y means "never positioned by a full save" (the
     * widget renders in registry order), while the shipped default of
     * 0 would jump any row a single-widget patch created to the front
     * of the grid. The width override's 0 means "keep the registry's
     * shipped span", but the column shipped with a default of 1 —
     * which read as a quarter-width override instead of no override.
     */
    public function up(): void
    {
        // Rows carrying width 1 were never user choices — the old
        // full-save endpoint rejected every payload before this
        // feature landed, so its schema default wrote all existing
        // rows. They mean "shipped span": zero them before the
        // default change makes the reading permanent.
        DB::table('widget_preferences')->where('width', 1)->update(['width' => 0]);

        Schema::table('widget_preferences', function (Blueprint $table) {
            $table->integer('position_y')->nullable()->change();
            $table->integer('width')->default(0)->change();
        });
    }

    public function down(): void
    {
        // Rows this feature left unplaced (NULL) must be back-filled
        // before the column loses NULL — sqlite rebuilds the table
        // and refuses NULLs in a NOT NULL column, so the rollback
        // would die on the first such row. Width data is left as-is:
        // the pre-feature code never read it, so 0s are harmless
        // under the restored schema.
        DB::table('widget_preferences')->whereNull('position_y')->update(['position_y' => 0]);

        Schema::table('widget_preferences', function (Blueprint $table) {
            $table->integer('position_y')->default(0)->change();
            $table->integer('width')->default(1)->change();
        });
    }
};

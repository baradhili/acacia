<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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
        Schema::table('widget_preferences', function (Blueprint $table) {
            $table->integer('position_y')->nullable()->change();
            $table->integer('width')->default(0)->change();
        });
    }

    public function down(): void
    {
        Schema::table('widget_preferences', function (Blueprint $table) {
            $table->integer('position_y')->default(0)->change();
            $table->integer('width')->default(1)->change();
        });
    }
};

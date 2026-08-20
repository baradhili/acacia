<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Time entries are date-based: a date plus a manually entered number of
 * hours is the default, with optional start/end times for that date and
 * zero or more breaks. Hours are computed from (end − start − breaks)
 * when times are given; otherwise the manual figure stands.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('time_entries', function (Blueprint $table) {
            $table->date('entry_date')->nullable()->after('purchase_order_id');
            $table->datetime('start_time')->nullable()->change();
        });

        // Legacy rows carried the date inside start_time; new manual-only
        // rows have no times at all, so fall back to created_at.
        DB::table('time_entries')
            ->whereNull('entry_date')
            ->update(['entry_date' => DB::raw('DATE(COALESCE(start_time, created_at))')]);

        Schema::create('time_entry_breaks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('time_entry_id')->constrained('time_entries')->cascadeOnDelete();
            $table->time('start_time');
            $table->time('end_time');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('time_entry_breaks');

        Schema::table('time_entries', function (Blueprint $table) {
            $table->dropColumn('entry_date');
            $table->datetime('start_time')->nullable(false)->change();
        });
    }
};

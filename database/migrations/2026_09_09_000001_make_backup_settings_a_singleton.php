<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lock backup_settings down to one row: BackupSetting::current() used
 * to hand unsaved in-memory models to concurrent first callers, each
 * of which recordSuccess() would then persist — leaving first() to
 * pick an arbitrary winner afterwards. The fixed singleton_key with a
 * unique index makes fetch-or-create atomic: the database rejects the
 * loser, which re-reads the winner's row.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Collapse any duplicates the old race could have created,
        // keeping the most recently updated row, before the index.
        $keep = DB::table('backup_settings')->orderByDesc('updated_at')->value('id');
        if ($keep !== null) {
            DB::table('backup_settings')->where('id', '!=', $keep)->delete();
        }

        Schema::table('backup_settings', function (Blueprint $table) {
            $table->string('singleton_key')->default('default');
            $table->unique('singleton_key');
        });
    }

    public function down(): void
    {
        Schema::table('backup_settings', function (Blueprint $table) {
            $table->dropUnique(['singleton_key']);
            $table->dropColumn('singleton_key');
        });
    }
};

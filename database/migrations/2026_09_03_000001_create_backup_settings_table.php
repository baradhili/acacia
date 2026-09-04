<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Instance-wide backup schedule: how often `backup:create` runs and how
 * many archives of each type (db dump, files archive) are kept. A single
 * row, created lazily with code defaults (BackupSetting::current()) the
 * first time an admin changes the schedule.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_settings', function (Blueprint $table) {
            $table->id();
            $table->string('frequency')->default('daily');
            $table->unsignedSmallInteger('retention_count')->default(30);
            $table->timestamp('last_backup_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_settings');
    }
};

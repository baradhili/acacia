<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The tables behind the backup feature's Tao-of-Backup additions:
     * an archive inventory with checksums (security + the
     * missing-archive detection), integrity snapshots of the source
     * data (manifests + change reports), and restore-test results.
     * Retention is no longer a count in backup_settings — it is the
     * GFS policy in config/backup.php — so retention_count goes.
     */
    public function up(): void
    {
        Schema::create('backup_archives', function (Blueprint $table) {
            $table->id();
            $table->string('disk');
            $table->string('name');
            $table->string('path');
            $table->unsignedBigInteger('bytes')->default(0);
            $table->string('sha256', 64)->nullable();
            $table->enum('status', ['ok', 'corrupt', 'missing'])->default('ok');
            $table->timestamp('backed_up_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->unique(['disk', 'name']);
            $table->index('status');
        });

        Schema::create('backup_integrity_snapshots', function (Blueprint $table) {
            $table->id();
            $table->json('manifest');
            $table->json('diff')->nullable();
            $table->string('summary')->nullable();
            $table->foreignId('backup_archive_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('backup_restore_tests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('backup_archive_id')->nullable()->constrained()->nullOnDelete();
            $table->string('disk');
            $table->string('file');
            $table->enum('status', ['passed', 'failed']);
            $table->json('checks')->nullable();
            $table->text('message')->nullable();
            $table->unsignedInteger('duration_ms')->default(0);
            $table->timestamps();
        });

        if (Schema::hasColumn('backup_settings', 'retention_count')) {
            Schema::table('backup_settings', function (Blueprint $table) {
                $table->dropColumn('retention_count');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_restore_tests');
        Schema::dropIfExists('backup_integrity_snapshots');
        Schema::dropIfExists('backup_archives');

        Schema::table('backup_settings', function (Blueprint $table) {
            $table->unsignedInteger('retention_count')->default(30);
        });
    }
};

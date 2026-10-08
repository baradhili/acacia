<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The GUI-configured offsite backup leg (Tao head 3 —
     * separation). One singleton row holds the chosen remote driver
     * (s3 or sftp), its credential bundle — encrypted at rest with
     * APP_KEY, since these are live secrets the DB must not hold in
     * the clear — and the outcome of the last connection test, which
     * gates enabling: an offsite disk that cannot be reached would
     * fail every scheduled backup run.
     */
    public function up(): void
    {
        Schema::create('backup_offsite_disks', function (Blueprint $table) {
            $table->id();
            // Defaulted like backup_settings.singleton_key: the row
            // is created via firstOrCreate with a constant key that
            // mass assignment filters out, so the column itself
            // carries the value.
            $table->string('singleton_key')->default('default')->unique();
            $table->boolean('enabled')->default(false);
            $table->string('driver', 16)->default('s3');
            // sftp root path / s3 key prefix, driver-dependent.
            $table->string('root')->nullable();
            // Encrypted JSON credential bundle (encrypted:array cast).
            $table->text('config')->nullable();
            $table->timestamp('last_test_at')->nullable();
            $table->string('last_test_status', 16)->nullable();
            $table->text('last_test_message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_offsite_disks');
    }
};

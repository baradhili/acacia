<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When the last overdue-payment reminder was emailed for the invoice —
 * the notifications:overdue-reminders command's re-send throttle. The
 * reminders go out by mail only (no database notification rows are
 * written), so the invoice row itself is the one place the send date
 * can live.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->timestamp('last_reminder_sent_at')->nullable()->after('sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('last_reminder_sent_at');
        });
    }
};

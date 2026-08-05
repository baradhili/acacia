<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('credit_note_id')->nullable()->after('ifrs_receipt_id')->constrained('credit_notes')->onDelete('set null');
            $table->index('credit_note_id');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['credit_note_id']);
            $table->dropColumn('credit_note_id');
        });
    }
};

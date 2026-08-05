<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            // Recurring invoice support
            $table->boolean('is_recurring')->default(false);
            $table->string('recurring_frequency')->nullable(); // daily, weekly, monthly, yearly
            $table->date('next_recurring_date')->nullable();
            $table->foreignId('parent_invoice_id')->nullable()->constrained('invoices')->onDelete('set null');
            
            // Email tracking
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('viewed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropForeign(['parent_invoice_id']);
            $table->dropColumn(['is_recurring', 'recurring_frequency', 'next_recurring_date', 'parent_invoice_id', 'sent_at', 'viewed_at']);
        });
    }
};

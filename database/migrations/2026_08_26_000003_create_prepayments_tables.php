<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prepaid service contracts (subscriptions, licences, prepaid domain
 * renewals, finite-life intangibles): a payment against a prepaid bill
 * line funds a Prepayment row which the prepayments:amortise runner
 * expenses monthly (Dr expense / Cr asset) at each month-end of the
 * service period. Rows are per payment-and-item so partial payments
 * create partial schedules that amortise independently.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prepayments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('entity_id');
            $table->unsignedBigInteger('bill_payment_id');
            $table->unsignedBigInteger('bill_item_id');
            $table->string('description', 255);
            $table->unsignedBigInteger('asset_account_id');     // Cr side (460 Prepaid Subscriptions / 170 intangible)
            $table->unsignedBigInteger('expense_account_id');   // Dr side (7500 / 7510 / 7910)
            $table->date('service_start');
            $table->date('service_end');
            $table->unsignedInteger('periods');
            $table->decimal('total_amount', 14, 2);             // ex-GST amount funded
            $table->decimal('monthly_amount', 14, 2);
            $table->date('next_period_date');                    // next unposted month-end cursor
            $table->string('status', 20)->default('active');    // active|completed|void
            $table->timestamps();

            $table->foreign('bill_payment_id')->references('id')->on('bill_payments')->cascadeOnDelete();
            $table->foreign('bill_item_id')->references('id')->on('bill_items')->cascadeOnDelete();
            $table->index(['entity_id', 'status']);
        });

        Schema::create('prepayment_amortisations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('prepayment_id');
            $table->date('period_date');                        // month-end the entry is dated
            $table->decimal('amount', 14, 2);
            $table->unsignedBigInteger('ifrs_transaction_id')->nullable();
            $table->unsignedBigInteger('reversal_transaction_id')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->timestamps();

            $table->foreign('prepayment_id')->references('id')->on('prepayments')->cascadeOnDelete();
            // The idempotency anchor: one entry per prepayment per month.
            $table->unique(['prepayment_id', 'period_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prepayment_amortisations');
        Schema::dropIfExists('prepayments');
    }
};

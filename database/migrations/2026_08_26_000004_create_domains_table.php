<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Domain name registry: initial purchases capitalise to the intangible
 * account (170) via a normal capital bill line; this registry tracks
 * each domain's indefinite/finite life, cost and renewal expiry.
 * Indefinite-life domains are not amortised; finite-life ones get a
 * Prepayment schedule (asset 170 → expense 7910) from the show page.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('domains', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('entity_id');
            $table->string('name', 255);
            $table->string('registrar', 100)->nullable();
            $table->date('purchased_at')->nullable();
            $table->date('expiry_date')->nullable();      // next renewal due
            $table->decimal('cost', 14, 2)->default(0);   // initial capitalised cost (ex GST)
            $table->unsignedBigInteger('account_id')->nullable(); // intangible account (170)
            $table->boolean('indefinite_life')->default(true);
            $table->unsignedInteger('useful_life_months')->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 20)->default('active'); // active|retired
            $table->timestamps();

            $table->unique(['entity_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domains');
    }
};

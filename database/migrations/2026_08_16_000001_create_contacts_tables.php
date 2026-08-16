<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Squashed final schema (2026-08-16): clients / suppliers / vendors with
     * billing + shipping addresses, custom fields, and (clients/suppliers)
     * logo + soft deletes — all previously added by separate migrations.
     */
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('address', 500)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('state', 100)->nullable();
            $table->string('postcode', 20)->nullable();
            $table->string('country', 100)->nullable();
            $table->string('billing_address', 500)->nullable();
            $table->string('billing_city', 100)->nullable();
            $table->string('billing_state', 100)->nullable();
            $table->string('billing_postcode', 20)->nullable();
            $table->string('billing_country', 100)->nullable();
            $table->string('shipping_address', 500)->nullable();
            $table->string('shipping_city', 100)->nullable();
            $table->string('shipping_state', 100)->nullable();
            $table->string('shipping_postcode', 20)->nullable();
            $table->string('shipping_country', 100)->nullable();
            $table->boolean('same_as_billing')->default(true);
            $table->string('abn', 20)->nullable();
            $table->text('notes')->nullable();
            $table->string('logo')->nullable();
            $table->json('custom_fields')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('address', 500)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('state', 100)->nullable();
            $table->string('postcode', 20)->nullable();
            $table->string('country', 100)->nullable();
            $table->string('billing_address', 500)->nullable();
            $table->string('billing_city', 100)->nullable();
            $table->string('billing_state', 100)->nullable();
            $table->string('billing_postcode', 20)->nullable();
            $table->string('billing_country', 100)->nullable();
            $table->string('shipping_address', 500)->nullable();
            $table->string('shipping_city', 100)->nullable();
            $table->string('shipping_state', 100)->nullable();
            $table->string('shipping_postcode', 20)->nullable();
            $table->string('shipping_country', 100)->nullable();
            $table->boolean('same_as_billing')->default(true);
            $table->string('abn', 20)->nullable();
            $table->text('notes')->nullable();
            $table->string('logo')->nullable();
            $table->json('custom_fields')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('vendors', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('address', 500)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('state', 100)->nullable();
            $table->string('postcode', 20)->nullable();
            $table->string('country', 100)->nullable();
            $table->string('billing_address', 500)->nullable();
            $table->string('billing_city', 100)->nullable();
            $table->string('billing_state', 100)->nullable();
            $table->string('billing_postcode', 20)->nullable();
            $table->string('billing_country', 100)->nullable();
            $table->string('shipping_address', 500)->nullable();
            $table->string('shipping_city', 100)->nullable();
            $table->string('shipping_state', 100)->nullable();
            $table->string('shipping_postcode', 20)->nullable();
            $table->string('shipping_country', 100)->nullable();
            $table->boolean('same_as_billing')->default(true);
            $table->string('abn', 20)->nullable();
            $table->string('category', 100)->nullable();
            $table->text('notes')->nullable();
            $table->json('custom_fields')->nullable();
            $table->timestamps();
        });

        // Runs here (not in 0001) so deleted_at lands after the IFRS
        // package's entity_id/destroyed_at columns on users.
        Schema::table('users', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });

        Schema::dropIfExists('vendors');
        Schema::dropIfExists('suppliers');
        Schema::dropIfExists('clients');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add billing address fields to clients
        Schema::table('clients', function (Blueprint $table) {
            $table->string('billing_address', 500)->nullable()->after('country');
            $table->string('billing_city', 100)->nullable()->after('billing_address');
            $table->string('billing_state', 100)->nullable()->after('billing_city');
            $table->string('billing_postcode', 20)->nullable()->after('billing_state');
            $table->string('billing_country', 100)->nullable()->after('billing_postcode');
            
            // Add shipping address fields
            $table->string('shipping_address', 500)->nullable()->after('billing_country');
            $table->string('shipping_city', 100)->nullable()->after('shipping_address');
            $table->string('shipping_state', 100)->nullable()->after('shipping_city');
            $table->string('shipping_postcode', 20)->nullable()->after('shipping_state');
            $table->string('shipping_country', 100)->nullable()->after('shipping_postcode');
            
            // Add same_as_billing flag
            $table->boolean('same_as_billing')->default(true)->after('shipping_country');
        });

        // Add billing address fields to suppliers
        Schema::table('suppliers', function (Blueprint $table) {
            $table->string('billing_address', 500)->nullable()->after('country');
            $table->string('billing_city', 100)->nullable()->after('billing_address');
            $table->string('billing_state', 100)->nullable()->after('billing_city');
            $table->string('billing_postcode', 20)->nullable()->after('billing_state');
            $table->string('billing_country', 100)->nullable()->after('billing_postcode');
            
            $table->string('shipping_address', 500)->nullable()->after('billing_country');
            $table->string('shipping_city', 100)->nullable()->after('shipping_address');
            $table->string('shipping_state', 100)->nullable()->after('shipping_city');
            $table->string('shipping_postcode', 20)->nullable()->after('shipping_state');
            $table->string('shipping_country', 100)->nullable()->after('shipping_postcode');
            
            $table->boolean('same_as_billing')->default(true)->after('shipping_country');
        });

        // Add billing address fields to vendors
        Schema::table('vendors', function (Blueprint $table) {
            $table->string('billing_address', 500)->nullable()->after('country');
            $table->string('billing_city', 100)->nullable()->after('billing_address');
            $table->string('billing_state', 100)->nullable()->after('billing_city');
            $table->string('billing_postcode', 20)->nullable()->after('billing_state');
            $table->string('billing_country', 100)->nullable()->after('billing_postcode');
            
            $table->string('shipping_address', 500)->nullable()->after('billing_country');
            $table->string('shipping_city', 100)->nullable()->after('shipping_address');
            $table->string('shipping_state', 100)->nullable()->after('shipping_city');
            $table->string('shipping_postcode', 20)->nullable()->after('shipping_state');
            $table->string('shipping_country', 100)->nullable()->after('shipping_postcode');
            
            $table->boolean('same_as_billing')->default(true)->after('shipping_country');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn([
                'billing_address', 'billing_city', 'billing_state', 'billing_postcode', 'billing_country',
                'shipping_address', 'shipping_city', 'shipping_state', 'shipping_postcode', 'shipping_country',
                'same_as_billing',
            ]);
        });

        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropColumn([
                'billing_address', 'billing_city', 'billing_state', 'billing_postcode', 'billing_country',
                'shipping_address', 'shipping_city', 'shipping_state', 'shipping_postcode', 'shipping_country',
                'same_as_billing',
            ]);
        });

        Schema::table('vendors', function (Blueprint $table) {
            $table->dropColumn([
                'billing_address', 'billing_city', 'billing_state', 'billing_postcode', 'billing_country',
                'shipping_address', 'shipping_city', 'shipping_state', 'shipping_postcode', 'shipping_country',
                'same_as_billing',
            ]);
        });
    }
};

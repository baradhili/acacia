<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->decimal('salary', 12, 2)->nullable()->after('password');
            $table->decimal('charge_out_rate', 10, 2)->nullable()->after('salary');
            $table->string('position', 100)->nullable()->after('charge_out_rate');
            $table->string('phone', 50)->nullable()->after('position');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['salary', 'charge_out_rate', 'position', 'phone']);
        });
    }
};

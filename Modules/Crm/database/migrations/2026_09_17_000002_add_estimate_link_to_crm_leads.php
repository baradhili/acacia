<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The estimate prepared from a lead (the proposal-stage shortcut):
 * one optional link per lead, kept on the module's own table — the
 * core estimates schema stays untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_leads', function (Blueprint $table) {
            $table->foreignId('estimate_id')->nullable()->after('client_id')
                ->constrained('estimates')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('crm_leads', function (Blueprint $table) {
            $table->dropConstrainedForeignId('estimate_id');
        });
    }
};

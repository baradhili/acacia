<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PSI assessment state (the .zcode wages_and_psi spec, module C):
 * whether the entity is locked into "PSI mode" (the PSB results test
 * failed — deductions restricted, net PSI attributed to the
 * individual) and the stored results-test answers with their
 * assessment date.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('entity_settings', function (Blueprint $table) {
            $table->boolean('psi_mode')->default(false);
            $table->json('psb_results')->nullable();
            $table->timestamp('psi_assessed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('entity_settings', function (Blueprint $table) {
            $table->dropColumn(['psi_mode', 'psb_results', 'psi_assessed_at']);
        });
    }
};

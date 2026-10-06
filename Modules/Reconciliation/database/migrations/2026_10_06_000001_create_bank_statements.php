<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per imported statement that carries balances — the MT940
     * and camt.053 downloads (the Wise CSV layouts carry none). The
     * closing balance anchors the bank-vs-books cash check's actual
     * side exactly, instead of the running sum of imported lines the
     * CSV-only feed forces.
     */
    public function up(): void
    {
        Schema::create('bank_statements', function (Blueprint $table) {
            $table->id();
            $table->string('source'); // wise, ...
            $table->string('format'); // MT940, CAMT.053
            $table->string('statement_id')->nullable(); // :20: / Stmt Id
            $table->string('external_account')->nullable(); // :25: / Acct Id
            $table->string('currency', 3)->nullable();
            $table->date('opening_date')->nullable();
            $table->date('closing_date')->nullable();
            $table->decimal('opening_balance', 15, 2)->nullable(); // signed: credit +
            $table->decimal('closing_balance', 15, 2)->nullable();
            $table->timestamps();
            // Re-importing the same statement updates its balances
            // rather than duplicating the anchor.
            $table->unique(['source', 'statement_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_statements');
    }
};

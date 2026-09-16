<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * W1 (total salary/wages) and W2 (amounts withheld) join the frozen
 * BAS figures: the PAYG withholding labels share the ATO form with the
 * GST ones, so a quarter frozen at lodgement must snapshot them too —
 * pre-existing frozen rows predate the labels and read as zero.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bas_statements', function (Blueprint $table) {
            $table->decimal('w1', 14, 2)->default(0)->after('g11');
            $table->decimal('w2', 14, 2)->default(0)->after('w1');
        });
    }

    public function down(): void
    {
        Schema::table('bas_statements', function (Blueprint $table) {
            $table->dropColumn(['w1', 'w2']);
        });
    }
};

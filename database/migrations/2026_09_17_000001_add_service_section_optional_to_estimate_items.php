<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LaraEstimate concepts, adapted to the estimate flow: lines can
 * reference a catalogue Service (pre-filling description and the
 * standard rate), carry a section label so related lines group under
 * a heading, and be flagged optional — optional lines are quoted as
 * extras the client can take or leave, excluded from the committed
 * total and only invoiced when the conversion asks for them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('estimate_items', function (Blueprint $table) {
            $table->foreignId('service_id')->nullable()->constrained('services')->nullOnDelete();
            $table->string('section', 100)->nullable()->after('estimate_id');
            $table->boolean('is_optional')->default(false)->after('sort_order');
        });
    }

    public function down(): void
    {
        Schema::table('estimate_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('service_id');
            $table->dropColumn(['section', 'is_optional']);
        });
    }
};

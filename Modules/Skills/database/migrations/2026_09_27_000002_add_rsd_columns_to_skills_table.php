<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rich Skills Descriptor imports: source_id is the RSD's own id
     * (an IRI) and makes re-importing update instead of duplicate;
     * rsd keeps the full descriptor verbatim for reference. Unique
     * on source_id still allows any number of hand-entered skills
     * (NULL) alongside.
     */
    public function up(): void
    {
        Schema::table('skills', function (Blueprint $table) {
            $table->string('source_id')->nullable()->unique();
            $table->json('rsd')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('skills', function (Blueprint $table) {
            $table->dropUnique(['source_id']);
            $table->dropColumn(['source_id', 'rsd']);
        });
    }
};

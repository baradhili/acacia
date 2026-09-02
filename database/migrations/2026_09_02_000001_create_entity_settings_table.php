<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Administrator settings for the reporting entity.
 *
 * Kept in an app-owned table rather than columns on the vendor's
 * ifrs_entities (package migrations would clash on updates), mirroring
 * the company_profiles convention. open_year pins the "currently open"
 * financial year for backfilling: when null the working year simply
 * follows the calendar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entity_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('entity_id')->unique();
            $table->unsignedInteger('open_year')->nullable();
            $table->timestamps();

            $table->foreign('entity_id')->references('id')->on('ifrs_entities')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entity_settings');
    }
};

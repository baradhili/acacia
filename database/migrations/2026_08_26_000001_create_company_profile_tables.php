<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Company identity and registry data for the reporting entity.
 *
 * Kept in app-owned tables rather than columns on the vendor's
 * ifrs_entities (package migrations would clash on updates). The
 * profile backs statutory outputs — the ATO Company Tax Return's
 * identification section (ABN/TFN), registered address, directors and
 * the shareholder registry (Phase 1 of the franking/dividend spec).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_profiles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('entity_id')->unique();
            $table->string('abn', 11)->nullable();
            $table->string('tfn', 9)->nullable();
            $table->string('acn', 9)->nullable();
            $table->string('address_line1', 100)->nullable();
            $table->string('address_line2', 100)->nullable();
            $table->string('suburb', 60)->nullable();
            $table->string('state', 3)->nullable();
            $table->string('postcode', 4)->nullable();
            $table->string('country', 2)->default('AU');
            $table->string('email', 100)->nullable();
            $table->string('phone', 20)->nullable();
            $table->timestamps();

            $table->foreign('entity_id')->references('id')->on('ifrs_entities')->cascadeOnDelete();
        });

        Schema::create('company_directors', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_profile_id');
            $table->string('name', 100);
            $table->date('appointment_date')->nullable();
            $table->date('resignation_date')->nullable();
            $table->string('email', 100)->nullable();
            $table->string('phone', 20)->nullable();
            $table->timestamps();

            $table->foreign('company_profile_id')->references('id')->on('company_profiles')->cascadeOnDelete();
        });

        Schema::create('company_shareholders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_profile_id');
            $table->string('name', 100);
            $table->string('abn', 11)->nullable();
            $table->string('tfn', 9)->nullable();
            $table->string('address_line1', 100)->nullable();
            $table->string('suburb', 60)->nullable();
            $table->string('state', 3)->nullable();
            $table->string('postcode', 4)->nullable();
            $table->string('country', 2)->default('AU');
            $table->string('email', 100)->nullable();
            $table->string('phone', 20)->nullable();
            $table->boolean('resident_for_tax')->default(true);
            $table->string('share_class', 10)->default('ORD');
            $table->unsignedInteger('shares_held')->default(0);
            $table->char('status', 1)->default('A'); // A=Active, I=Inactive
            $table->timestamps();

            $table->foreign('company_profile_id')->references('id')->on('company_profiles')->cascadeOnDelete();
            $table->index(['company_profile_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_shareholders');
        Schema::dropIfExists('company_directors');
        Schema::dropIfExists('company_profiles');
    }
};

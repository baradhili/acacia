<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The skill register ported from resource_mgr: a skills library
     * plus a proficiency-carrying link to payroll payees and a plain
     * required-skill link to core services (upstream stored the
     * service side as loose JSON names — here both sides are real
     * foreign keys so the counts and coverage stay consistent).
     */
    public function up(): void
    {
        Schema::create('skills', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->text('description')->nullable();
            $table->string('category')->nullable();
            $table->timestamps();
        });

        Schema::create('employee_skill', function (Blueprint $table) {
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('skill_id')->constrained('skills')->cascadeOnDelete();
            $table->string('proficiency')->default('beginner');
            $table->timestamps();

            $table->primary(['employee_id', 'skill_id']);
        });

        Schema::create('service_skill', function (Blueprint $table) {
            $table->foreignId('service_id')->constrained('services')->cascadeOnDelete();
            $table->foreignId('skill_id')->constrained('skills')->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['service_id', 'skill_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_skill');
        Schema::dropIfExists('employee_skill');
        Schema::dropIfExists('skills');
    }
};

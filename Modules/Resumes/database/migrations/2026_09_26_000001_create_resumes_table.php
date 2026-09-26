<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resumes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('entity_id')->constrained('ifrs_entities')->cascadeOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('original_filename');
            $table->json('parsed_data');
            $table->string('json_resume_version', 100)->nullable();
            $table->timestamp('uploaded_at')->useCurrent();
            $table->timestamps();
            $table->index(['entity_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resumes');
    }
};

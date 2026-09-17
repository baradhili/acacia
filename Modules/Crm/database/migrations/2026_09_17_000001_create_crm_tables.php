<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CRM: leads through a sales funnel (new → contacted → qualified →
 * proposal → won/lost), the activities logged against them, and the
 * monthly sales targets the funnel is measured against. Won leads
 * convert to a Client — that is the seam into the rest of the ERP.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_leads', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // contact or account name
            $table->string('company')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('source')->nullable(); // website | referral | cold_call | event | existing_client | other
            $table->string('status')->default('new'); // new | contacted | qualified | proposal | won | lost
            $table->text('notes')->nullable(); // the plan: what we're selling and how
            $table->decimal('estimated_value', 14, 2)->default(0);
            $table->decimal('probability', 5, 2)->default(0); // win probability %
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('next_follow_up')->nullable();
            $table->string('loss_reason')->nullable();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete(); // set on conversion
            $table->timestamp('converted_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('next_follow_up');
        });

        Schema::create('crm_lead_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained('crm_leads')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 20)->default('note'); // note | call | email | meeting | task
            $table->string('summary', 500);
            $table->text('details')->nullable();
            $table->datetime('happened_at')->nullable();
            $table->timestamps();

            $table->index(['lead_id', 'happened_at']);
        });

        Schema::create('crm_targets', function (Blueprint $table) {
            $table->id();
            $table->date('month'); // first of the month
            $table->decimal('amount', 14, 2);
            $table->timestamps();

            $table->unique('month');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_targets');
        Schema::dropIfExists('crm_lead_activities');
        Schema::dropIfExists('crm_leads');
    }
};

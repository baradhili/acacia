<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shares, franking and dividends module (Phase 2 of the franking/dividend
 * spec in .zcode/plans/tax_spec.md).
 *
 * The shareholding transaction ledger becomes authoritative for holdings:
 * existing company_shareholders.shares_held values are backfilled as opening
 * issue transactions, and the column remains only as a display cache kept
 * in sync by ShareholdingService. Franking account entries are a notional
 * ledger (no GL posting); dividend declarations post two-stage journals
 * (Dr Dividends Paid / Cr Dividends Payable on approval, then Dr Dividends
 * Payable / Cr Bank when the manually-paid run is recorded).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('share_classes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_profile_id');
            $table->string('code', 10);
            $table->string('description', 60)->nullable();
            $table->boolean('voting_rights')->default(true);
            $table->boolean('dividend_rights')->default(true);
            $table->integer('ranking')->default(1); // 1=Ordinary, 2=Preference, ...
            $table->boolean('franking_entitlement')->default(true);
            $table->char('status', 1)->default('A'); // A=Active, I=Inactive
            $table->timestamps();

            $table->foreign('company_profile_id')->references('id')->on('company_profiles')->cascadeOnDelete();
            $table->unique(['company_profile_id', 'code']);
        });

        Schema::create('shareholdings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_shareholder_id');
            $table->unsignedBigInteger('share_class_id');
            $table->char('transaction_type', 1); // I=Issue, T=Transfer, B=Buyback, C=Consolidation
            $table->date('transaction_date');
            $table->integer('quantity'); // signed: + increases the holding, - reduces it
            $table->decimal('unit_price', 12, 4)->nullable();
            $table->decimal('amount_paid', 14, 2)->nullable();
            $table->string('reference', 20)->nullable(); // certificate / transfer ref
            $table->char('status', 1)->default('A'); // A=Active, C=Cancelled
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->foreign('company_shareholder_id')->references('id')->on('company_shareholders')->cascadeOnDelete();
            $table->foreign('share_class_id')->references('id')->on('share_classes')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['company_shareholder_id', 'transaction_date']);
            $table->index(['share_class_id', 'transaction_date']);
        });

        Schema::table('company_shareholders', function (Blueprint $table) {
            $table->string('address_line2', 100)->nullable()->after('address_line1');
            $table->string('contact_name', 60)->nullable()->after('country');
            $table->string('bank_bsb', 7)->nullable()->after('phone');
            $table->string('bank_account_number', 9)->nullable()->after('bank_bsb');
            $table->string('bank_account_name', 60)->nullable()->after('bank_account_number');
        });

        Schema::create('dividend_declarations', function (Blueprint $table) {
            $table->id();
            $table->string('declaration_number', 15)->unique(); // DIV-YYYY-NNNN
            $table->unsignedBigInteger('entity_id');
            $table->date('declaration_date');
            $table->integer('financial_year');
            $table->unsignedBigInteger('share_class_id');
            $table->char('dividend_type', 1)->default('I'); // I=Interim, F=Final, S=Special
            $table->decimal('amount_per_share', 12, 6);
            $table->decimal('franking_percentage', 5, 2)->default(100.00); // 0-100
            $table->decimal('franking_credit_rate', 5, 2)->default(30.00); // corporate tax rate
            $table->date('payment_date');
            $table->date('books_close_date'); // entitlement date
            $table->unsignedInteger('total_shares_eligible')->default(0);
            $table->decimal('total_cash_dividend', 14, 2)->default(0);
            $table->decimal('total_franking_credit', 14, 2)->default(0);
            $table->decimal('total_grossed_up', 14, 2)->default(0);
            $table->string('status', 20)->default('draft'); // draft, approved, completed, cancelled
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->datetime('approved_at')->nullable();
            $table->datetime('paid_at')->nullable();
            $table->unsignedBigInteger('ifrs_declaration_transaction_id')->nullable();
            $table->unsignedBigInteger('ifrs_payment_transaction_id')->nullable();
            $table->string('notes', 500)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->foreign('share_class_id')->references('id')->on('share_classes')->restrictOnDelete();
            $table->foreign('approved_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['status', 'payment_date']);
            $table->index('payment_date');
            $table->index('entity_id');
        });

        Schema::create('dividend_distributions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('dividend_declaration_id');
            $table->unsignedBigInteger('company_shareholder_id')->nullable(); // null keeps history if holder removed
            $table->string('shareholder_name', 100); // snapshot at generation time
            $table->unsignedBigInteger('share_class_id');
            $table->unsignedInteger('shares_eligible');
            $table->decimal('cash_dividend', 14, 2);
            $table->decimal('franking_credit', 14, 2);
            $table->decimal('grossed_up_dividend', 14, 2);
            $table->decimal('withholding_tax', 14, 2)->default(0); // reserved (non-residents, Phase 4)
            $table->decimal('net_payment', 14, 2);
            $table->string('payment_reference', 20)->nullable();
            $table->string('status', 20)->default('pending'); // pending, paid, cancelled
            $table->datetime('paid_at')->nullable();
            $table->boolean('statement_sent')->default(false);
            $table->datetime('statement_sent_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->foreign('dividend_declaration_id')->references('id')->on('dividend_declarations')->cascadeOnDelete();
            $table->foreign('company_shareholder_id')->references('id')->on('company_shareholders')->nullOnDelete();
            $table->foreign('share_class_id')->references('id')->on('share_classes')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->unique(['dividend_declaration_id', 'company_shareholder_id']);
            $table->index(['company_shareholder_id', 'status']);
        });

        // After dividend_declarations — the FD entry type links to its declaration.
        Schema::create('franking_account_entries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('entity_id');
            $table->integer('financial_year'); // FY label, e.g. 2025 = 1 Jul 2025 - 30 Jun 2026
            $table->date('entry_date');
            $table->char('entry_type', 2); // TC=Tax Payment, DR=Dividend Received, FD=Franked Dividend Paid,
                                           // RF=Refund Received, FT=Franking Deficit Tax, AJ=Adjustment
            $table->string('reference', 20)->nullable();
            $table->string('description', 100)->nullable();
            $table->decimal('credit_amount', 14, 2)->default(0); // increases balance
            $table->decimal('debit_amount', 14, 2)->default(0);  // decreases balance
            $table->boolean('is_estimated')->default(false); // AASB 1054.13 anticipated entries
            $table->unsignedBigInteger('dividend_declaration_id')->nullable();
            $table->unsignedBigInteger('ifrs_transaction_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->foreign('dividend_declaration_id')->references('id')->on('dividend_declarations')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['financial_year', 'entry_date']);
            $table->index(['entry_type', 'financial_year']);
            $table->index('entity_id');
        });

        // Seed an ORD share class per company profile and backfill each
        // shareholder's shares_held as an opening issue transaction so the
        // ledger is authoritative from day one.
        foreach (\DB::table('company_profiles')->get() as $profile) {
            $classId = \DB::table('share_classes')->insertGetId([
                'company_profile_id' => $profile->id,
                'code' => 'ORD',
                'description' => 'Ordinary Shares',
                'voting_rights' => true,
                'dividend_rights' => true,
                'ranking' => 1,
                'franking_entitlement' => true,
                'status' => 'A',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach (\DB::table('company_shareholders')->where('company_profile_id', $profile->id)->get() as $shareholder) {
                if ((int) $shareholder->shares_held <= 0) {
                    continue;
                }

                \DB::table('shareholdings')->insert([
                    'company_shareholder_id' => $shareholder->id,
                    'share_class_id' => $classId,
                    'transaction_type' => 'I',
                    'transaction_date' => $profile->created_at ? \Carbon\Carbon::parse($profile->created_at)->toDateString() : now()->toDateString(),
                    'quantity' => (int) $shareholder->shares_held,
                    'reference' => 'OPENING',
                    'status' => 'A',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('dividend_distributions');
        Schema::dropIfExists('dividend_declarations');
        Schema::dropIfExists('franking_account_entries');

        Schema::table('company_shareholders', function (Blueprint $table) {
            $table->dropColumn(['address_line2', 'contact_name', 'bank_bsb', 'bank_account_number', 'bank_account_name']);
        });

        Schema::dropIfExists('shareholdings');
        Schema::dropIfExists('share_classes');
    }
};

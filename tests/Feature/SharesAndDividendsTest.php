<?php

namespace Tests\Feature;

use App\Mail\DividendStatementMail;
use App\Models\CompanyProfile;
use App\Models\CompanyShareholder;
use App\Models\DividendDeclaration;
use App\Models\DividendDistribution;
use App\Models\FrankingAccountEntry;
use App\Models\ShareClass;
use App\Models\Shareholding;
use App\Models\User;
use App\Services\FrankingService;
use App\Services\ShareholdingService;
use Carbon\Carbon;
use Hash;
use IFRS\Models\Account;
use IFRS\Models\Currency;
use IFRS\Models\Entity;
use IFRS\Models\ExchangeRate;
use IFRS\Models\Ledger;
use IFRS\Models\ReportingPeriod;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * End-to-end dividend lifecycle: shareholding ledger → distribution
 * calculation → approval (Dr Dividends Paid / Cr Dividends Payable, with
 * the franking-availability check) → manual payment run → record payment
 * (Dr Dividends Payable / Cr Bank, franking debit, statement emails).
 * Franking credits never appear in the GL.
 */
class SharesAndDividendsTest extends TestCase
{
    protected Entity $entity;

    protected User $admin;

    protected Account $bank;

    protected Account $dividendsPayable;

    protected Account $dividendsPaid;

    protected CompanyShareholder $alice;

    protected CompanyShareholder $bob;

    protected ShareClass $ord;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::create(2026, 2, 15, 9, 0, 0));

        foreach (['admin', 'accountant', 'staff'] as $role) {
            Role::firstOrCreate(['name' => $role]);
        }

        $this->entity = Entity::create([
            'name' => 'Dividend Co',
            'locale' => 'en_GB',
            'year_start' => 7,
            'multi_currency' => false,
        ]);

        $currency = Currency::create([
            'name' => 'Australian Dollar',
            'currency_code' => 'AUD',
            'entity_id' => $this->entity->id,
        ]);
        $this->entity->update(['currency_id' => $currency->id]);
        $this->entity->refresh();

        ExchangeRate::create([
            'currency_id' => $currency->id,
            'rate' => 1.0,
            'valid_from' => Carbon::create(2025, 1, 1),
            'entity_id' => $this->entity->id,
        ]);

        ReportingPeriod::create([
            'calendar_year' => 2025,
            'status' => ReportingPeriod::OPEN,
            'period_count' => 1,
            'entity_id' => $this->entity->id,
        ]);

        $this->bank = Account::create([
            'account_type' => Account::BANK,
            'name' => 'Operating Account',
            'code' => 320,
            'currency_id' => $currency->id,
            'entity_id' => $this->entity->id,
        ]);
        $this->dividendsPayable = Account::create([
            'account_type' => Account::CURRENT_LIABILITY,
            'name' => 'Dividends Payable',
            'code' => 2260,
            'currency_id' => $currency->id,
            'entity_id' => $this->entity->id,
        ]);
        $this->dividendsPaid = Account::create([
            'account_type' => Account::EQUITY,
            'name' => 'Dividends Paid',
            'code' => 3400,
            'currency_id' => $currency->id,
            'entity_id' => $this->entity->id,
        ]);

        $this->admin = new User;
        $this->admin->name = 'Controller';
        $this->admin->email = 'controller'.uniqid().'@example.com';
        $this->admin->email_verified_at = now();
        $this->admin->password = Hash::make('password');
        $this->admin->entity_id = $this->entity->id;
        $this->admin->save();
        $this->admin->assignRole('admin');
        $this->actingAs($this->admin);

        $profile = CompanyProfile::create(['entity_id' => $this->entity->id, 'country' => 'AU', 'abn' => '12345678901']);
        $this->ord = $profile->shareClasses()->create([
            'code' => 'ORD',
            'description' => 'Ordinary Shares',
            'status' => ShareClass::STATUS_ACTIVE,
        ]);

        $this->alice = $profile->allShareholders()->create([
            'name' => 'Alice Holder',
            'email' => 'alice@example.com',
            'bank_bsb' => '062-000',
            'bank_account_number' => '12345678',
            'bank_account_name' => 'A HOLDER',
            'resident_for_tax' => true,
            'status' => CompanyShareholder::STATUS_ACTIVE,
        ]);
        $this->bob = $profile->allShareholders()->create([
            'name' => 'Bob Holder',
            'email' => 'bob@example.com',
            'resident_for_tax' => true,
            'status' => CompanyShareholder::STATUS_ACTIVE,
        ]);

        ShareholdingService::record($this->alice, [
            'share_class_id' => $this->ord->id,
            'transaction_type' => Shareholding::TYPE_ISSUE,
            'transaction_date' => '2025-07-15',
            'quantity' => 1000,
        ]);
        ShareholdingService::record($this->bob, [
            'share_class_id' => $this->ord->id,
            'transaction_type' => Shareholding::TYPE_ISSUE,
            'transaction_date' => '2025-07-15',
            'quantity' => 500,
        ]);

        // Income tax paid FY2025 → 30,000 franking credits.
        FrankingService::recordEntry([
            'entry_type' => FrankingAccountEntry::TYPE_TAX_PAYMENT,
            'entry_date' => '2025-09-30',
            'credit_amount' => 30000,
            'reference' => 'IAS-1',
            'description' => 'Income tax instalment',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Cumulative debit-positive balance, same convention as the reports.
     * The window looks past the frozen test clock so journals dated on
     * future payment dates (still within the open FY) are included.
     */
    protected function balance(Account $account): float
    {
        return round((float) Ledger::balance(
            $account,
            Carbon::create(2000, 1, 1),
            Carbon::create(2030, 1, 1),
            $this->entity->currency_id,
        )[$this->entity->currency_id], 2);
    }

    protected function createDeclaration(array $overrides = []): DividendDeclaration
    {
        $this->post(route('dividends.store'), array_merge([
            'declaration_date' => '2026-02-01',
            'share_class_id' => $this->ord->id,
            'dividend_type' => DividendDeclaration::DIVIDEND_TYPE_INTERIM,
            'amount_per_share' => '0.07',
            'franking_percentage' => '100',
            'franking_credit_rate' => '30',
            'payment_date' => '2026-02-20',
            'books_close_date' => '2026-02-10',
        ], $overrides))->assertRedirect(route('dividends.index'));

        return DividendDeclaration::latest('id')->first();
    }

    public function test_shareholding_ledger_holds_authoritative_holdings(): void
    {
        $this->assertSame(1000, ShareholdingService::totalShares($this->alice->id));
        $this->assertSame(1500, ShareholdingService::totalShares($this->alice->id) + ShareholdingService::totalShares($this->bob->id));
        $this->assertSame(1500, ShareholdingService::issuedShares($this->ord->id));

        // As-at dates exclude future transactions and cancelled rows.
        $this->assertSame(0, ShareholdingService::totalShares($this->alice->id, $this->ord->id, '2025-07-01'));
        $this->assertSame(1000, ShareholdingService::totalShares($this->alice->id, $this->ord->id, '2025-07-15'));

        ShareholdingService::record($this->bob, [
            'share_class_id' => $this->ord->id,
            'transaction_type' => Shareholding::TYPE_BUYBACK,
            'transaction_date' => '2026-01-10',
            'quantity' => -200,
        ]);
        $this->assertSame(300, ShareholdingService::totalShares($this->bob->id));
        $this->bob->refresh();
        $this->assertSame(300, $this->bob->shares_held); // display cache synced
    }

    public function test_buyback_cannot_take_holding_negative(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ShareholdingService::record($this->alice, [
            'share_class_id' => $this->ord->id,
            'transaction_type' => Shareholding::TYPE_BUYBACK,
            'transaction_date' => '2026-01-10',
            'quantity' => -1001,
        ]);
    }

    public function test_full_lifecycle_posts_two_stage_journals_and_emails_statements(): void
    {
        $declaration = $this->createDeclaration();

        // Calculate: Alice 1000 shares → $70 cash / $30 credit; Bob 500 → $35 / $15.
        $this->post(route('dividends.calculate', $declaration))
            ->assertSessionHas('success');

        $declaration->refresh();
        $lines = $declaration->distributions()->orderBy('company_shareholder_id')->get();
        $this->assertCount(2, $lines);

        $aliceLine = $lines->firstWhere('company_shareholder_id', $this->alice->id);
        $this->assertSame(1000, $aliceLine->shares_eligible);
        $this->assertEquals(70.0, (float) $aliceLine->cash_dividend);
        $this->assertEquals(30.0, (float) $aliceLine->franking_credit);
        $this->assertEquals(100.0, (float) $aliceLine->grossed_up_dividend);
        $this->assertEquals(70.0, (float) $aliceLine->net_payment);

        $this->assertSame(1500, $declaration->total_shares_eligible);
        $this->assertEquals(105.0, (float) $declaration->total_cash_dividend);
        $this->assertEquals(45.0, (float) $declaration->total_franking_credit);
        $this->assertEquals(150.0, (float) $declaration->total_grossed_up);
        $this->assertSame(2025, $declaration->financial_year);

        // Approve → Dr Dividends Paid / Cr Dividends Payable.
        $this->post(route('dividends.approve', $declaration))->assertSessionHas('success');
        $declaration->refresh();
        $this->assertSame(DividendDeclaration::STATUS_APPROVED, $declaration->status);
        $this->assertNotNull($declaration->ifrs_declaration_transaction_id);

        $this->assertEquals(105.0, $this->balance($this->dividendsPaid));
        $this->assertEquals(-105.0, $this->balance($this->dividendsPayable));
        $this->assertEquals(0.0, $this->balance($this->bank));

        // The approved-but-unpaid run reserves its franking debits.
        $this->assertEquals(29955.0, FrankingService::availableBalance());

        // Payment schedule CSV for the manual bank run.
        $schedule = $this->get(route('dividends.payment-schedule.csv', $declaration));
        $schedule->assertStatus(200);
        $this->assertStringContainsString('Alice Holder', $schedule->streamedContent());

        // Record payment → Dr Dividends Payable / Cr Bank + franking debit + statements.
        Mail::fake();
        $this->post(route('dividends.record-payment', $declaration))->assertSessionHas('success');

        $declaration->refresh();
        $this->assertSame(DividendDeclaration::STATUS_COMPLETED, $declaration->status);
        $this->assertNotNull($declaration->ifrs_payment_transaction_id);

        $this->assertEquals(0.0, $this->balance($this->dividendsPayable)); // liability cleared
        $this->assertEquals(-105.0, $this->balance($this->bank));          // only cash left the bank
        $this->assertEquals(105.0, $this->balance($this->dividendsPaid));  // franking credits never posted

        $fd = FrankingAccountEntry::where('entry_type', FrankingAccountEntry::TYPE_FRANKED_DIVIDEND_PAID)
            ->where('dividend_declaration_id', $declaration->id)->first();
        $this->assertNotNull($fd);
        $this->assertEquals(45.0, (float) $fd->debit_amount);
        $this->assertFalse((bool) $fd->is_estimated);
        $this->assertEquals(29955.0, FrankingService::balance());

        $this->assertSame(
            DividendDistribution::STATUS_PAID,
            $declaration->distributions()->where('company_shareholder_id', $this->alice->id)->value('status'),
        );

        // The mailable is queueable, so the fake records it as queued.
        Mail::assertQueued(DividendStatementMail::class, 2);
        $this->assertTrue((bool) $declaration->distributions()->where('company_shareholder_id', $this->alice->id)->value('statement_sent'));
    }

    public function test_books_close_date_governs_eligibility(): void
    {
        $declaration = $this->createDeclaration();
        $this->post(route('dividends.calculate', $declaration));

        // Bob acquires more shares AFTER the books-close date.
        ShareholdingService::record($this->bob, [
            'share_class_id' => $this->ord->id,
            'transaction_type' => Shareholding::TYPE_ISSUE,
            'transaction_date' => '2026-02-12',
            'quantity' => 500,
        ]);

        $this->post(route('dividends.calculate', $declaration)); // recalculate
        $bobLine = $declaration->distributions()->where('company_shareholder_id', $this->bob->id)->first();
        $this->assertSame(500, $bobLine->shares_eligible); // new parcel excluded
    }

    public function test_approval_blocked_when_franking_credits_insufficient(): void
    {
        $declaration = $this->createDeclaration(['amount_per_share' => '500']);
        $this->post(route('dividends.calculate', $declaration));

        // 1,500 shares x $500 = $750,000 cash → $321,428.57 credits vs $30,000 available.
        $this->post(route('dividends.approve', $declaration))
            ->assertSessionHas('error');

        $declaration->refresh();
        $this->assertSame(DividendDeclaration::STATUS_DRAFT, $declaration->status);
        $this->assertNull($declaration->ifrs_declaration_transaction_id);
        $this->assertEquals(0.0, $this->balance($this->dividendsPaid)); // nothing posted
    }

    public function test_unfranked_dividend_needs_no_franking_balance(): void
    {
        $declaration = $this->createDeclaration(['franking_percentage' => '0']);
        $this->post(route('dividends.calculate', $declaration));

        $declaration->refresh();
        $this->assertEquals(0.0, (float) $declaration->total_franking_credit);

        $this->post(route('dividends.approve', $declaration))->assertSessionHas('success');
        $this->assertSame(DividendDeclaration::STATUS_APPROVED, $declaration->refresh()->status);
    }

    public function test_cancelling_approved_declaration_reverses_the_ledger(): void
    {
        $declaration = $this->createDeclaration();
        $this->post(route('dividends.calculate', $declaration));
        $this->post(route('dividends.approve', $declaration));

        $this->assertEquals(105.0, $this->balance($this->dividendsPaid));

        $this->post(route('dividends.cancel', $declaration))->assertSessionHas('success');
        $declaration->refresh();

        $this->assertSame(DividendDeclaration::STATUS_CANCELLED, $declaration->status);
        $this->assertEquals(0.0, $this->balance($this->dividendsPaid));
        $this->assertEquals(0.0, $this->balance($this->dividendsPayable));
        $this->assertEquals(0.0, $this->balance($this->bank));

        // Cancelled runs no longer reserve franking credits.
        $this->assertEquals(30000.0, FrankingService::availableBalance());
        $this->assertSame(
            DividendDistribution::STATUS_CANCELLED,
            $declaration->distributions()->where('company_shareholder_id', $this->alice->id)->value('status'),
        );
    }

    public function test_completed_run_cannot_be_cancelled(): void
    {
        $declaration = $this->createDeclaration();
        $this->post(route('dividends.calculate', $declaration));
        $this->post(route('dividends.approve', $declaration));
        Mail::fake();
        $this->post(route('dividends.record-payment', $declaration));

        $this->post(route('dividends.cancel', $declaration))->assertSessionHas('error');
        $this->assertSame(DividendDeclaration::STATUS_COMPLETED, $declaration->refresh()->status);
    }

    public function test_holdings_relied_on_by_approved_declarations_are_locked(): void
    {
        $holding = Shareholding::where('company_shareholder_id', $this->alice->id)->first();
        $this->assertFalse(ShareholdingService::protectedByDeclarations($holding));

        $declaration = $this->createDeclaration();
        $this->post(route('dividends.calculate', $declaration));
        $this->post(route('dividends.approve', $declaration));

        $this->assertTrue(ShareholdingService::protectedByDeclarations($holding));
        $this->post(route('shareholders.shareholdings.cancel', [$this->alice, $holding]))
            ->assertSessionHas('error');
        $this->assertSame(Shareholding::STATUS_ACTIVE, $holding->refresh()->status);
    }

    public function test_statement_skips_shareholders_without_email(): void
    {
        $this->bob->update(['email' => null]);

        $declaration = $this->createDeclaration();
        $this->post(route('dividends.calculate', $declaration));
        $this->post(route('dividends.approve', $declaration));

        Mail::fake();
        $this->post(route('dividends.record-payment', $declaration))->assertSessionHas('success');

        Mail::assertQueued(DividendStatementMail::class, 1); // Alice only
        $this->assertTrue((bool) $declaration->distributions()->where('company_shareholder_id', $this->alice->id)->value('statement_sent'));
        $this->assertFalse((bool) $declaration->distributions()->where('company_shareholder_id', $this->bob->id)->value('statement_sent'));
    }

    public function test_tax_rate_classification_drives_default_franking_rate(): void
    {
        // Default classification: base rate entity (small company, 25%).
        $this->assertSame(25.0, CompanyProfile::effectiveTaxRate($this->entity->id));

        $this->get(route('dividends.create'))
            ->assertStatus(200)
            ->assertSee('Base rate entity (small company)')
            ->assertSee('value="25', false); // prefilled franking credit rate

        // Reclassify as a regular company (30%) — the default rate follows.
        $this->put(route('company-profile.update'), [
            'tax_rate_type' => CompanyProfile::TAX_RATE_COMPANY,
        ])->assertSessionHas('success');

        $this->assertSame(30.0, CompanyProfile::effectiveTaxRate($this->entity->id));
        $this->get(route('dividends.create'))
            ->assertSee('Other company')
            ->assertSee('value="30', false);
    }

    public function test_small_company_franking_gross_up_uses_its_own_rate(): void
    {
        // 25% classification: $75 cash fully franked attaches $25, not $32.14.
        $declaration = $this->createDeclaration(['amount_per_share' => '0.075', 'franking_credit_rate' => '25']);
        $this->post(route('dividends.calculate', $declaration));

        $aliceLine = $declaration->distributions()->where('company_shareholder_id', $this->alice->id)->first();
        $this->assertEquals(75.0, (float) $aliceLine->cash_dividend);
        $this->assertEquals(25.0, (float) $aliceLine->franking_credit);
    }

    public function test_dividend_screens_render_for_admin_and_block_staff(): void
    {
        $this->get(route('dividends.index'))->assertStatus(200);
        $this->get(route('shareholders.index'))->assertStatus(200);
        $this->get(route('share-classes.index'))->assertStatus(200);
        $this->get(route('franking-account.index'))->assertStatus(200);

        $staff = User::factory()->create();
        $staff->entity_id = $this->entity->id;
        $staff->save();
        $staff->assignRole('staff');

        $this->actingAs($staff)->get(route('dividends.index'))->assertStatus(403);
        $this->actingAs($staff)->get(route('franking-account.index'))->assertStatus(403);
    }
}

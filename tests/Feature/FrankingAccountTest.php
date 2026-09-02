<?php

namespace Tests\Feature;

use App\Models\CompanyProfile;
use App\Models\DividendDeclaration;
use App\Models\FrankingAccountEntry;
use App\Models\User;
use App\Services\FrankingService;
use Carbon\Carbon;
use Hash;
use IFRS\Models\Currency;
use IFRS\Models\Entity;
use IFRS\Models\ExchangeRate;
use IFRS\Models\ReportingPeriod;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The notional franking account: manual entry maintenance, the computed
 * running balance, AASB 1054.13 disclosure adjustments (estimated entries
 * and approved-but-unpaid dividends) and the year-end deficit warning.
 */
class FrankingAccountTest extends TestCase
{
    protected Entity $entity;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::create(2026, 2, 15, 9, 0, 0));

        foreach (['admin', 'accountant', 'staff'] as $role) {
            Role::firstOrCreate(['name' => $role]);
        }

        $this->entity = Entity::create([
            'name' => 'Franking Co',
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

        $this->admin = new User;
        $this->admin->name = 'Accountant';
        $this->admin->email = 'accountant'.uniqid().'@example.com';
        $this->admin->email_verified_at = now();
        $this->admin->password = Hash::make('password');
        $this->admin->entity_id = $this->entity->id;
        $this->admin->save();
        $this->admin->assignRole('accountant');
        $this->actingAs($this->admin);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function entry(array $attributes): FrankingAccountEntry
    {
        return FrankingService::recordEntry(array_merge([
            'entry_date' => '2025-09-30',
            'description' => 'Test entry',
        ], $attributes));
    }

    public function test_balance_nets_credits_and_debits_across_years(): void
    {
        $this->entry(['entry_type' => FrankingAccountEntry::TYPE_TAX_PAYMENT, 'credit_amount' => 30000, 'entry_date' => '2024-12-15']);
        $this->entry(['entry_type' => FrankingAccountEntry::TYPE_REFUND_RECEIVED, 'debit_amount' => 5000, 'entry_date' => '2025-11-20']);

        // The balance is a lifetime running total; FY boundaries only group it.
        $this->assertEquals(30000.0, FrankingService::openingBalance(2025));
        $this->assertEquals(25000.0, FrankingService::closingBalance(2025));
        $this->assertEquals(25000.0, FrankingService::balance());

        // A refund dated in a later year does not affect FY2025's closing.
        $this->entry(['entry_type' => FrankingAccountEntry::TYPE_REFUND_RECEIVED, 'debit_amount' => 1000, 'entry_date' => '2026-08-01']);
        $this->assertEquals(25000.0, FrankingService::closingBalance(2025));
        $this->assertEquals(24000.0, FrankingService::balance());
    }

    public function test_estimated_entries_feed_disclosure_but_not_balance(): void
    {
        $this->entry(['entry_type' => FrankingAccountEntry::TYPE_TAX_PAYMENT, 'credit_amount' => 10000]);
        $this->entry([
            'entry_type' => FrankingAccountEntry::TYPE_ADJUSTMENT,
            'credit_amount' => 2000,
            'is_estimated' => true,
            'description' => 'Credits expected from the current tax provision',
        ]);

        $this->assertEquals(10000.0, FrankingService::balance('2026-06-30'));
        $this->assertEquals(2000.0, FrankingService::estimatedNetCredits());
        $this->assertEquals(12000.0, FrankingService::availableBalance());

        $data = FrankingService::disclosureData(2025);
        $this->assertEquals(10000.0, $data['closing_balance']);
        $this->assertEquals(2000.0, $data['anticipated_credits']);
        $this->assertEquals(0.0, $data['anticipated_debits']);
        $this->assertEquals(12000.0, $data['available']);
        $this->assertFalse($data['deficit']);
    }

    public function test_approved_unpaid_dividends_reduce_available_credits(): void
    {
        $this->entry(['entry_type' => FrankingAccountEntry::TYPE_TAX_PAYMENT, 'credit_amount' => 10000]);

        $profile = CompanyProfile::create(['entity_id' => $this->entity->id, 'country' => 'AU']);
        $class = $profile->shareClasses()->create(['code' => 'ORD', 'description' => 'Ordinary Shares']);

        DividendDeclaration::create([
            'entity_id' => $this->entity->id,
            'declaration_date' => '2026-02-01',
            'financial_year' => 2025,
            'share_class_id' => $class->id,
            'amount_per_share' => 0.10,
            'franking_percentage' => 100,
            'franking_credit_rate' => 30,
            'payment_date' => '2026-02-20',
            'books_close_date' => '2026-02-10',
            'total_cash_dividend' => 700,
            'total_franking_credit' => 300,
            'status' => DividendDeclaration::STATUS_APPROVED,
            'approved_at' => '2026-02-05 10:00:00',
        ]);

        // Balance unchanged (no FD entry until paid)...
        $this->assertEquals(10000.0, FrankingService::balance());
        // ...but the approved run's credits are spoken for, and disclosed.
        $this->assertEquals(9700.0, FrankingService::availableBalance());

        $data = FrankingService::disclosureData(2025);
        $this->assertEquals(300.0, $data['anticipated_debits']);
        $this->assertEquals(9700.0, $data['available']);
    }

    public function test_deficit_warning_fires_on_negative_closing_balance(): void
    {
        $this->entry(['entry_type' => FrankingAccountEntry::TYPE_TAX_PAYMENT, 'credit_amount' => 1000]);
        $this->entry(['entry_type' => FrankingAccountEntry::TYPE_REFUND_RECEIVED, 'debit_amount' => 5000]);

        $this->assertTrue(FrankingService::hasDeficit(2025));

        $this->get(route('franking-account.index'))
            ->assertStatus(200)
            ->assertSee('Franking deficit');
    }

    public function test_manual_entries_via_http_and_validation(): void
    {
        $this->post(route('franking-account.store'), [
            'entry_type' => FrankingAccountEntry::TYPE_TAX_PAYMENT,
            'entry_date' => '2025-09-30',
            'credit_amount' => '1000',
            'debit_amount' => '0',
            'description' => 'Income tax paid',
        ])->assertSessionHas('success');

        $this->assertEquals(1000.0, FrankingService::balance());

        // Both sides non-zero is rejected.
        $this->post(route('franking-account.store'), [
            'entry_type' => FrankingAccountEntry::TYPE_ADJUSTMENT,
            'entry_date' => '2025-10-30',
            'credit_amount' => '10',
            'debit_amount' => '10',
        ])->assertSessionHas('error');

        // FD is system-reserved — rejected at validation.
        $this->post(route('franking-account.store'), [
            'entry_type' => FrankingAccountEntry::TYPE_FRANKED_DIVIDEND_PAID,
            'entry_date' => '2025-10-30',
            'debit_amount' => '10',
        ])->assertSessionHasErrors('entry_type');
    }

    public function test_opening_balance_entry_carries_forward_without_polluting_movements(): void
    {
        $this->entry([
            'entry_type' => FrankingAccountEntry::TYPE_OPENING_BALANCE,
            'entry_date' => '2025-06-30',
            'credit_amount' => 5000,
            'description' => 'Franking credits brought forward',
        ]);
        $this->entry([
            'entry_type' => FrankingAccountEntry::TYPE_TAX_PAYMENT,
            'entry_date' => '2025-11-20',
            'credit_amount' => 1000,
        ]);

        // The eve date belongs to the financial year it opens (FY2025 =
        // 1 Jul 2025 – 30 Jun 2026), never the year before it.
        $this->assertSame(2025, FrankingAccountEntry::query()
            ->where('entry_type', FrankingAccountEntry::TYPE_OPENING_BALANCE)
            ->value('financial_year'));

        $this->assertEquals(5000.0, FrankingService::openingBalance(2025));
        $this->assertEquals(6000.0, FrankingService::closingBalance(2025));
        $this->assertEquals(6000.0, FrankingService::balance());

        // Carry-forward, not a movement of the year it opens; the eve date
        // never creates a phantom year in the selector.
        $movements = FrankingService::movementsByType(2025);
        $this->assertArrayNotHasKey(FrankingAccountEntry::TYPE_OPENING_BALANCE, $movements);
        $this->assertEquals(1000.0, $movements[FrankingAccountEntry::TYPE_TAX_PAYMENT]);
        $this->assertContains(2025, FrankingService::years());
        $this->assertNotContains(2024, FrankingService::years());

        // The screen offers the type and lists the entry with its label.
        $this->get(route('franking-account.index', ['year' => 2025]))
            ->assertOk()
            ->assertSee('Opening balance');
    }

    public function test_opening_balance_entry_validation(): void
    {
        // Must sit on the eve of a financial year.
        $this->post(route('franking-account.store'), [
            'entry_type' => FrankingAccountEntry::TYPE_OPENING_BALANCE,
            'entry_date' => '2025-07-15',
            'credit_amount' => '1000',
        ])->assertSessionHas('error');

        // A debit opening records a brought-forward deficit.
        $this->entry([
            'entry_type' => FrankingAccountEntry::TYPE_OPENING_BALANCE,
            'entry_date' => '2025-06-30',
            'debit_amount' => 300,
        ]);
        $this->assertEquals(-300.0, FrankingService::balance());

        // One opening balance per financial year.
        $this->post(route('franking-account.store'), [
            'entry_type' => FrankingAccountEntry::TYPE_OPENING_BALANCE,
            'entry_date' => '2025-06-30',
            'credit_amount' => '1000',
        ])->assertSessionHas('error');
        $this->assertEquals(-300.0, FrankingService::balance());
    }

    public function test_system_franking_entries_cannot_be_deleted(): void
    {
        $entry = FrankingAccountEntry::create([
            'entity_id' => $this->entity->id,
            'financial_year' => 2025,
            'entry_date' => '2026-02-20',
            'entry_type' => FrankingAccountEntry::TYPE_FRANKED_DIVIDEND_PAID,
            'debit_amount' => 300,
            'created_by' => $this->admin->id,
        ]);

        $this->delete(route('franking-account.destroy', $entry))
            ->assertSessionHas('error');
        $this->assertDatabaseHas('franking_account_entries', ['id' => $entry->id]);

        // Manual entries can be.
        $manual = $this->entry(['entry_type' => FrankingAccountEntry::TYPE_TAX_PAYMENT, 'credit_amount' => 100]);
        $this->delete(route('franking-account.destroy', $manual))
            ->assertSessionHas('success');
        $this->assertDatabaseMissing('franking_account_entries', ['id' => $manual->id]);
    }

    public function test_disclosure_screen_renders(): void
    {
        $this->entry(['entry_type' => FrankingAccountEntry::TYPE_TAX_PAYMENT, 'credit_amount' => 10000]);

        $this->get(route('franking-account.disclosure', ['year' => 2025]))
            ->assertStatus(200)
            ->assertSee('AASB 1054')
            ->assertSee('10,000.00');

        $this->get(route('franking-account.disclosure.pdf', ['year' => 2025]))
            ->assertStatus(200);
    }
}

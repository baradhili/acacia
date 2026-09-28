<?php

namespace Tests\Feature;

use App\Models\Bill;
use App\Models\BillPayment;
use App\Models\Supplier;
use App\Models\User;
use Carbon\Carbon;
use IFRS\Models\Account;
use IFRS\Models\Currency;
use IFRS\Models\Entity;
use IFRS\Models\ReportingPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class IfrsReportsTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Entity $entity;

    protected function setUp(): void
    {
        parent::setUp();

        // Run migrations
        $this->artisan('migrate');

        Role::firstOrCreate(['name' => 'admin']);
        Role::firstOrCreate(['name' => 'accountant']);

        // Create IFRS entity first
        $this->entity = Entity::create([
            'name' => 'Test Entity',
            'currency_id' => 1,
            // July FY start — matches the test data below (Aug = Q1,
            // May = Q4, fy=2026 meaning Jul 2025 – Jun 2026) and the
            // production seeder; BAS/company-tax FY boundaries are
            // entity-derived.
            'year_start' => 7,
            'multi_currency' => false,
        ]);

        // The entity's currency_id must reference a real currency row for
        // account creation (FK) to work.
        $currency = Currency::create([
            'name' => 'Australian Dollar',
            'currency_code' => 'AUD',
            'entity_id' => $this->entity->id,
        ]);
        $this->entity->update(['currency_id' => $currency->id]);
        $this->entity->refresh();

        // Create IFRS reporting period
        ReportingPeriod::create([
            'entity_id' => $this->entity->id,
            'year' => Carbon::now()->year,
            'calendar_year' => Carbon::now()->year,
            'period' => Carbon::now()->month,
            'period_count' => 1,
            'start_date' => Carbon::now()->startOfMonth(),
            'end_date' => Carbon::now()->endOfMonth(),
            'status' => ReportingPeriod::OPEN,
        ]);

        // Create user with entity relationship
        $this->user = User::factory()->create();
        $this->user->entity_id = $this->entity->id;
        $this->user->save();
        $this->user->assignRole('admin');
    }

    // ============================================================
    // Account Statement Export Tests
    // ============================================================
    public function test_account_statement_page_loads(): void
    {
        $response = $this->actingAs($this->user)
            ->get(route('reports.account-statement'));

        $response->assertStatus(200);
        $response->assertSee('Account Statement');
    }

    public function test_account_statement_export_pdf_requires_account(): void
    {
        $response = $this->actingAs($this->user)
            ->get(route('reports.export.account-statement.pdf'));

        $response->assertStatus(302);
        $response->assertSessionHas('error');
    }

    // ============================================================
    // Account Schedule Export Tests
    // ============================================================
    public function test_account_schedule_page_loads(): void
    {
        $response = $this->actingAs($this->user)
            ->get(route('reports.account-schedule'));

        $response->assertStatus(200);
        $response->assertSee('Account Schedule');
    }

    public function test_bill_views_offer_capital_purchase_categories(): void
    {
        Account::create([
            'name' => 'Tools & Equipment',
            'account_type' => Account::NON_CURRENT_ASSET,
            'code' => 150,
            'currency_id' => $this->entity->currency_id,
            'entity_id' => $this->entity->id,
        ]);
        Account::create([
            'name' => 'Software',
            'account_type' => Account::NON_CURRENT_ASSET,
            'code' => 160,
            'currency_id' => $this->entity->currency_id,
            'entity_id' => $this->entity->id,
        ]);
        Account::create([
            'name' => 'Office Supplies',
            'account_type' => Account::OPERATING_EXPENSE,
            'code' => 5600,
            'currency_id' => $this->entity->currency_id,
            'entity_id' => $this->entity->id,
        ]);

        $this->actingAs($this->user)
            ->get(route('bills.create'))
            ->assertStatus(200)
            ->assertSee('Capital purchases')
            ->assertSee('Tools & Equipment')
            ->assertSee('Software')
            ->assertSee('Expenses');

        $supplier = Supplier::create(['name' => 'Test Supplier']);
        $bill = Bill::create(['supplier_id' => $supplier->id]);
        $bill->items()->create([
            'description' => 'Drill',
            'quantity' => 1,
            'unit_price' => 100,
            'tax_rate' => 10,
            'gst_added' => true,
        ]);
        $bill->recalculateTotals();

        $this->actingAs($this->user)
            ->get(route('bills.edit', $bill))
            ->assertStatus(200)
            ->assertSee('Capital purchases')
            ->assertSee('Tools & Equipment');
    }

    // ============================================================
    // Bill IFRS Journal Entry Tests (per-line GST posting is covered
    // in Unit/BillPaymentModelTest)
    // ============================================================
    public function test_bill_can_be_marked_as_paid(): void
    {
        $supplier = Supplier::create(['name' => 'Test Supplier']);

        $bill = Bill::create(['supplier_id' => $supplier->id]);
        $bill->items()->create([
            'description' => 'Office supplies',
            'quantity' => 1,
            'unit_price' => 100,
            'tax_rate' => 10,
        ]);
        $bill->recalculateTotals();
        $bill->markAsOpen();

        $payment = BillPayment::createWithUniqueNumber([
            'supplier_id' => $supplier->id,
            'paid_by' => $this->user->id,
            'amount' => 110,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'bank_transfer',
        ]);
        $payment->allocateToBill($bill, 110);

        $bill->refresh();

        $this->assertEquals(Bill::STATUS_PAID, $bill->status);
        $this->assertNotNull($bill->paid_at);
    }
}

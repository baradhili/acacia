<?php

namespace Tests\Feature;

use App\Models\Bill;
use App\Models\Supplier;
use App\Models\User;
use Carbon\Carbon;
use IFRS\Models\Currency;
use IFRS\Models\Entity;
use IFRS\Models\ReportingPeriod;
use Spatie\Permission\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $currency = \IFRS\Models\Currency::create([
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

    // ============================================================
    // BAS Report Tests
    // ============================================================
    public function test_bas_page_loads(): void
    {
        $response = $this->actingAs($this->user)
            ->get(route('reports.bas'));

        $response->assertStatus(200);
        $response->assertSee('BAS');
        $response->assertSee('Q1 (Jul-Sep)');
        $response->assertSee('Q2 (Oct-Dec)');
        $response->assertSee('Q3 (Jan-Mar)');
        $response->assertSee('Q4 (Apr-Jun)');
    }

    public function test_bas_allocates_figures_to_correct_quarters(): void
    {
        $client = \App\Models\Client::factory()->create();

        // Q1 FY2026: $110 invoice (incl $10 GST)
        $q1 = \App\Models\Invoice::create([
            'client_id' => $client->id,
            'invoice_number' => 'INV-2025-0001',
            'status' => 'sent',
            'issue_date' => '2025-08-15',
            'due_date' => '2025-09-15',
            'subtotal' => 100,
            'tax_amount' => 10,
            'total' => 110,
        ]);
        $q1->items()->create([
            'description' => 'Q1 services',
            'quantity' => 1,
            'unit_price' => 100,
            'tax_rate' => 10,
        ]);

        // Q2 FY2026: $55 bill (incl $5 GST), entered ex-GST with GST added
        $supplier = Supplier::create(['name' => 'Test Supplier']);
        $bill = Bill::create([
            'supplier_id' => $supplier->id,
            'bill_date' => '2025-11-01',
            'due_date' => '2025-12-01',
        ]);
        $bill->items()->create([
            'description' => 'Q2 supplies',
            'quantity' => 1,
            'unit_price' => 50,
            'tax_rate' => 10,
            'gst_added' => true,
        ]);
        $bill->recalculateTotals();
        $bill->markAsOpen();

        // Q4 FY2026: $220 invoice (incl $20 GST)
        $q4 = \App\Models\Invoice::create([
            'client_id' => $client->id,
            'invoice_number' => 'INV-2026-0004',
            'status' => 'sent',
            'issue_date' => '2026-05-20',
            'due_date' => '2026-06-20',
            'subtotal' => 200,
            'tax_amount' => 20,
            'total' => 220,
        ]);
        $q4->items()->create([
            'description' => 'Q4 services',
            'quantity' => 1,
            'unit_price' => 200,
            'tax_rate' => 10,
        ]);

        $response = $this->actingAs($this->user)
            ->get(route('reports.bas', ['fy' => 2026]));

        $response->assertStatus(200);
        // Q1: G1 $110.00, 1A $10.00
        $response->assertSee('$110.00');
        $response->assertSee('$10.00');
        // Q2: G11 $55.00, 1B $5.00
        $response->assertSee('$55.00');
        $response->assertSee('$5.00');
        // Q4: G1 $220.00, 1A $20.00
        $response->assertSee('$220.00');
        $response->assertSee('$20.00');
        // FY totals: 1A $30.00, 1B $5.00, net $25.00 payable
        $response->assertSee('$30.00');
        $response->assertSee('$25.00');
        $response->assertSee('Payable to ATO');
    }

    public function test_bas_splits_capital_and_non_capital_purchases(): void
    {
        $supplier = Supplier::create(['name' => 'Test Supplier']);

        $tools = \IFRS\Models\Account::create([
            'name' => 'Tools & Equipment',
            'account_type' => \IFRS\Models\Account::NON_CURRENT_ASSET,
            'code' => 150,
            'currency_id' => $this->entity->currency_id,
            'entity_id' => $this->entity->id,
        ]);
        $office = \IFRS\Models\Account::create([
            'name' => 'Office Supplies',
            'account_type' => \IFRS\Models\Account::OPERATING_EXPENSE,
            'code' => 5600,
            'currency_id' => $this->entity->currency_id,
            'entity_id' => $this->entity->id,
        ]);

        $bill = Bill::create([
            'supplier_id' => $supplier->id,
            'bill_date' => '2025-11-01',
            'due_date' => '2025-12-01',
        ]);
        // Non-capital line: $55 incl GST; capital line: $1,100 incl GST.
        $bill->items()->create([
            'description' => 'Office supplies',
            'quantity' => 1,
            'unit_price' => 50,
            'tax_rate' => 10,
            'gst_added' => true,
            'expense_account_id' => $office->id,
        ]);
        $bill->items()->create([
            'description' => 'Cordless drill',
            'quantity' => 1,
            'unit_price' => 1000,
            'tax_rate' => 10,
            'gst_added' => true,
            'expense_account_id' => $tools->id,
        ]);
        $bill->recalculateTotals();
        $bill->markAsOpen();

        $response = $this->actingAs($this->user)
            ->get(route('reports.bas', ['fy' => 2026]));

        $response->assertStatus(200);
        $response->assertSee('G10 Capital purchases');
        $response->assertSee('G11 Non-capital purchases');
        // Q2: the drill lands in G10, supplies in G11.
        $response->assertSee('$1,100.00');
        $response->assertSee('$55.00');
        // 1B covers GST on both lines: $5 + $100.
        $response->assertSee('$105.00');
    }

    public function test_bill_views_offer_capital_purchase_categories(): void
    {
        \IFRS\Models\Account::create([
            'name' => 'Tools & Equipment',
            'account_type' => \IFRS\Models\Account::NON_CURRENT_ASSET,
            'code' => 150,
            'currency_id' => $this->entity->currency_id,
            'entity_id' => $this->entity->id,
        ]);
        \IFRS\Models\Account::create([
            'name' => 'Software',
            'account_type' => \IFRS\Models\Account::NON_CURRENT_ASSET,
            'code' => 160,
            'currency_id' => $this->entity->currency_id,
            'entity_id' => $this->entity->id,
        ]);
        \IFRS\Models\Account::create([
            'name' => 'Office Supplies',
            'account_type' => \IFRS\Models\Account::OPERATING_EXPENSE,
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

    public function test_bas_excludes_draft_and_cancelled(): void
    {
        $client = \App\Models\Client::factory()->create();
        $supplier = Supplier::create(['name' => 'Test Supplier']);

        // Draft invoice — not yet a tax invoice, must not appear.
        \App\Models\Invoice::create([
            'client_id' => $client->id,
            'invoice_number' => 'INV-2025-0009',
            'status' => 'draft',
            'issue_date' => '2025-09-30',
            'due_date' => '2025-10-30',
            'subtotal' => 1000,
            'tax_amount' => 100,
            'total' => 1100,
        ]);

        // Cancelled invoice.
        \App\Models\Invoice::create([
            'client_id' => $client->id,
            'invoice_number' => 'INV-2025-0010',
            'status' => 'cancelled',
            'issue_date' => '2025-10-05',
            'due_date' => '2025-11-05',
            'subtotal' => 500,
            'tax_amount' => 50,
            'total' => 550,
        ]);

        // Draft bill (never marked open).
        $bill = Bill::create([
            'supplier_id' => $supplier->id,
            'bill_date' => '2026-03-10',
            'due_date' => '2026-04-10',
        ]);
        $bill->items()->create([
            'description' => 'Draft supplies',
            'quantity' => 1,
            'unit_price' => 275,
            'tax_rate' => 10,
            'gst_added' => true,
        ]);
        $bill->recalculateTotals();

        $response = $this->actingAs($this->user)
            ->get(route('reports.bas', ['fy' => 2026]));

        $response->assertStatus(200);
        $response->assertDontSee('$1,100.00');
        $response->assertDontSee('$550.00');
        $response->assertDontSee('$302.50');
        // Every quarter shows nil figures.
        $response->assertSee('$0.00');
    }

    public function test_bas_export_pdf_generates(): void
    {
        $response = $this->actingAs($this->user)
            ->get(route('reports.export.bas.pdf', ['fy' => 2026]));

        $response->assertStatus(200);
        $response->assertHeader('content-type', 'application/pdf');
    }

    public function test_bas_export_excel_generates(): void
    {
        $response = $this->actingAs($this->user)
            ->get(route('reports.export.bas.excel', ['fy' => 2026]));

        $response->assertStatus(200);
        $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
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

        $payment = \App\Models\BillPayment::createWithUniqueNumber([
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

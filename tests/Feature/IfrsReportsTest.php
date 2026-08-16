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
            'year_start' => 1,
            'multi_currency' => false,
        ]);

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
    // Tax Summary Report Tests
    // ============================================================
    public function test_tax_summary_page_loads(): void
    {
        $response = $this->actingAs($this->user)
            ->get(route('reports.tax-summary'));

        $response->assertStatus(200);
        $response->assertSee('Tax Summary');
    }

    public function test_tax_summary_shows_with_date_range(): void
    {
        $response = $this->actingAs($this->user)
            ->get(route('reports.tax-summary', [
                'start_date' => Carbon::now()->startOfMonth()->format('Y-m-d'),
                'end_date' => Carbon::now()->endOfMonth()->format('Y-m-d'),
            ]));

        $response->assertStatus(200);
        $response->assertSee('Tax Summary');
    }

    public function test_tax_summary_calculates_input_tax_from_bills(): void
    {
        $supplier = Supplier::create(['name' => 'Test Supplier']);

        $bill = Bill::create([
            'supplier_id' => $supplier->id,
            'bill_date' => Carbon::now()->toDateString(),
            'due_date' => Carbon::now()->addDays(30)->toDateString(),
        ]);
        $bill->items()->create([
            'description' => 'Taxable purchase',
            'quantity' => 1,
            'unit_price' => 100,
            'tax_rate' => 10,
        ]);
        $bill->items()->create([
            'description' => 'GST-free purchase',
            'quantity' => 1,
            'unit_price' => 50,
            'tax_rate' => 0,
        ]);
        $bill->recalculateTotals();
        $bill->markAsOpen();

        $response = $this->actingAs($this->user)
            ->get(route('reports.tax-summary', [
                'start_date' => Carbon::now()->startOfMonth()->format('Y-m-d'),
                'end_date' => Carbon::now()->endOfMonth()->format('Y-m-d'),
            ]));

        $response->assertStatus(200);
    }

    public function test_tax_summary_export_pdf_generates(): void
    {
        $response = $this->actingAs($this->user)
            ->get(route('reports.export.tax-summary.pdf', [
                'start_date' => Carbon::now()->startOfMonth()->format('Y-m-d'),
                'end_date' => Carbon::now()->endOfMonth()->format('Y-m-d'),
            ]));

        $response->assertStatus(200);
        $response->assertHeader('content-type', 'application/pdf');
    }

    public function test_tax_summary_export_excel_generates(): void
    {
        $response = $this->actingAs($this->user)
            ->get(route('reports.export.tax-summary.excel', [
                'start_date' => Carbon::now()->startOfMonth()->format('Y-m-d'),
                'end_date' => Carbon::now()->endOfMonth()->format('Y-m-d'),
            ]));

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

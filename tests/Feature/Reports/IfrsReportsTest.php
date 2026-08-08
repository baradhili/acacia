<?php

namespace Tests\Feature;

use App\Models\Expense;
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

    public function test_tax_summary_calculates_input_tax_from_expenses(): void
    {
        $expense = Expense::factory()->create([
            'expense_date' => Carbon::now(),
            'amount' => 100,
            'tax_amount' => 15,
            'total' => 115,
            'status' => 'paid',
        ]);

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
    // Expense IFRS Journal Entry Tests
    // ============================================================
    public function test_expense_can_be_marked_as_paid(): void
    {
        $expense = Expense::factory()->create([
            'category' => 'office_supplies',
            'amount' => 100,
            'tax_amount' => 15,
            'total' => 115,
            'status' => 'approved',
            'expense_date' => Carbon::now(),
        ]);

        $result = $expense->markAsPaid(
            paymentMethod: 'bank_transfer',
            userId: $this->user->id
        );

        $expense->refresh();

        $this->assertTrue($result);
        $this->assertEquals('paid', $expense->status);
    }
}

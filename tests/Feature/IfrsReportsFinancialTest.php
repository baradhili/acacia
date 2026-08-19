<?php

namespace Tests\Feature;

use App\Models\Bill;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Supplier;
use App\Models\User;
use Carbon\Carbon;
use IFRS\Models\Account;
use IFRS\Models\Currency;
use IFRS\Models\Entity;
use IFRS\Models\LineItem;
use IFRS\Models\ReportingPeriod;
use IFRS\Transactions\JournalEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Financial report endpoints against the real ekmungai/eloquent-ifrs v6
 * API. These reports previously 500'd (nonexistent Account::getBalance(),
 * wrong ledger columns, wrong ReportingPeriod constants/columns, and a
 * nonexistent Invoice::balance) — every path here was untested before.
 */
class IfrsReportsFinancialTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Entity $entity;
    protected Currency $currency;
    protected Account $bank;
    protected Account $travel;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'admin']);

        // Australian entity: financial year runs 1 July – 30 June
        $this->entity = Entity::create([
            'name' => 'AU Test Entity',
            'locale' => 'en_AU',
            'multi_currency' => false,
            'year_start' => 7,
        ]);

        $this->currency = Currency::create([
            'name' => 'Australian Dollar',
            'currency_code' => 'AUD',
            'entity_id' => $this->entity->id,
        ]);
        $this->entity->update(['currency_id' => $this->currency->id]);
        $this->entity->refresh();

        $this->user = User::factory()->create();
        $this->user->entity_id = $this->entity->id;
        $this->user->save();
        $this->user->assignRole('admin');
        $this->actingAs($this->user);

        foreach ([
            ['bank', 'Operating Account', Account::BANK, 320],
            ['travel', 'Travel & Accommodation', Account::OPERATING_EXPENSE, 5300],
        ] as [$property, $name, $type, $code]) {
            $this->$property = Account::create([
                'name' => $name,
                'account_type' => $type,
                'code' => $code,
                'currency_id' => $this->currency->id,
                'entity_id' => $this->entity->id,
            ]);
        }

        // Annual FY reporting period, as the seeder + getReportingPeriod()
        // create it.
        ReportingPeriod::firstOrCreate(
            [
                'entity_id' => $this->entity->id,
                'calendar_year' => ReportingPeriod::year(now(), $this->entity),
            ],
            ['period_count' => 1, 'status' => ReportingPeriod::OPEN],
        );
    }

    /**
     * Post Dr <expense> / Cr <bank> for $amount — the minimum real ledger
     * activity through the package's own posting pipeline.
     */
    protected function postExpense(float $amount, ?Carbon $date = null): JournalEntry
    {
        $journalEntry = new JournalEntry([
            'account_id' => $this->travel->id,
            'transaction_date' => $date ?? Carbon::now(),
            'narration' => 'Travel paid from bank',
            'currency_id' => $this->currency->id,
            'credited' => false, // debit the expense (main side)
        ]);

        $journalEntry->addLineItem(
            LineItem::create([
                'account_id' => $this->bank->id,
                'amount' => $amount,
                'quantity' => 1,
                'credited' => true, // credit the bank
                'entity_id' => $this->entity->id,
            ])
        );

        $journalEntry->post();

        return $journalEntry;
    }

    public function test_australian_financial_year_periods(): void
    {
        // August 2026 falls in FY2026 (1 Jul 2026 – 30 Jun 2027)
        $this->assertEquals(2026, ReportingPeriod::year(Carbon::create(2026, 8, 19), $this->entity));
        $this->assertEquals(
            '2026-07-01',
            ReportingPeriod::periodStart(Carbon::create(2026, 8, 19), $this->entity)->toDateString()
        );
        $this->assertEquals(
            '2027-06-30',
            ReportingPeriod::periodEnd(Carbon::create(2026, 8, 19), $this->entity)->toDateString()
        );

        // February 2026 falls in FY2025 (1 Jul 2025 – 30 Jun 2026)
        $this->assertEquals(2025, ReportingPeriod::year(Carbon::create(2026, 2, 1), $this->entity));
        $this->assertEquals(
            '2025-07-01',
            ReportingPeriod::periodStart(Carbon::create(2026, 2, 1), $this->entity)->toDateString()
        );
    }

    public function test_trial_balance_renders_with_posted_activity(): void
    {
        $this->postExpense(110);

        $response = $this->get(route('reports.trial-balance'));

        $response->assertStatus(200);
        $response->assertSee('Travel & Accommodation');
        // Debit (expense) and credit (bank) columns balance
        $response->assertSee('110.00');
        $this->assertTrue(
            ReportingPeriod::where('entity_id', $this->entity->id)
                ->where('calendar_year', ReportingPeriod::year(now(), $this->entity))
                ->exists(),
            'getReportingPeriod should ensure the FY period exists'
        );
    }

    public function test_income_statement_renders_with_posted_activity(): void
    {
        $this->postExpense(110);

        $response = $this->get(route('reports.income-statement'));

        $response->assertStatus(200);
        $response->assertSee('Travel & Accommodation');
        $response->assertSee('110.00');
    }

    public function test_balance_sheet_renders_with_posted_activity(): void
    {
        $this->postExpense(110);

        $response = $this->get(route('reports.balance-sheet'));

        $response->assertStatus(200);
        $response->assertSee('Operating Account');
        $response->assertSee('110.00');
    }

    public function test_cash_flow_renders(): void
    {
        $this->postExpense(110);

        $response = $this->get(route('reports.cash-flow'));

        $response->assertStatus(200);
        $response->assertSee('Operating Activities');
        $response->assertSee('Net Cash Movement');
    }

    public function test_account_statement_shows_posted_ledger_entries(): void
    {
        $this->postExpense(110);

        // Bank side: credited 110
        $response = $this->get(route('reports.account-statement', [
            'account_id' => $this->bank->id,
            'start_date' => now()->startOfMonth()->toDateString(),
            'end_date' => now()->endOfDay()->toDateString(),
        ]));

        $response->assertStatus(200);
        $response->assertSee('Travel paid from bank');
        $response->assertSee('110.00');

        // Expense side: debited 110
        $response = $this->get(route('reports.account-statement', ['account_id' => $this->travel->id]));
        $response->assertStatus(200);
        $response->assertSee('Travel paid from bank');
    }

    public function test_account_statement_pdf_and_excel_download(): void
    {
        $this->postExpense(110);

        $pdf = $this->get(route('reports.export.account-statement.pdf', ['account_id' => $this->bank->id]));
        $pdf->assertStatus(200);

        $excel = $this->get(route('reports.export.account-statement.excel', ['account_id' => $this->bank->id]));
        $excel->assertStatus(200);
    }

    public function test_account_schedule_renders_for_account(): void
    {
        $this->postExpense(110);

        $response = $this->get(route('reports.account-schedule', ['account_id' => $this->bank->id]));

        $response->assertStatus(200);
        $response->assertSee('Travel paid from bank');
    }

    public function test_aging_report_buckets_overdue_ar_invoice(): void
    {
        $client = Client::factory()->create(['name' => 'Aging Client Co']);
        $invoice = Invoice::create([
            'client_id' => $client->id,
            'issue_date' => now()->subDays(60)->toDateString(),
            'due_date' => now()->subDays(40)->toDateString(), // 40 days past due
            'status' => Invoice::STATUS_SENT,
        ]);
        $invoice->items()->create([
            'description' => 'Service',
            'quantity' => 1,
            'unit_price' => 100,
            'tax_rate' => 10,
        ]);
        $invoice->refresh();
        $invoice->recalculateTotals();

        $payment = \App\Models\Payment::create([
            'client_id' => $client->id,
            'amount' => 50,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'bank_transfer',
        ]);
        $payment->allocateToInvoice($invoice, 50); // 60 still due

        $response = $this->get(route('reports.aging', ['type' => 'ar']));

        $response->assertStatus(200);
        $response->assertSee('Aging Client Co');
        $response->assertSee('60.00'); // 110 total less 50 allocated
    }

    public function test_aging_report_ap_variant_uses_bills(): void
    {
        $supplier = Supplier::create(['name' => 'Aging Supplier Co']);
        $bill = Bill::create(['supplier_id' => $supplier->id]);
        $bill->items()->create([
            'description' => 'Supplies',
            'quantity' => 1,
            'unit_price' => 220, // GST-inclusive
            'tax_rate' => 10,
        ]);
        $bill->recalculateTotals();
        $bill->markAsOpen();
        $bill->update(['due_date' => now()->subDays(45)->toDateString()]);

        $response = $this->get(route('reports.aging', ['type' => 'ap']));

        $response->assertStatus(200);
        $response->assertSee('Supplier');
        $response->assertSee('Aging Supplier Co');
        $response->assertSee('220.00');
    }

    public function test_income_by_customer_shows_outstanding(): void
    {
        $client = Client::factory()->create(['name' => 'Income Client Co']);
        $invoice = Invoice::create([
            'client_id' => $client->id,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => Invoice::STATUS_SENT,
        ]);
        $invoice->items()->create([
            'description' => 'Service',
            'quantity' => 1,
            'unit_price' => 100,
            'tax_rate' => 10,
        ]);
        $invoice->refresh();
        $invoice->recalculateTotals();

        $payment = \App\Models\Payment::create([
            'client_id' => $client->id,
            'amount' => 50,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'bank_transfer',
        ]);
        $payment->allocateToInvoice($invoice, 50);

        $response = $this->get(route('reports.income-by-customer'));

        $response->assertStatus(200);
        $response->assertSee('Income Client Co');
        $response->assertSee('60.00'); // outstanding: 110 total less 50 paid
    }

    public function test_tax_summary_uses_stored_line_amounts(): void
    {
        $client = Client::factory()->create();
        $invoice = Invoice::create([
            'client_id' => $client->id,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => Invoice::STATUS_SENT,
        ]);
        $invoice->items()->create([
            'description' => 'Service',
            'quantity' => 1,
            'unit_price' => 100, // +10 GST = 110 tax-inclusive item total
            'tax_rate' => 10,
        ]);
        $invoice->refresh();
        $invoice->recalculateTotals();

        $supplier = Supplier::create(['name' => 'Tax Supplier Co']);
        $bill = Bill::create([
            'supplier_id' => $supplier->id,
            'bill_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => Bill::STATUS_OPEN,
        ]);
        $bill->items()->create([
            'description' => 'Supplies',
            'quantity' => 1,
            'unit_price' => 110, // GST-inclusive: 100 net + 10 GST
            'tax_rate' => 10,
        ]);
        $bill->recalculateTotals();

        $response = $this->get(route('reports.tax-summary'));

        $response->assertStatus(200);
        // GST collected and GST paid both 10.00; net payable zero
        $response->assertSee('10.00');
    }

    public function test_expenses_by_category_and_gst_reports_still_render(): void
    {
        $supplier = Supplier::create(['name' => 'Category Supplier Co']);
        $bill = Bill::create([
            'supplier_id' => $supplier->id,
            'bill_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => Bill::STATUS_OPEN,
        ]);
        $bill->items()->create([
            'description' => 'Supplies',
            'quantity' => 1,
            'unit_price' => 110,
            'tax_rate' => 10,
        ]);
        $bill->recalculateTotals();

        $this->get(route('reports.expenses-by-category'))->assertStatus(200);
        $this->get(route('reports.gst'))->assertStatus(200);
    }
}

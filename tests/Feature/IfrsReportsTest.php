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

    /**
     * Accounts + Vats the payment postings need, with the production
     * seeder's codes: bank (320), revenue (4100), GST Payable (2200) +
     * GST 10% for client receipts; Other Expenses (8900) fallback and
     * GST Receivable (430) + GST Input 10% for supplier payments.
     */
    protected function seedPostingAccounts(): void
    {
        $byCode = [];
        foreach ([
            ['Operating Account', \IFRS\Models\Account::BANK, 320],
            ['Consulting Revenue', \IFRS\Models\Account::OPERATING_REVENUE, 4100],
            ['GST Payable', \IFRS\Models\Account::CONTROL, 2200],
            ['GST Receivable', \IFRS\Models\Account::CONTROL, 430],
            ['Other Expenses', \IFRS\Models\Account::OTHER_EXPENSE, 8900],
        ] as [$name, $type, $code]) {
            $byCode[$code] = \IFRS\Models\Account::create([
                'name' => $name,
                'account_type' => $type,
                'code' => $code,
                'currency_id' => $this->entity->currency_id,
                'entity_id' => $this->entity->id,
            ]);
        }

        \IFRS\Models\Vat::create([
            'name' => 'GST 10%', 'code' => 'G', 'rate' => 10,
            'account_id' => $byCode[2200]->id, 'entity_id' => $this->entity->id,
        ]);
        \IFRS\Models\Vat::create([
            'name' => 'GST Input 10%', 'code' => 'I', 'rate' => 10,
            'account_id' => $byCode[430]->id, 'entity_id' => $this->entity->id,
        ]);
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
        $this->seedPostingAccounts();
        $client = \App\Models\Client::factory()->create();

        // Q1 FY2026: $110 invoice (incl $10 GST), PAID 2025-08-15.
        $q1 = \App\Models\Invoice::create([
            'client_id' => $client->id,
            'invoice_number' => 'INV-2025-0001',
            'status' => 'sent',
            'issue_date' => '2025-08-01',
            'due_date' => '2025-09-01',
        ]);
        $q1->items()->create([
            'description' => 'Q1 services',
            'quantity' => 1,
            'unit_price' => 100,
            'tax_rate' => 10,
        ]);
        $q1->refresh();
        $q1->recalculateTotals();
        $q1Payment = \App\Models\Payment::createWithUniqueNumber([
            'client_id' => $client->id,
            'amount' => 110,
            'payment_date' => '2025-08-15',
            'payment_method' => 'bank_transfer',
        ]);
        $q1Payment->allocateToInvoice($q1, 110);
        $this->assertNotNull($q1Payment->postToIFRS(), $q1Payment->lastPostingError ?? 'posting failed');

        // Q2 FY2026: $55 bill (incl $5 GST), entered ex-GST with GST
        // added, PAID 2025-11-01.
        $supplier = Supplier::create(['name' => 'Test Supplier']);
        $bill = Bill::create([
            'supplier_id' => $supplier->id,
            'bill_date' => '2025-10-20',
            'due_date' => '2025-11-20',
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
        $billPayment = \App\Models\BillPayment::createWithUniqueNumber([
            'supplier_id' => $supplier->id,
            'amount' => $bill->total,
            'payment_date' => '2025-11-01',
            'payment_method' => \App\Models\BillPayment::METHOD_BANK_TRANSFER,
        ]);
        $billPayment->allocateToBill($bill, (float) $bill->total);
        $this->assertNotNull($billPayment->postToIFRS(), $billPayment->lastPostingError ?? 'posting failed');

        // Q3 FY2026: sent but UNPAID invoice — on the cash basis it must
        // contribute nothing.
        $unpaid = \App\Models\Invoice::create([
            'client_id' => $client->id,
            'invoice_number' => 'INV-2026-0003',
            'status' => 'sent',
            'issue_date' => '2026-02-10',
            'due_date' => '2026-03-10',
        ]);
        $unpaid->items()->create([
            'description' => 'Q3 services (unpaid)',
            'quantity' => 1,
            'unit_price' => 3000,
            'tax_rate' => 10,
        ]);

        // Q4 FY2026: $220 invoice (incl $20 GST), PAID 2026-05-20.
        $q4 = \App\Models\Invoice::create([
            'client_id' => $client->id,
            'invoice_number' => 'INV-2026-0004',
            'status' => 'sent',
            'issue_date' => '2026-05-01',
            'due_date' => '2026-06-01',
        ]);
        $q4->items()->create([
            'description' => 'Q4 services',
            'quantity' => 1,
            'unit_price' => 200,
            'tax_rate' => 10,
        ]);
        $q4->refresh();
        $q4->recalculateTotals();
        $q4Payment = \App\Models\Payment::createWithUniqueNumber([
            'client_id' => $client->id,
            'amount' => 220,
            'payment_date' => '2026-05-20',
            'payment_method' => 'bank_transfer',
        ]);
        $q4Payment->allocateToInvoice($q4, 220);
        $this->assertNotNull($q4Payment->postToIFRS(), $q4Payment->lastPostingError ?? 'posting failed');

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
        // The unpaid Q3 invoice is invisible on the cash basis.
        $response->assertDontSee('$3,300.00');
    }

    public function test_bas_splits_capital_and_non_capital_purchases(): void
    {
        $this->seedPostingAccounts();
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
            'bill_date' => '2025-10-20',
            'due_date' => '2025-11-20',
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

        $billPayment = \App\Models\BillPayment::createWithUniqueNumber([
            'supplier_id' => $supplier->id,
            'amount' => $bill->total,
            'payment_date' => '2025-11-01',
            'payment_method' => \App\Models\BillPayment::METHOD_BANK_TRANSFER,
        ]);
        $billPayment->allocateToBill($bill, (float) $bill->total);
        $this->assertNotNull($billPayment->postToIFRS(), $billPayment->lastPostingError ?? 'posting failed');

        $response = $this->actingAs($this->user)
            ->get(route('reports.bas', ['fy' => 2026]));

        $response->assertStatus(200);
        $response->assertSee('G10 Capital purchases');
        $response->assertSee('G11 Non-capital purchases');
        // Q2: the drill's payment share lands in G10, supplies in G11.
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

    public function test_bas_excludes_unpaid_invoices_unposted_and_voided_payments(): void
    {
        $this->seedPostingAccounts();
        $client = \App\Models\Client::factory()->create();

        // Sent but unpaid invoice — on the cash basis it contributes
        // nothing until a payment posts.
        $unpaid = \App\Models\Invoice::create([
            'client_id' => $client->id,
            'invoice_number' => 'INV-2025-0009',
            'status' => 'sent',
            'issue_date' => '2025-09-30',
            'due_date' => '2025-10-30',
        ]);
        $unpaid->items()->create([
            'description' => 'Unpaid services',
            'quantity' => 1,
            'unit_price' => 1000,
            'tax_rate' => 10,
        ]);

        // Payment captured but never posted — invisible until
        // ifrs:post-payments backfills it.
        \App\Models\Payment::createWithUniqueNumber([
            'client_id' => $client->id,
            'amount' => 550,
            'payment_date' => '2025-10-05',
            'payment_method' => 'bank_transfer',
        ]);

        // Posted then voided payment — the reversal nets the ledger and
        // the void status excludes it from G1.
        $invoice = \App\Models\Invoice::create([
            'client_id' => $client->id,
            'invoice_number' => 'INV-2025-0010',
            'status' => 'sent',
            'issue_date' => '2026-03-01',
            'due_date' => '2026-04-01',
        ]);
        $invoice->items()->create([
            'description' => 'Voided services',
            'quantity' => 1,
            'unit_price' => 275,
            'tax_rate' => 10,
        ]);
        $invoice->refresh();
        $invoice->recalculateTotals();
        $voided = \App\Models\Payment::createWithUniqueNumber([
            'client_id' => $client->id,
            'amount' => 302.50,
            'payment_date' => '2026-03-10',
            'payment_method' => 'bank_transfer',
        ]);
        $voided->allocateToInvoice($invoice, 302.50);
        $this->assertNotNull($voided->postToIFRS(), $voided->lastPostingError ?? 'posting failed');
        $this->assertTrue($voided->void());

        $response = $this->actingAs($this->user)
            ->get(route('reports.bas', ['fy' => 2026]));

        $response->assertStatus(200);
        $response->assertDontSee('$1,100.00');
        $response->assertDontSee('$550.00');
        $response->assertDontSee('$302.50');
        // Every quarter shows nil figures.
        $response->assertSee('$0.00');
    }

    public function test_gst_report_reads_posted_cash_basis_ledger(): void
    {
        $this->seedPostingAccounts();
        $client = \App\Models\Client::factory()->create();
        $supplier = Supplier::create(['name' => 'Test Supplier']);

        // Paid $110 invoice (incl $10 GST), posted 2025-08-15.
        $invoice = \App\Models\Invoice::create([
            'client_id' => $client->id,
            'invoice_number' => 'INV-2025-0021',
            'status' => 'sent',
            'issue_date' => '2025-08-01',
            'due_date' => '2025-09-01',
        ]);
        $invoice->items()->create([
            'description' => 'Services',
            'quantity' => 1,
            'unit_price' => 100,
            'tax_rate' => 10,
        ]);
        $invoice->refresh();
        $invoice->recalculateTotals();
        $payment = \App\Models\Payment::createWithUniqueNumber([
            'client_id' => $client->id,
            'amount' => 110,
            'payment_date' => '2025-08-15',
            'payment_method' => 'bank_transfer',
        ]);
        $payment->allocateToInvoice($invoice, 110);
        $this->assertNotNull($payment->postToIFRS(), $payment->lastPostingError ?? 'posting failed');

        // Paid $55 bill (incl $5 GST), posted 2025-11-01.
        $bill = Bill::create([
            'supplier_id' => $supplier->id,
            'bill_date' => '2025-10-20',
            'due_date' => '2025-11-20',
        ]);
        $bill->items()->create([
            'description' => 'Supplies',
            'quantity' => 1,
            'unit_price' => 50,
            'tax_rate' => 10,
            'gst_added' => true,
        ]);
        $bill->recalculateTotals();
        $bill->markAsOpen();
        $billPayment = \App\Models\BillPayment::createWithUniqueNumber([
            'supplier_id' => $supplier->id,
            'amount' => $bill->total,
            'payment_date' => '2025-11-01',
            'payment_method' => \App\Models\BillPayment::METHOD_BANK_TRANSFER,
        ]);
        $billPayment->allocateToBill($bill, (float) $bill->total);
        $this->assertNotNull($billPayment->postToIFRS(), $billPayment->lastPostingError ?? 'posting failed');

        // Sent but unpaid invoice — must not appear on a cash basis.
        $unpaid = \App\Models\Invoice::create([
            'client_id' => $client->id,
            'invoice_number' => 'INV-2026-0031',
            'status' => 'sent',
            'issue_date' => '2026-02-10',
            'due_date' => '2026-03-10',
        ]);
        $unpaid->items()->create([
            'description' => 'Unpaid services',
            'quantity' => 1,
            'unit_price' => 200,
            'tax_rate' => 10,
        ]);

        $response = $this->actingAs($this->user)
            ->get(route('reports.gst', ['start_date' => '2025-07-01', 'end_date' => '2026-06-30']));

        $response->assertStatus(200);
        // GST figures come from the posted Vat account ledger legs.
        $response->assertSee('$10.00'); // GST on sales
        $response->assertSee('$5.00');  // GST on purchases / net payable
        // Totals are the posted receipts and payments behind those legs.
        $response->assertSee('Total Receipts (incl. GST)');
        $response->assertSee('$110.00');
        $response->assertSee('Total Payments (incl. GST)');
        $response->assertSee('$55.00');
        // The unpaid invoice's figures are absent.
        $response->assertDontSee('$220.00');
        $response->assertDontSee('$20.00');
    }

    public function test_gst_report_nets_refund_reversals_per_vat_account(): void
    {
        $this->seedPostingAccounts();
        $client = \App\Models\Client::factory()->create();
        $supplier = Supplier::create(['name' => 'Test Supplier']);

        // Paid $110 invoice (incl $10 GST), posted 2025-08-15: Cr 10 on 2200.
        $invoice = \App\Models\Invoice::create([
            'client_id' => $client->id,
            'invoice_number' => 'INV-2025-0041',
            'status' => 'sent',
            'issue_date' => '2025-08-01',
            'due_date' => '2025-09-01',
        ]);
        $invoice->items()->create([
            'description' => 'Services',
            'quantity' => 1,
            'unit_price' => 100,
            'tax_rate' => 10,
        ]);
        $invoice->refresh();
        $invoice->recalculateTotals();
        $payment = \App\Models\Payment::createWithUniqueNumber([
            'client_id' => $client->id,
            'amount' => 110,
            'payment_date' => '2025-08-15',
            'payment_method' => 'bank_transfer',
        ]);
        $payment->allocateToInvoice($invoice, 110);
        $this->assertNotNull($payment->postToIFRS(), $payment->lastPostingError ?? 'posting failed');

        // Full credit-note refund (posts at now()): Dr 10 back on 2200 —
        // an output-GST reversal, not GST paid.
        $creditNote = \App\Models\CreditNote::create([
            'client_id' => $client->id,
            'total' => -110,
            'remaining_amount' => 110,
            'status' => \App\Models\CreditNote::STATUS_ISSUED,
        ]);
        $this->assertTrue($creditNote->applyToInvoice($invoice, 110));
        $refund = $creditNote->refresh()->refund;
        $this->assertNotNull($refund->ifrs_receipt_id, 'credit note refund must be posted');

        // Paid $55 bill (incl $5 GST), posted 2025-11-01: Dr 5 on 430.
        $bill = Bill::create([
            'supplier_id' => $supplier->id,
            'bill_date' => '2025-10-20',
            'due_date' => '2025-11-20',
        ]);
        $bill->items()->create([
            'description' => 'Supplies',
            'quantity' => 1,
            'unit_price' => 50,
            'tax_rate' => 10,
            'gst_added' => true,
        ]);
        $bill->recalculateTotals();
        $bill->markAsOpen();
        $billPayment = \App\Models\BillPayment::createWithUniqueNumber([
            'supplier_id' => $supplier->id,
            'amount' => $bill->total,
            'payment_date' => '2025-11-01',
            'payment_method' => \App\Models\BillPayment::METHOD_BANK_TRANSFER,
        ]);
        $billPayment->allocateToBill($bill, (float) $bill->total);
        $this->assertNotNull($billPayment->postToIFRS(), $billPayment->lastPostingError ?? 'posting failed');

        // End date reaches now(): the credit-note refund posts at today's date.
        $response = $this->actingAs($this->user)
            ->get(route('reports.gst', [
                'start_date' => '2025-07-01',
                'end_date' => now()->addDay()->toDateString(),
            ]));

        $response->assertStatus(200);
        // The refund's Dr 10 on 2200 nets collected GST to $0.00 rather
        // than being miscounted as GST paid; 430's Dr 5 stays GST paid.
        // (Old per-side totals were $10.00 collected / $15.00 paid.)
        $response->assertDontSee('$10.00');
        $response->assertDontSee('$15.00');
        $response->assertSee('$5.00');
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

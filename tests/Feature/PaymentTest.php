<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Client $client;
    protected Invoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->user = User::factory()->create();
        $this->client = Client::factory()->create([
            'name' => 'Test Client',
            'email' => 'client@test.com',
        ]);

        $this->invoice = Invoice::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => Invoice::STATUS_SENT,
        ]);

        $this->invoice->items()->create([
            'description' => 'Test Service',
            'quantity' => 1,
            'unit_price' => 100,
            'tax_rate' => 10,
        ]);

        $this->invoice->refresh();
        $this->invoice->recalculateTotals();
    }

    public function test_payment_list_page_requires_authentication(): void
    {
        $response = $this->get('/payments');
        $response->assertRedirect('/login');
    }

    public function test_can_create_payment(): void
    {
        $response = $this->actingAs($this->user)->post('/payments', [
            'client_id' => $this->client->id,
            'amount' => 110,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'bank_transfer',
            'allocate_type' => 'no',
        ]);

        $response->assertSessionHas('success');
        $this->assertDatabaseHas('payments', [
            'client_id' => $this->client->id,
            'amount' => 110,
        ]);
    }

    public function test_payment_generates_correct_payment_number(): void
    {
        $payment = Payment::create([
            'client_id' => $this->client->id,
            'amount' => 110,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'bank_transfer',
        ]);

        $this->assertMatchesRegularExpression('/^PAY-' . date('Y') . '-\d{4}$/', $payment->payment_number);
    }

    public function test_partial_payment_allocates_correctly(): void
    {
        $payment = Payment::create([
            'client_id' => $this->client->id,
            'amount' => 55,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'bank_transfer',
        ]);

        // Allocate only $30 of the $55 payment
        $payment->allocateToInvoice($this->invoice, 30);

        $this->assertEquals(30, $payment->allocated_amount);
        $this->assertEquals(25, $payment->unallocated_amount);
    }

    public function test_full_payment_updates_invoice_status(): void
    {
        $payment = Payment::create([
            'client_id' => $this->client->id,
            'amount' => 110,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'bank_transfer',
        ]);

        $payment->allocateToInvoice($this->invoice, 110);

        $this->invoice->refresh();
        $this->assertEquals(Invoice::STATUS_PAID, $this->invoice->status);
        $this->assertNotNull($this->invoice->paid_at);
    }

    public function test_payment_allocation_cannot_exceed_payment_amount(): void
    {
        $payment = Payment::create([
            'client_id' => $this->client->id,
            'amount' => 50,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'bank_transfer',
        ]);

        // Allocating more than the payment amount must throw rather than
        // silently clamping to the available balance.
        $this->expectException(\InvalidArgumentException::class);
        $payment->allocateToInvoice($this->invoice, 100);
    }

    public function test_manual_allocation_override(): void
    {
        // Create another invoice
        $invoice2 = Invoice::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->subDays(5)->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => Invoice::STATUS_SENT,
        ]);

        $invoice2->items()->create([
            'description' => 'Test Service 2',
            'quantity' => 1,
            'unit_price' => 50,
            'tax_rate' => 10,
        ]);

        // Create payment
        $payment = Payment::create([
            'client_id' => $this->client->id,
            'amount' => 55,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'bank_transfer',
        ]);

        // Manually allocate to invoice2
        $payment->allocateToInvoice($invoice2, 55);

        $this->assertDatabaseHas('payment_allocations', [
            'payment_id' => $payment->id,
            'invoice_id' => $invoice2->id,
        ]);
    }

    public function test_remove_allocation(): void
    {
        $payment = Payment::create([
            'client_id' => $this->client->id,
            'amount' => 110,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'bank_transfer',
        ]);

        $payment->allocateToInvoice($this->invoice, 110);

        $result = $payment->removeAllocation($this->invoice);

        $this->assertTrue($result);
        $this->assertDatabaseMissing('payment_allocations', [
            'payment_id' => $payment->id,
            'invoice_id' => $this->invoice->id,
        ]);
    }

    // ============================================================
    // Phase 4.5 - Payment IFRS and Email Tests
    // ============================================================

    public function test_allocation_handles_payment_exceeding_total_outstanding(): void
    {
        // Invoice total is $110
        // Payment is $200 (exceeds total outstanding)
        $payment = Payment::create([
            'client_id' => $this->client->id,
            'amount' => 200,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'bank_transfer',
        ]);

        // Allocate only what's needed
        $payment->allocateToInvoice($this->invoice, $this->invoice->total);

        $this->assertEquals(110, $payment->allocated_amount);
        $this->assertEquals(90, $payment->unallocated_amount);
    }

    public function test_reallocating_payment_updates_invoice_statuses(): void
    {
        // Create two invoices
        $invoice1 = Invoice::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->subDays(10)->toDateString(),
            'due_date' => now()->subDays(5)->toDateString(),
            'status' => Invoice::STATUS_SENT,
        ]);

        $invoice1->items()->create([
            'description' => 'Service 1',
            'quantity' => 1,
            'unit_price' => 50,
            'tax_rate' => 10,
        ]);
        $invoice1->refresh();
        $invoice1->recalculateTotals();

        $invoice2 = Invoice::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->subDays(5)->toDateString(),
            'due_date' => now()->toDateString(),
            'status' => Invoice::STATUS_SENT,
        ]);

        $invoice2->items()->create([
            'description' => 'Service 2',
            'quantity' => 1,
            'unit_price' => 50,
            'tax_rate' => 10,
        ]);
        $invoice2->refresh();
        $invoice2->recalculateTotals();

        // Create payment
        $payment = Payment::create([
            'client_id' => $this->client->id,
            'amount' => 110,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'bank_transfer',
        ]);

        // Allocate to both
        $payment->allocateToInvoice($invoice1, 55);
        $payment->allocateToInvoice($invoice2, 55);

        $invoice1->refresh();
        $invoice2->refresh();
        $this->assertEquals(Invoice::STATUS_PAID, $invoice1->status);
        $this->assertEquals(Invoice::STATUS_PAID, $invoice2->status);

        // Remove allocation from invoice 1. invoice1's due_date is 5 days in
        // the past, so the correct post-removal status is OVERDUE (not SENT).
        $payment->removeAllocation($invoice1);

        $invoice1->refresh();
        $this->assertEquals(Invoice::STATUS_OVERDUE, $invoice1->status);
    }

    public function test_partial_payment_covers_multiple_invoices_correctly(): void
    {
        // Create three invoices of $100 each ($110 with GST)
        $invoice1 = Invoice::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->subDays(30)->toDateString(),
            'due_date' => now()->subDays(20)->toDateString(),
            'status' => Invoice::STATUS_SENT,
        ]);
        $invoice1->items()->create([
            'description' => 'Service 1',
            'quantity' => 1,
            'unit_price' => 100,
            'tax_rate' => 10,
        ]);

        $invoice2 = Invoice::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->subDays(20)->toDateString(),
            'due_date' => now()->subDays(10)->toDateString(),
            'status' => Invoice::STATUS_SENT,
        ]);
        $invoice2->items()->create([
            'description' => 'Service 2',
            'quantity' => 1,
            'unit_price' => 100,
            'tax_rate' => 10,
        ]);

        $invoice3 = Invoice::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->subDays(10)->toDateString(),
            'due_date' => now()->toDateString(),
            'status' => Invoice::STATUS_SENT,
        ]);
        $invoice3->items()->create([
            'description' => 'Service 3',
            'quantity' => 1,
            'unit_price' => 100,
            'tax_rate' => 10,
        ]);

        // Create payment of $220 (covers invoice1 $110 + invoice2 $110)
        $payment = Payment::create([
            'client_id' => $this->client->id,
            'amount' => 220,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'bank_transfer',
        ]);

        $payment->allocateToInvoice($invoice1, 110);
        $payment->allocateToInvoice($invoice2, 110);

        $invoice1->refresh();
        $invoice2->refresh();
        $invoice3->refresh();

        // First two should be paid, third should be unchanged
        $this->assertEquals(Invoice::STATUS_PAID, $invoice1->status);
        $this->assertEquals(Invoice::STATUS_PAID, $invoice2->status);
        $this->assertEquals(Invoice::STATUS_SENT, $invoice3->status);
    }

    public function test_payment_generates_payment_number_format(): void
    {
        $payment = Payment::create([
            'client_id' => $this->client->id,
            'amount' => 100,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'bank_transfer',
        ]);

        $this->assertMatchesRegularExpression('/^PAY-\d{4}-\d{4}$/', $payment->payment_number);
    }

    public function test_payment_can_be_voided(): void
    {
        $payment = Payment::create([
            'client_id' => $this->client->id,
            'amount' => 110,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'bank_transfer',
            'status' => Payment::STATUS_COMPLETED,
        ]);

        $this->assertTrue(method_exists($payment, 'void'));

        // Void the payment
        $payment->void();

        $this->assertEquals(Payment::STATUS_VOID, $payment->status);
    }

    public function test_credit_note_refund_payment_is_posted_to_ifrs(): void
    {
        $entity = $this->seedIfrs();

        $creditNote = \App\Models\CreditNote::create([
            'client_id' => $this->client->id,
            'total' => -60,
            'remaining_amount' => 60,
            'status' => \App\Models\CreditNote::STATUS_ISSUED,
        ]);

        $this->assertTrue($creditNote->applyToInvoice($this->invoice, 55));

        // The negative refund payment created by applyToInvoice() must be
        // posted (Cr Bank / Dr Revenue / Dr GST) without throwing on the
        // negative amount.
        $refund = $creditNote->refresh()->refund;
        $this->assertNotNull($refund->ifrs_receipt_id);
        $this->assertEquals(-55, (float) $refund->amount);

        $bank = \IFRS\Models\Account::where('code', 320)->first();
        $revenue = \IFRS\Models\Account::where('code', 4100)->first();
        $gst = \IFRS\Models\Account::where('code', 2200)->first();

        // Cr Bank 55, Dr Revenue 50 net, Dr GST 5.
        $this->assertEquals(55, (float) \IFRS\Models\Ledger::where('post_account', $bank->id)
            ->where('entry_type', \IFRS\Models\Balance::CREDIT)->sum('amount'));
        $this->assertEquals(50, (float) \IFRS\Models\Ledger::where('post_account', $revenue->id)
            ->where('entry_type', \IFRS\Models\Balance::DEBIT)->sum('amount')
            - \IFRS\Models\Ledger::where('post_account', $revenue->id)
            ->where('entry_type', \IFRS\Models\Balance::CREDIT)->sum('amount'));
        $this->assertEquals(5, (float) \IFRS\Models\Ledger::where('post_account', $gst->id)
            ->where('entry_type', \IFRS\Models\Balance::DEBIT)->sum('amount')
            - \IFRS\Models\Ledger::where('post_account', $gst->id)
            ->where('entry_type', \IFRS\Models\Balance::CREDIT)->sum('amount'));
    }

    public function test_allocation_groups_split_taxable_and_free_shares(): void
    {
        $invoice = Invoice::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => Invoice::STATUS_SENT,
        ]);
        $invoice->items()->createMany([
            ['description' => 'Consulting', 'quantity' => 1, 'unit_price' => 100, 'tax_rate' => 10], // $110 incl GST
            ['description' => 'Export service', 'quantity' => 1, 'unit_price' => 50, 'tax_rate' => 0], // $50 GST-free
            ['description' => 'Consulting 2', 'quantity' => 1, 'unit_price' => 200, 'tax_rate' => 10], // $220 incl GST
        ]);
        $invoice->refresh();
        $invoice->recalculateTotals();

        // A 20% allocation of the $380 total: taxable $66 + GST-free
        // $10 — the last item takes the remainder so shares sum exactly.
        $this->assertSame(['gst' => 6600, 'free' => 1000], Payment::allocationGroups($invoice, 76.0));
    }

    public function test_mixed_gst_invoice_payment_apportions_revenue_and_gst(): void
    {
        $this->seedIfrs();

        $invoice = Invoice::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => Invoice::STATUS_SENT,
        ]);
        $invoice->items()->createMany([
            ['description' => 'Consulting', 'quantity' => 1, 'unit_price' => 100, 'tax_rate' => 10], // $110 incl GST
            ['description' => 'Export service', 'quantity' => 1, 'unit_price' => 50, 'tax_rate' => 0], // $50 GST-free
        ]);
        $invoice->refresh();
        $invoice->recalculateTotals();

        $payment = Payment::create([
            'client_id' => $this->client->id,
            'amount' => 160,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'bank_transfer',
        ]);
        $payment->allocateToInvoice($invoice, 160);

        $this->assertNotNull($payment->postToIFRS());

        // Dr Bank 160; Cr Revenue 160 gross with the GST leg debiting 10
        // back out (net 150 = 100 net taxable + the full 50 GST-free
        // share); Cr GST Payable 10 — the GST-free share accrues no GST.
        $this->assertEquals(160, $this->ledgerSum(320, \IFRS\Models\Balance::DEBIT));
        $this->assertEquals(160, $this->ledgerSum(4100, \IFRS\Models\Balance::CREDIT));
        $this->assertEquals(10, $this->ledgerSum(4100, \IFRS\Models\Balance::DEBIT));
        $this->assertEquals(10, $this->ledgerSum(2200, \IFRS\Models\Balance::CREDIT));
        $this->assertEquals(0, $this->ledgerSum(2200, \IFRS\Models\Balance::DEBIT));
    }

    public function test_partially_allocated_payment_posts_remainder_gst_inclusive(): void
    {
        $this->seedIfrs();

        // The setUp invoice is $110 taxable. The payment allocates only
        // half of its $220; the unallocated remainder keeps the default
        // GST-inclusive treatment so the bank leg equals the payment.
        $payment = Payment::create([
            'client_id' => $this->client->id,
            'amount' => 220,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'bank_transfer',
        ]);
        $payment->allocateToInvoice($this->invoice, 110);

        $this->assertNotNull($payment->postToIFRS());

        $this->assertEquals(220, $this->ledgerSum(320, \IFRS\Models\Balance::DEBIT));
        $this->assertEquals(220, $this->ledgerSum(4100, \IFRS\Models\Balance::CREDIT)); // gross; net 200 after the GST leg
        $this->assertEquals(20, $this->ledgerSum(4100, \IFRS\Models\Balance::DEBIT));
        $this->assertEquals(20, $this->ledgerSum(2200, \IFRS\Models\Balance::CREDIT)); // 10 + 10
    }

    public function test_credit_note_refund_of_mixed_invoice_nets_ledger_to_zero(): void
    {
        $this->seedIfrs();

        $invoice = Invoice::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => Invoice::STATUS_SENT,
        ]);
        $invoice->items()->createMany([
            ['description' => 'Consulting', 'quantity' => 1, 'unit_price' => 100, 'tax_rate' => 10], // $110 incl GST
            ['description' => 'Export service', 'quantity' => 1, 'unit_price' => 50, 'tax_rate' => 0], // $50 GST-free
        ]);
        $invoice->refresh();
        $invoice->recalculateTotals();

        $payment = Payment::create([
            'client_id' => $this->client->id,
            'amount' => 160,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'bank_transfer',
        ]);
        $payment->allocateToInvoice($invoice, 160);
        $this->assertNotNull($payment->postToIFRS());

        $creditNote = \App\Models\CreditNote::create([
            'client_id' => $this->client->id,
            'total' => -160,
            'remaining_amount' => 160,
            'status' => \App\Models\CreditNote::STATUS_ISSUED,
        ]);

        // The refund allocates to the same mixed invoice, so it must
        // reverse the exact apportioned legs the receipt accrued.
        $this->assertTrue($creditNote->applyToInvoice($invoice, 160));

        foreach ([320, 4100, 2200] as $code) {
            $net = $this->ledgerSum($code, \IFRS\Models\Balance::DEBIT)
                - $this->ledgerSum($code, \IFRS\Models\Balance::CREDIT);
            $this->assertEquals(0, $net, "Account {$code} should net to zero after the refund.");
        }
    }

    /**
     * Sum posted ledger rows for one IFRS account code and entry side.
     */
    protected function ledgerSum(int $code, string $entryType): float
    {
        $account = \IFRS\Models\Account::where('code', $code)->first();

        return (float) \IFRS\Models\Ledger::where('post_account', $account->id)
            ->where('entry_type', $entryType)
            ->sum('amount');
    }

    /**
     * Seed the minimum IFRS prerequisites for receipt posting: currency,
     * entity + reporting period, bank (320), revenue (4100), GST Payable
     * (2200) and the GST 10% Vat. Mirrors BillPaymentModelTest::seedIfrs().
     */
    protected function seedIfrs(): \IFRS\Models\Entity
    {
        $entity = \IFRS\Models\Entity::create([
            'name' => 'Test Entity',
            'locale' => 'en_AU',
            'multi_currency' => false,
            'year_start' => 1,
        ]);

        $currency = \IFRS\Models\Currency::create([
            'name' => 'Australian Dollar',
            'currency_code' => 'AUD',
            'entity_id' => $entity->id,
        ]);
        $entity->update(['currency_id' => $currency->id]);
        $entity->refresh();

        \IFRS\Models\ReportingPeriod::create([
            'period_count' => 1,
            'calendar_year' => (int) date('Y'),
            'status' => \IFRS\Models\ReportingPeriod::OPEN,
            'entity_id' => $entity->id,
        ]);

        foreach ([
            ['Operating Account', \IFRS\Models\Account::BANK, 320],
            ['Consulting Revenue', \IFRS\Models\Account::OPERATING_REVENUE, 4100],
            ['GST Payable', \IFRS\Models\Account::CONTROL, 2200],
        ] as [$name, $type, $code]) {
            \IFRS\Models\Account::create([
                'name' => $name,
                'account_type' => $type,
                'code' => $code,
                'currency_id' => $currency->id,
                'entity_id' => $entity->id,
            ]);
        }

        $gstPayable = \IFRS\Models\Account::where('code', 2200)->first();
        \IFRS\Models\Vat::create([
            'name' => 'GST 10%',
            'code' => 'G',
            'rate' => 10,
            'account_id' => $gstPayable->id,
            'entity_id' => $entity->id,
        ]);

        return $entity;
    }

    public function test_payment_status_constants_are_defined(): void
    {
        $this->assertEquals('pending', Payment::STATUS_PENDING);
        $this->assertEquals('completed', Payment::STATUS_COMPLETED);
        $this->assertEquals('void', Payment::STATUS_VOID);
    }

    public function test_get_client_invoices_returns_outstanding_with_amount_due(): void
    {
        // The setUp invoice: 110 total, fully outstanding.
        $partial = Invoice::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => Invoice::STATUS_SENT,
        ]);
        $partial->items()->create([
            'description' => 'Partial Service',
            'quantity' => 1,
            'unit_price' => 200,
            'tax_rate' => 10,
        ]);
        $partial->refresh();
        $partial->recalculateTotals();

        $payment = Payment::create([
            'client_id' => $this->client->id,
            'amount' => 110,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'bank_transfer',
        ]);
        $payment->allocateToInvoice($partial, 110); // 220 total → 110 still due

        $draft = Invoice::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ]);
        // Balance-carrying drafts are returned too — flagged allocatable =>
        // false so the form can show them greyed-out with a "mark as sent
        // first" hint instead of them silently missing from the list.
        $draft->items()->create([
            'description' => 'Draft Service',
            'quantity' => 1,
            'unit_price' => 50,
            'tax_rate' => 10,
        ]);
        $draft->refresh();
        $draft->recalculateTotals();

        $response = $this->actingAs($this->user)
            ->get(route('payments.client-invoices', $this->client));

        $response->assertStatus(200);
        $invoices = collect($response->json())->keyBy('id');

        // amount_due drives the 100%-allocation default in the UI.
        $this->assertEquals(110.0, $invoices[$this->invoice->id]['amount_due']);
        $this->assertEquals(110.0, $invoices[$partial->id]['amount_due']);
        $this->assertEquals(220.0, $invoices[$partial->id]['total']);
        // The draft is visible but not selectable.
        $this->assertArrayHasKey($draft->id, $invoices);
        $this->assertFalse($invoices[$draft->id]['allocatable']);
        $this->assertEquals('draft', $invoices[$draft->id]['status']);
        $this->assertTrue($invoices[$this->invoice->id]['allocatable']);
        $this->assertTrue($invoices[$partial->id]['allocatable']);
    }

    public function test_store_with_paired_invoice_allocations_creates_payment_and_allocations(): void
    {
        // Regression: the create form used to submit invoice_allocations[][invoice_id]
        // and [][amount], which PHP never pairs into one row — validation
        // failed silently for every manual allocation. The form now submits
        // per-invoice indices; this is exactly that payload shape.
        $response = $this->actingAs($this->user)->post('/payments', [
            'client_id' => $this->client->id,
            'amount' => 110,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'bank_transfer',
            'allocate_type' => 'manual',
            'invoice_allocations' => [
                $this->invoice->id => [
                    'invoice_id' => $this->invoice->id,
                    'amount' => 110,
                ],
            ],
        ]);

        $response->assertSessionHas('success');
        $this->assertDatabaseHas('payments', [
            'client_id' => $this->client->id,
            'amount' => 110,
        ]);
        $this->assertDatabaseHas('payment_allocations', [
            'invoice_id' => $this->invoice->id,
            'amount' => 110,
        ]);
        $this->assertEquals(Invoice::STATUS_PAID, $this->invoice->refresh()->status);
    }

    public function test_store_manual_allocation_requires_at_least_one_invoice(): void
    {
        $response = $this->actingAs($this->user)->post('/payments', [
            'client_id' => $this->client->id,
            'amount' => 110,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'bank_transfer',
            'allocate_type' => 'manual',
        ]);

        $response->assertSessionHas('error');
        $this->assertDatabaseMissing('payments', ['client_id' => $this->client->id]);
    }

    public function test_store_rejects_allocation_to_draft_invoice(): void
    {
        $draft = Invoice::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ]);
        $draft->items()->create([
            'description' => 'Draft Service',
            'quantity' => 1,
            'unit_price' => 100,
            'tax_rate' => 10,
        ]);
        $draft->refresh();
        $draft->recalculateTotals();

        $response = $this->actingAs($this->user)->post('/payments', [
            'client_id' => $this->client->id,
            'amount' => 110,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'bank_transfer',
            'allocate_type' => 'manual',
            'invoice_allocations' => [
                $draft->id => ['invoice_id' => $draft->id, 'amount' => 110],
            ],
        ]);

        $response->assertSessionHas('error');
        $this->assertDatabaseMissing('payments', ['client_id' => $this->client->id]);
        $this->assertEquals(Invoice::STATUS_DRAFT, $draft->refresh()->status);
    }

    public function test_store_rejects_allocation_to_another_clients_invoice(): void
    {
        $otherClient = Client::factory()->create();
        $otherInvoice = Invoice::create([
            'client_id' => $otherClient->id,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => Invoice::STATUS_SENT,
        ]);
        $otherInvoice->items()->create([
            'description' => 'Other Client Service',
            'quantity' => 1,
            'unit_price' => 100,
            'tax_rate' => 10,
        ]);
        $otherInvoice->refresh();
        $otherInvoice->recalculateTotals();

        $response = $this->actingAs($this->user)->post('/payments', [
            'client_id' => $this->client->id,
            'amount' => 110,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'bank_transfer',
            'allocate_type' => 'manual',
            'invoice_allocations' => [
                $otherInvoice->id => ['invoice_id' => $otherInvoice->id, 'amount' => 110],
            ],
        ]);

        $response->assertSessionHas('error');
        $this->assertDatabaseMissing('payments', ['client_id' => $this->client->id]);
    }

    public function test_allocate_rejects_draft_invoice(): void
    {
        $payment = Payment::create([
            'client_id' => $this->client->id,
            'amount' => 110,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'bank_transfer',
        ]);
        $draft = Invoice::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ]);
        $draft->items()->create([
            'description' => 'Draft Service',
            'quantity' => 1,
            'unit_price' => 100,
            'tax_rate' => 10,
        ]);
        $draft->refresh();
        $draft->recalculateTotals();

        $response = $this->actingAs($this->user)
            ->post(route('payments.allocate', $payment), [
                'invoice_id' => $draft->id,
                'amount' => 110,
            ]);

        $response->assertSessionHas('error');
        $this->assertDatabaseMissing('payment_allocations', ['payment_id' => $payment->id]);
        $this->assertEquals(Invoice::STATUS_DRAFT, $draft->refresh()->status);
    }

    public function test_allocate_rejects_amount_exceeding_invoice_balance(): void
    {
        $payment = Payment::create([
            'client_id' => $this->client->id,
            'amount' => 500,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'bank_transfer',
        ]);

        // Invoice total is 110; allocating 200 must be rejected even though
        // the payment has plenty unallocated.
        $response = $this->actingAs($this->user)
            ->post(route('payments.allocate', $payment), [
                'invoice_id' => $this->invoice->id,
                'amount' => 200,
            ]);

        $response->assertSessionHas('error');
        $this->assertDatabaseMissing('payment_allocations', ['payment_id' => $payment->id]);
    }

    public function test_remove_all_allocations_frees_payment_and_reverts_invoice_statuses(): void
    {
        $second = Invoice::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => Invoice::STATUS_SENT,
        ]);
        $second->items()->create([
            'description' => 'Second Service',
            'quantity' => 1,
            'unit_price' => 100,
            'tax_rate' => 10,
        ]);
        $second->refresh();
        $second->recalculateTotals();

        $payment = Payment::create([
            'client_id' => $this->client->id,
            'amount' => 220,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'bank_transfer',
        ]);
        $payment->allocateToInvoice($this->invoice, 110);
        $payment->allocateToInvoice($second, 110);
        $payment->refresh();

        $response = $this->actingAs($this->user)
            ->post(route('payments.removeAllAllocations', $payment));

        $response->assertSessionHas('success');
        $this->assertDatabaseMissing('payment_allocations', ['payment_id' => $payment->id]);
        $payment->refresh();
        $this->assertEquals(0, $payment->allocated_amount);
        $this->assertEquals(220, $payment->unallocated_amount);
        // Invoices revert to their payable-but-unpaid status.
        $this->assertEquals(Invoice::STATUS_SENT, $this->invoice->refresh()->status);
        $this->assertEquals(Invoice::STATUS_SENT, $second->refresh()->status);
    }

    public function test_payment_unallocated_amount_calculated_correctly(): void
    {
        $payment = Payment::create([
            'client_id' => $this->client->id,
            'amount' => 200,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'bank_transfer',
        ]);

        $this->assertEquals(200, $payment->unallocated_amount);

        $payment->allocateToInvoice($this->invoice, 110);
        $payment->refresh();

        $this->assertEquals(110, $payment->allocated_amount);
        $this->assertEquals(90, $payment->unallocated_amount);
    }

    public function test_client_relationship_works(): void
    {
        $payment = Payment::create([
            'client_id' => $this->client->id,
            'amount' => 100,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'bank_transfer',
        ]);

        $this->assertEquals($this->client->id, $payment->client->id);
        $this->assertEquals($this->client->name, $payment->client->name);
    }

    public function test_create_with_unique_number_retries_on_duplicate(): void
    {
        // Occupy the number the generator would assign next so the first
        // insert inside createWithUniqueNumber() hits the unique constraint.
        // This mirrors the race where two concurrent requests compute the same
        // next number; the loser must regenerate and succeed.
        $collidingNumber = Payment::generatePaymentNumber();
        Payment::create([
            'client_id' => $this->client->id,
            'payment_number' => $collidingNumber,
            'amount' => 1,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'bank_transfer',
        ]);

        $payment = Payment::createWithUniqueNumber([
            'client_id' => $this->client->id,
            'amount' => 100,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'bank_transfer',
        ]);

        $this->assertNotNull($payment->id);
        $this->assertNotEquals($collidingNumber, $payment->payment_number);
        $this->assertMatchesRegularExpression('/^PAY-\d{4}-\d{4}$/', $payment->payment_number);

        // Both rows exist with distinct numbers.
        $this->assertEquals(2, Payment::where('client_id', $this->client->id)->count());
        $this->assertEquals(
            2,
            Payment::where('client_id', $this->client->id)->distinct()->count('payment_number')
        );
    }

    public function test_update_payment_rejects_amount_below_allocated(): void
    {
        $payment = Payment::create([
            'client_id' => $this->client->id,
            'amount' => 110,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'bank_transfer',
        ]);
        $payment->allocateToInvoice($this->invoice, 110);

        // Try to shrink the amount below what's already allocated.
        $response = $this->actingAs($this->user)->put("/payments/{$payment->id}", [
            'client_id' => $this->client->id,
            'amount' => 50,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'bank_transfer',
        ]);

        $response->assertSessionHas('error');
        $payment->refresh();
        $this->assertEquals(110, (float) $payment->amount, 'Amount must be unchanged.');
    }

    public function test_update_payment_rejects_client_change_with_allocations(): void
    {
        $otherClient = Client::factory()->create(['name' => 'Other Client']);

        $payment = Payment::create([
            'client_id' => $this->client->id,
            'amount' => 110,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'bank_transfer',
        ]);
        $payment->allocateToInvoice($this->invoice, 110);

        // Try to change the client while allocations to the original client's
        // invoice still exist.
        $response = $this->actingAs($this->user)->put("/payments/{$payment->id}", [
            'client_id' => $otherClient->id,
            'amount' => 110,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'bank_transfer',
        ]);

        $response->assertSessionHas('error');
        $payment->refresh();
        $this->assertEquals($this->client->id, $payment->client_id, 'Client must be unchanged.');
    }
}

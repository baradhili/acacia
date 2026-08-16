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
        ]); // draft — excluded

        $response = $this->actingAs($this->user)
            ->get(route('payments.client-invoices', $this->client));

        $response->assertStatus(200);
        $invoices = collect($response->json())->keyBy('id');

        // amount_due drives the 100%-allocation default in the UI.
        $this->assertEquals(110.0, $invoices[$this->invoice->id]['amount_due']);
        $this->assertEquals(110.0, $invoices[$partial->id]['amount_due']);
        $this->assertEquals(220.0, $invoices[$partial->id]['total']);
        $this->assertArrayNotHasKey($draft->id, $invoices);
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

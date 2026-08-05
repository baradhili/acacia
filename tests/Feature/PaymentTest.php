<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\PaymentAllocation;
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
            'allocate_type' => 'fifo',
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

    public function test_payment_allocates_to_invoice_using_fifo(): void
    {
        // Create another invoice
        $invoice2 = Invoice::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->subDays(5)->toDateString(),
            'due_date' => now()->subDays(3)->toDateString(),
            'status' => Invoice::STATUS_SENT,
        ]);

        $invoice2->items()->create([
            'description' => 'Test Service 2',
            'quantity' => 1,
            'unit_price' => 50,
            'tax_rate' => 10,
        ]);

        // Create payment of $100
        $payment = Payment::create([
            'client_id' => $this->client->id,
            'amount' => 100,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'bank_transfer',
        ]);

        // Allocate using FIFO
        $payment->allocateToInvoicesFIFO();

        // Should allocate to oldest invoice first (invoice2)
        $this->assertDatabaseHas('payment_allocations', [
            'payment_id' => $payment->id,
            'invoice_id' => $invoice2->id,
            'allocation_type' => 'fifo',
        ]);
    }

    public function test_partial_payment_allocates_correctly(): void
    {
        $payment = Payment::create([
            'client_id' => $this->client->id,
            'amount' => 55,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'bank_transfer',
        ]);

        $payment->allocateToInvoice($this->invoice, 55);

        $this->assertEquals(55, $payment->allocated_amount);
        $this->assertEquals(55, $payment->unallocated_amount);
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

        // Try to allocate more than payment amount
        $payment->allocateToInvoice($this->invoice, 100);

        // Should only allocate 50 (the payment amount)
        $allocation = PaymentAllocation::where('payment_id', $payment->id)->first();
        $this->assertEquals(50, $allocation->amount);
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

        // Manually allocate to invoice2 (ignoring FIFO)
        $payment->allocateToInvoice($invoice2, 55, 'manual');

        $this->assertDatabaseHas('payment_allocations', [
            'payment_id' => $payment->id,
            'invoice_id' => $invoice2->id,
            'allocation_type' => 'manual',
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
}

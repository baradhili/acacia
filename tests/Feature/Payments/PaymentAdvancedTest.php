<?php

namespace Tests\Feature;

use App\Mail\PaymentReceiptMail;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PaymentAdvancedTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->client = Client::factory()->create([
            'name' => 'Test Client',
            'email' => 'client@test.com',
        ]);
    }

    protected function createInvoiceWithAmount(float $amount): Invoice
    {
        $invoice = Invoice::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => Invoice::STATUS_SENT,
        ]);

        $invoice->items()->create([
            'description' => 'Test Service',
            'quantity' => 1,
            'unit_price' => $amount / 1.1, // Excluding GST
            'tax_rate' => 10,
        ]);

        $invoice->refresh();
        return $invoice;
    }

    public function test_payment_receipt_email_is_sent_to_client(): void
    {
        Mail::fake();

        $invoice = $this->createInvoiceWithAmount(110);

        $response = $this->actingAs($this->user)->post('/payments', [
            'client_id' => $this->client->id,
            'amount' => 110,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'bank_transfer',
            'allocate_type' => 'fifo',
            'invoice_allocations' => [
                ['invoice_id' => $invoice->id, 'amount' => 110],
            ],
        ]);

        $response->assertSessionHas('success');

        // Verify receipt email was sent
        Mail::assertSent(PaymentReceiptMail::class, function ($mail) {
            return $mail->payment->client->email === 'client@test.com';
        });
    }

    public function test_allocation_handles_payment_exceeding_total_outstanding(): void
    {
        // Create two invoices totalling $165
        $invoice1 = $this->createInvoiceWithAmount(110);
        $invoice2 = $this->createInvoiceWithAmount(55);

        // Payment of $200 (exceeds total outstanding of $165)
        $payment = Payment::create([
            'client_id' => $this->client->id,
            'amount' => 200,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'bank_transfer',
        ]);

        // Allocate using FIFO
        $payment->allocateToInvoicesFIFO();

        $payment->refresh();

        // Should allocate all $165 to invoices
        $this->assertEquals(165, $payment->allocated_amount);
        // $35 should remain unallocated (client credit)
        $this->assertEquals(35, $payment->unallocated_amount);

        // Verify allocations
        $this->assertCount(2, $payment->allocations);
    }

    public function test_removing_allocation_updates_unallocated_amount(): void
    {
        $invoice = $this->createInvoiceWithAmount(110);

        // Create payment and allocate
        $payment = Payment::create([
            'client_id' => $this->client->id,
            'amount' => 110,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'bank_transfer',
        ]);

        $payment->allocateToInvoice($invoice, 110);

        $payment->refresh();
        $this->assertEquals(110, $payment->allocated_amount);
        $this->assertEquals(0, $payment->unallocated_amount);

        // Remove allocation
        $payment->removeAllocation($invoice);

        $payment->refresh();
        $this->assertEquals(0, $payment->allocated_amount);
        $this->assertEquals(110, $payment->unallocated_amount);
    }

    public function test_fifo_allocation_allocates_to_oldest_invoice_first(): void
    {
        // Create older invoice (should be paid first)
        $oldInvoice = Invoice::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->subDays(10)->toDateString(),
            'due_date' => now()->subDays(5)->toDateString(),
            'status' => Invoice::STATUS_SENT,
        ]);
        $oldInvoice->items()->create([
            'description' => 'Old Service',
            'quantity' => 1,
            'unit_price' => 50,
            'tax_rate' => 0,
        ]);

        // Create newer invoice
        $newInvoice = Invoice::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->subDays(5)->toDateString(),
            'due_date' => now()->addDays(5)->toDateString(),
            'status' => Invoice::STATUS_SENT,
        ]);
        $newInvoice->items()->create([
            'description' => 'New Service',
            'quantity' => 1,
            'unit_price' => 50,
            'tax_rate' => 0,
        ]);

        // Create payment
        $payment = Payment::create([
            'client_id' => $this->client->id,
            'amount' => 50,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'bank_transfer',
        ]);

        // Allocate using FIFO
        $payment->allocateToInvoicesFIFO();

        // Should allocate to oldest invoice first
        $this->assertDatabaseHas('payment_allocations', [
            'payment_id' => $payment->id,
            'invoice_id' => $oldInvoice->id,
            'allocation_type' => 'fifo',
        ]);
    }

    public function test_partial_payment_covers_multiple_invoices_correctly_using_fifo(): void
    {
        // Create invoices totalling $275
        $invoice1 = $this->createInvoiceWithAmount(110);
        $invoice2 = $this->createInvoiceWithAmount(165);

        // Payment of $165
        $payment = Payment::create([
            'client_id' => $this->client->id,
            'amount' => 165,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'bank_transfer',
        ]);

        // Allocate using FIFO
        $payment->allocateToInvoicesFIFO();

        // First invoice should be fully paid ($110)
        $invoice1->refresh();
        $this->assertEquals(Invoice::STATUS_PAID, $invoice1->status);

        // Second invoice should be partially paid ($55 remaining)
        $invoice2->refresh();
        $this->assertEquals(Invoice::STATUS_PARTIALLY_PAID, $invoice2->status);

        // Verify allocation amounts
        $allocations = PaymentAllocation::where('payment_id', $payment->id)
            ->orderBy('created_at')
            ->get();

        $this->assertEquals(110, $allocations->first()->amount); // First invoice
        $this->assertEquals(55, $allocations->last()->amount); // Second invoice partial
    }

    public function test_payment_generates_unique_payment_number(): void
    {
        $payment1 = Payment::create([
            'client_id' => $this->client->id,
            'amount' => 100,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'bank_transfer',
        ]);

        $payment2 = Payment::create([
            'client_id' => $this->client->id,
            'amount' => 200,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'bank_transfer',
        ]);

        $this->assertNotEquals($payment1->payment_number, $payment2->payment_number);
        $this->assertMatchesRegularExpression('/^PAY-' . date('Y') . '-\d{4}$/', $payment2->payment_number);
    }

    public function test_payment_unallocated_amount_calculation(): void
    {
        $payment = Payment::create([
            'client_id' => $this->client->id,
            'amount' => 100,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'bank_transfer',
        ]);

        $this->assertEquals(100, $payment->unallocated_amount);

        // Allocate $30
        $invoice = $this->createInvoiceWithAmount(110);
        $payment->allocateToInvoice($invoice, 30);

        $payment->refresh();
        $this->assertEquals(70, $payment->unallocated_amount);
        $this->assertEquals(30, $payment->allocated_amount);
    }
}

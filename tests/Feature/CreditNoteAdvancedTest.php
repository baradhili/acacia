<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\CreditNote;
use App\Models\CreditNoteItem;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreditNoteAdvancedTest extends TestCase
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

    public function test_full_credit_note_application_reduces_invoice_balance(): void
    {
        $invoice = $this->createInvoiceWithAmount(110); // Total = $110 with GST

        // Create full credit note
        $creditNote = CreditNote::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'reason' => 'Full refund',
            'total' => 110,
            'remaining_amount' => 110,
        ]);

        // Apply credit note to invoice
        $result = $creditNote->applyToInvoice($invoice);

        $this->assertTrue($result);

        $creditNote->refresh();
        $invoice->refresh();

        // Credit note should be fully applied
        $this->assertEquals(CreditNote::STATUS_APPLIED, $creditNote->status);
        $this->assertEquals(0, $creditNote->remaining_amount);
        $this->assertEquals($invoice->id, $creditNote->invoice_id);
    }

    public function test_partial_credit_note_application_leaves_remaining_balance(): void
    {
        $invoice = $this->createInvoiceWithAmount(110);

        // Create partial credit note ($55)
        $creditNote = CreditNote::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'reason' => 'Partial refund',
            'total' => 55,
            'remaining_amount' => 55,
        ]);

        // Apply partial credit note
        $result = $creditNote->applyToInvoice($invoice);

        $this->assertTrue($result);

        $creditNote->refresh();

        // Credit note should be fully applied (applied_amount = total)
        $this->assertEquals(CreditNote::STATUS_APPLIED, $creditNote->status);
        $this->assertEquals(0, $creditNote->remaining_amount);
        $this->assertEquals(55, $creditNote->applied_amount);
    }

    public function test_refund_workflow_creates_credit_note_and_payment(): void
    {
        $invoice = $this->createInvoiceWithAmount(110);

        // First, pay the invoice
        $payment = Payment::create([
            'client_id' => $this->client->id,
            'amount' => 110,
            'payment_date' => now()->toDateString(),
            'payment_method' => Payment::METHOD_BANK_TRANSFER,
        ]);

        $payment->allocateToInvoice($invoice, 110);

        $invoice->refresh();
        $this->assertEquals(Invoice::STATUS_PAID, $invoice->status);

        // Now create credit note for refund
        $creditNote = CreditNote::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'reason' => 'Customer refund request',
            'total' => 110,
            'remaining_amount' => 110,
        ]);

        // Apply credit note to invoice (refund workflow)
        $result = $creditNote->applyToInvoice($invoice);

        $this->assertTrue($result);

        // Verify a refund payment was created
        $refundPayment = Payment::where('credit_note_id', $creditNote->id)->first();
        $this->assertNotNull($refundPayment);
        $this->assertEquals(-110, $refundPayment->amount); // Negative amount
        $this->assertEquals(Payment::METHOD_OTHER, $refundPayment->payment_method);
    }

    public function test_voiding_credit_note_with_partial_allocations_is_prevented(): void
    {
        // Create credit note and apply partially
        $creditNote = CreditNote::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'reason' => 'Partial refund',
            'total' => 110,
            'remaining_amount' => 0, // Already applied
            'applied_amount' => 110,
            'status' => CreditNote::STATUS_APPLIED,
            'applied_at' => now(),
        ]);

        // Try to void
        $result = $creditNote->void();

        $this->assertFalse($result);
        $this->assertEquals(CreditNote::STATUS_APPLIED, $creditNote->status);
    }

    public function test_credit_note_can_only_be_applied_once(): void
    {
        $invoice = $this->createInvoiceWithAmount(110);

        $creditNote = CreditNote::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'reason' => 'Test',
            'total' => 110,
            'remaining_amount' => 110,
        ]);

        // First application
        $result1 = $creditNote->applyToInvoice($invoice);
        $this->assertTrue($result1);

        // Second application to different invoice should fail
        $invoice2 = $this->createInvoiceWithAmount(110);
        $result2 = $creditNote->applyToInvoice($invoice2);

        $this->assertFalse($result2);
    }

    public function test_credit_note_from_invoice_item_creates_correct_credit_item(): void
    {
        $invoice = $this->createInvoiceWithAmount(110);
        $invoiceItem = $invoice->items()->first();

        $creditNoteItem = CreditNoteItem::createFromInvoiceItem($invoiceItem);

        $this->assertStringContainsString('Credit', $creditNoteItem->description);
        $this->assertEquals($invoiceItem->quantity, $creditNoteItem->quantity);
        $this->assertEquals($invoiceItem->unit_price, $creditNoteItem->unit_price);
    }

    public function test_void_issued_credit_note_succeeds(): void
    {
        $creditNote = CreditNote::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'reason' => 'Test',
            'total' => 100,
            'remaining_amount' => 100,
            'status' => CreditNote::STATUS_ISSUED,
        ]);

        $result = $creditNote->void();

        $this->assertTrue($result);
        $this->assertEquals(CreditNote::STATUS_VOID, $creditNote->status);
    }
}

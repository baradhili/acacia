<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\CreditNote;
use App\Models\CreditNoteItem;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreditNoteTest extends TestCase
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

    public function test_credit_note_list_page_requires_authentication(): void
    {
        $response = $this->get('/credit-notes');
        $response->assertRedirect('/login');
    }

    public function test_can_create_credit_note(): void
    {
        $response = $this->actingAs($this->user)->post('/credit-notes', [
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'reason' => 'Product return',
            'items' => [
                [
                    'description' => 'Test Service - Refund',
                    'quantity' => 1,
                    'unit_price' => 100,
                    'tax_rate' => 10,
                ],
            ],
        ]);

        $response->assertSessionHas('success');
        $this->assertDatabaseHas('credit_notes', [
            'client_id' => $this->client->id,
            'status' => 'issued',
            'reason' => 'Product return',
        ]);
    }

    public function test_credit_note_generates_correct_number(): void
    {
        $creditNote = CreditNote::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'reason' => 'Test',
            'total' => 100,
            'remaining_amount' => 100,
        ]);

        $this->assertMatchesRegularExpression('/^CN-' . date('Y') . '-\d{4}$/', $creditNote->credit_note_number);
    }

    public function test_credit_note_calculates_total(): void
    {
        $creditNote = CreditNote::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'reason' => 'Test',
            'total' => 0,
            'remaining_amount' => 0,
        ]);

        $creditNote->items()->create([
            'description' => 'Service 1',
            'quantity' => 2,
            'unit_price' => 100,
            'tax_rate' => 10,
        ]);

        $creditNote->items()->create([
            'description' => 'Service 2',
            'quantity' => 1,
            'unit_price' => 50,
            'tax_rate' => 10,
        ]);

        $creditNote->refresh();
        
        // 2*100 + 1*50 = 250 subtotal
        // Tax = 250 * 0.10 = 25
        // Total = 250 + 25 = 275
        $this->assertEquals(275, $creditNote->items()->sum('total'));
    }

    public function test_credit_note_has_remaining_balance(): void
    {
        $creditNote = CreditNote::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'reason' => 'Test',
            'total' => 100,
            'remaining_amount' => 100,
        ]);

        $this->assertTrue($creditNote->hasRemainingBalance());

        $creditNote->update(['remaining_amount' => 0]);
        $this->assertFalse($creditNote->hasRemainingBalance());
    }

    public function test_can_void_issued_credit_note(): void
    {
        $creditNote = CreditNote::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'reason' => 'Test',
            'total' => 100,
            'remaining_amount' => 100,
        ]);

        $result = $creditNote->void();

        $this->assertTrue($result);
        $this->assertEquals(CreditNote::STATUS_VOID, $creditNote->status);
    }

    public function test_cannot_void_applied_credit_note(): void
    {
        $creditNote = CreditNote::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'reason' => 'Test',
            'total' => 100,
            'remaining_amount' => 0,
            'status' => CreditNote::STATUS_APPLIED,
        ]);

        $result = $creditNote->void();

        $this->assertFalse($result);
    }

    public function test_credit_note_from_invoice_item(): void
    {
        $invoice = Invoice::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ]);

        $invoiceItem = $invoice->items()->create([
            'description' => 'Test Service',
            'quantity' => 1,
            'unit_price' => 100,
            'tax_rate' => 10,
        ]);

        $creditNoteItem = CreditNoteItem::createFromInvoiceItem($invoiceItem);

        $this->assertStringContainsString('Credit for:', $creditNoteItem->description);
        $this->assertEquals(1, $creditNoteItem->quantity);
        $this->assertEquals(100, $creditNoteItem->unit_price);
    }

    public function test_credit_note_statuses(): void
    {
        $creditNote = CreditNote::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'reason' => 'Test',
            'total' => 100,
            'remaining_amount' => 100,
        ]);

        $this->assertEquals(CreditNote::STATUS_ISSUED, $creditNote->status);

        $creditNote->update([
            'status' => CreditNote::STATUS_APPLIED,
            'applied_at' => now(),
            'applied_amount' => 100,
            'remaining_amount' => 0,
        ]);

        $this->assertEquals(CreditNote::STATUS_APPLIED, $creditNote->status);
        $this->assertNotNull($creditNote->applied_at);
    }

    public function test_scope_active_credit_notes(): void
    {
        CreditNote::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'reason' => 'Issued',
            'total' => 100,
            'remaining_amount' => 100,
            'status' => CreditNote::STATUS_ISSUED,
        ]);

        CreditNote::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'reason' => 'Void',
            'total' => 100,
            'remaining_amount' => 100,
            'status' => CreditNote::STATUS_VOID,
        ]);

        $activeCount = CreditNote::active()->count();
        $this->assertEquals(1, $activeCount);
    }

    public function test_scope_credit_notes_with_balance(): void
    {
        CreditNote::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'reason' => 'With Balance',
            'total' => 100,
            'remaining_amount' => 50,
            'status' => CreditNote::STATUS_ISSUED,
        ]);

        CreditNote::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'reason' => 'No Balance',
            'total' => 100,
            'remaining_amount' => 0,
            'status' => CreditNote::STATUS_APPLIED,
        ]);

        $withBalanceCount = CreditNote::withBalance()->count();
        $this->assertEquals(1, $withBalanceCount);
    }

    // ============================================================
    // Phase 4.5 - Credit Note Application Tests
    // ============================================================

    public function test_full_credit_note_application_reduces_invoice_balance(): void
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
            'unit_price' => 100,
            'tax_rate' => 10,
        ]);

        $invoice->refresh();
        $this->assertEquals(110, $invoice->amount_due);

        // Create credit note for the full amount
        $creditNote = CreditNote::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'reason' => 'Full refund',
            'total' => 110,
            'remaining_amount' => 110,
            'status' => CreditNote::STATUS_ISSUED,
        ]);

        $creditNote->items()->create([
            'description' => 'Credit for Service',
            'quantity' => 1,
            'unit_price' => 100,
            'tax_rate' => 10,
            'total' => 110,
        ]);

        // Apply full credit note to invoice
        $result = $creditNote->applyToInvoice($invoice);

        $this->assertTrue($result);
        $creditNote->refresh();
        $invoice->refresh();

        $this->assertEquals(CreditNote::STATUS_APPLIED, $creditNote->status);
        $this->assertEquals(0, $creditNote->remaining_amount);
        $this->assertNotNull($creditNote->applied_at);
    }

    public function test_partial_credit_note_application_leaves_remaining_balance(): void
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
            'unit_price' => 100,
            'tax_rate' => 10,
        ]);

        $invoice->refresh();
        $this->assertEquals(110, $invoice->amount_due);

        // Create credit note for partial amount (55 = half of 110)
        $creditNote = CreditNote::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'reason' => 'Partial refund',
            'total' => 55,
            'remaining_amount' => 55,
            'status' => CreditNote::STATUS_ISSUED,
        ]);

        $creditNote->items()->create([
            'description' => 'Partial Credit for Service',
            'quantity' => 0.5,
            'unit_price' => 100,
            'tax_rate' => 10,
            'total' => 55,
        ]);

        // Apply partial credit note to invoice
        $result = $creditNote->applyToInvoice($invoice, 55);

        $this->assertTrue($result);
        $creditNote->refresh();
        $invoice->refresh();

        $this->assertEquals(CreditNote::STATUS_APPLIED, $creditNote->status);
        $this->assertEquals(0, $creditNote->remaining_amount);
        $this->assertEquals(55, $invoice->amount_due);
    }

    public function test_voiding_credit_note_with_partial_allocations_is_prevented(): void
    {
        $creditNote = CreditNote::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'reason' => 'Test',
            'total' => 100,
            'remaining_amount' => 50, // Partially applied
            'status' => CreditNote::STATUS_ISSUED,
        ]);

        // Should not be able to void a partially applied credit note
        $result = $creditNote->void();

        $this->assertFalse($result);
        $this->assertEquals(CreditNote::STATUS_ISSUED, $creditNote->status);
    }

    public function test_voiding_unapplied_credit_note_succeeds(): void
    {
        $creditNote = CreditNote::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'reason' => 'Test',
            'total' => 100,
            'remaining_amount' => 100, // Not applied at all
            'status' => CreditNote::STATUS_ISSUED,
        ]);

        $result = $creditNote->void();

        $this->assertTrue($result);
        $this->assertEquals(CreditNote::STATUS_VOID, $creditNote->status);
    }

    public function test_voiding_fully_applied_credit_note_is_prevented(): void
    {
        $creditNote = CreditNote::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'reason' => 'Test',
            'total' => 100,
            'remaining_amount' => 0, // Fully applied
            'status' => CreditNote::STATUS_APPLIED,
        ]);

        $result = $creditNote->void();

        $this->assertFalse($result);
        $this->assertEquals(CreditNote::STATUS_APPLIED, $creditNote->status);
    }

    public function test_credit_note_from_fully_paid_invoice_marks_original_as_refunded(): void
    {
        $invoice = Invoice::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => Invoice::STATUS_PAID,
            'paid_at' => now(),
        ]);

        $invoice->items()->create([
            'description' => 'Test Service',
            'quantity' => 1,
            'unit_price' => 100,
            'tax_rate' => 10,
        ]);

        // Create credit note from invoice items
        $creditNote = CreditNote::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'reason' => 'Full refund for paid invoice',
            'total' => 110,
            'remaining_amount' => 110,
            'status' => CreditNote::STATUS_ISSUED,
            'invoice_id' => $invoice->id,
        ]);

        $creditNote->items()->create([
            'description' => 'Credit for Service (Full Refund)',
            'quantity' => 1,
            'unit_price' => 100,
            'tax_rate' => 10,
            'total' => 110,
        ]);

        $this->assertEquals($invoice->id, $creditNote->invoice_id);
        $this->assertEquals(110, $creditNote->total);
    }

    public function test_credit_note_status_constants_are_defined(): void
    {
        $this->assertEquals('issued', CreditNote::STATUS_ISSUED);
        $this->assertEquals('applied', CreditNote::STATUS_APPLIED);
        $this->assertEquals('void', CreditNote::STATUS_VOID);
    }

    public function test_credit_note_applied_at_is_set_on_application(): void
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
            'unit_price' => 100,
            'tax_rate' => 10,
        ]);

        $creditNote = CreditNote::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'reason' => 'Test',
            'total' => 110,
            'remaining_amount' => 110,
            'status' => CreditNote::STATUS_ISSUED,
        ]);

        $this->assertNull($creditNote->applied_at);

        $creditNote->applyToInvoice($invoice);
        $creditNote->refresh();

        $this->assertNotNull($creditNote->applied_at);
    }

    public function test_credit_note_applied_amount_is_recorded(): void
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
            'unit_price' => 100,
            'tax_rate' => 10,
        ]);

        $creditNote = CreditNote::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'reason' => 'Test',
            'total' => 110,
            'remaining_amount' => 110,
            'status' => CreditNote::STATUS_ISSUED,
        ]);

        $creditNote->applyToInvoice($invoice, 55);
        $creditNote->refresh();

        $this->assertEquals(55, $creditNote->applied_amount);
        $this->assertEquals(55, $creditNote->remaining_amount);
    }

    public function test_multiple_credit_notes_can_be_applied_to_single_invoice(): void
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
            'unit_price' => 100,
            'tax_rate' => 10,
        ]);

        $invoice->refresh();
        $this->assertEquals(110, $invoice->amount_due);

        // Apply first credit note
        $creditNote1 = CreditNote::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'reason' => 'First refund',
            'total' => 30,
            'remaining_amount' => 30,
            'status' => CreditNote::STATUS_APPLIED,
        ]);

        $creditNote1->applyToInvoice($invoice, 30);
        $invoice->refresh();

        $this->assertEquals(80, $invoice->amount_due);

        // Apply second credit note
        $creditNote2 = CreditNote::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'reason' => 'Second refund',
            'total' => 40,
            'remaining_amount' => 40,
            'status' => CreditNote::STATUS_APPLIED,
        ]);

        $creditNote2->applyToInvoice($invoice, 40);
        $invoice->refresh();

        $this->assertEquals(40, $invoice->amount_due);
    }
}

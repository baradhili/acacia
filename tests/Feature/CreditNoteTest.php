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
}

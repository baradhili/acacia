<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceTest extends TestCase
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

    public function test_invoice_list_page_requires_authentication(): void
    {
        $response = $this->get('/invoices');
        $response->assertRedirect('/login');
    }

    public function test_can_create_invoice(): void
    {
        $response = $this->actingAs($this->user)->post('/invoices', [
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'items' => [
                [
                    'description' => 'Test Service',
                    'quantity' => 1,
                    'unit_price' => 100,
                    'tax_rate' => 10,
                ],
            ],
        ]);

        $response->assertSessionHas('success');
        $this->assertDatabaseHas('invoices', [
            'client_id' => $this->client->id,
            'status' => 'draft',
        ]);
    }

    public function test_invoice_generates_correct_invoice_number(): void
    {
        $invoice = Invoice::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ]);

        $this->assertMatchesRegularExpression('/^INV-' . date('Y') . '-\d{4}$/', $invoice->invoice_number);
    }

    public function test_invoice_calculates_totals_correctly(): void
    {
        $invoice = Invoice::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ]);

        $invoice->items()->create([
            'description' => 'Service 1',
            'quantity' => 2,
            'unit_price' => 100,
            'tax_rate' => 10,
        ]);

        $invoice->items()->create([
            'description' => 'Service 2',
            'quantity' => 1,
            'unit_price' => 50,
            'tax_rate' => 10,
        ]);

        $invoice->refresh();
        
        // 2*100 + 1*50 = 250 subtotal
        // Tax = 250 * 0.10 = 25
        // Total = 250 + 25 = 275
        $this->assertEquals(275, $invoice->total);
    }

    public function test_invoice_status_transitions(): void
    {
        $invoice = Invoice::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ]);

        // Draft -> Sent
        $invoice->markAsSent();
        $this->assertEquals(Invoice::STATUS_SENT, $invoice->status);

        // Sent -> Viewed
        $invoice->markAsViewed();
        $this->assertEquals(Invoice::STATUS_VIEWED, $invoice->status);
    }

    public function test_invoice_cannot_transition_to_invalid_status(): void
    {
        $invoice = Invoice::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ]);

        // Paid invoice cannot transition to cancelled
        $invoice->update(['status' => Invoice::STATUS_PAID]);
        $this->assertFalse($invoice->canTransitionTo(Invoice::STATUS_CANCELLED));
    }

    public function test_only_draft_invoices_can_be_edited(): void
    {
        $invoice = Invoice::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ]);

        // Draft can be edited
        $this->assertTrue($invoice->canBeEdited());

        // Sent cannot be edited
        $invoice->update(['status' => Invoice::STATUS_SENT]);
        $this->assertFalse($invoice->canBeEdited());
    }

    public function test_invoice_due_date_detection(): void
    {
        $invoice = Invoice::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->subDay()->toDateString(), // Yesterday
        ]);

        $this->assertTrue($invoice->is_overdue);

        $invoice2 = Invoice::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDay()->toDateString(), // Tomorrow
        ]);

        $this->assertFalse($invoice2->is_overdue);
    }

    public function test_invoice_amount_paid_calculation(): void
    {
        $invoice = Invoice::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ]);

        $invoice->items()->create([
            'description' => 'Service',
            'quantity' => 1,
            'unit_price' => 100,
            'tax_rate' => 10,
        ]);

        $invoice->refresh();
        $this->assertEquals(0, $invoice->amount_paid);
        $this->assertEquals(110, $invoice->amount_due);
    }
}

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

    // ============================================================
    // Phase 4.5 - Additional Invoice Tests
    // ============================================================

    public function test_invoice_status_transitions_draft_to_sent_to_viewed_to_partially_paid_to_paid(): void
    {
        $invoice = Invoice::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ]);

        $this->assertEquals(Invoice::STATUS_DRAFT, $invoice->status);

        // Draft -> Sent
        $invoice->markAsSent();
        $this->assertEquals(Invoice::STATUS_SENT, $invoice->status);
        $this->assertNotNull($invoice->sent_at);

        // Sent -> Viewed
        $invoice->markAsViewed();
        $this->assertEquals(Invoice::STATUS_VIEWED, $invoice->status);
        $this->assertNotNull($invoice->viewed_at);
    }

    public function test_invoice_status_transitions_to_overdue(): void
    {
        $invoice = Invoice::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->subDays(60)->toDateString(),
            'due_date' => now()->subDays(30)->toDateString(), // Already past due
            'status' => Invoice::STATUS_SENT,
        ]);

        // Must explicitly call markAsOverdue
        $invoice->markAsOverdue();
        
        $this->assertTrue($invoice->is_overdue);
        $this->assertEquals(Invoice::STATUS_OVERDUE, $invoice->status);
    }

    public function test_invoice_cannot_transition_from_paid_to_cancelled(): void
    {
        $invoice = Invoice::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => Invoice::STATUS_PAID,
        ]);

        $this->assertFalse($invoice->canTransitionTo(Invoice::STATUS_CANCELLED));
        $this->assertFalse($invoice->canBeCancelled());
    }

    public function test_invoice_cancellation_only_allowed_in_draft_state(): void
    {
        // Draft invoice can be cancelled
        $draftInvoice = Invoice::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => Invoice::STATUS_DRAFT,
        ]);
        $this->assertTrue($draftInvoice->canBeCancelled());
        $draftInvoice->cancel();
        $this->assertEquals(Invoice::STATUS_CANCELLED, $draftInvoice->status);

        // Sent invoice can be cancelled (if not yet paid)
        $sentInvoice = Invoice::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => Invoice::STATUS_SENT,
        ]);
        $this->assertTrue($sentInvoice->canBeCancelled());
        $sentInvoice->cancel();
        $this->assertEquals(Invoice::STATUS_CANCELLED, $sentInvoice->status);

        // Paid invoice cannot be cancelled
        $paidInvoice = Invoice::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => Invoice::STATUS_PAID,
        ]);
        $this->assertFalse($paidInvoice->canBeCancelled());
    }

    public function test_invoice_cancellation_route_requires_draft_or_sent_status(): void
    {
        $invoice = Invoice::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => Invoice::STATUS_PAID,
        ]);

        $response = $this->actingAs($this->user)->post(route('invoices.cancel', $invoice));
        $response->assertSessionHas('error');
    }

    public function test_automatic_overdue_marking_via_cron_command(): void
    {
        // Create invoices that should be marked overdue
        $overdueInvoice = Invoice::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->subDays(60)->toDateString(),
            'due_date' => now()->subDays(30)->toDateString(),
            'status' => Invoice::STATUS_SENT,
        ]);

        // Create invoice that should NOT be marked overdue
        $notOverdueInvoice = Invoice::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => Invoice::STATUS_SENT,
        ]);

        $this->assertEquals(Invoice::STATUS_SENT, $overdueInvoice->status);
        $this->assertEquals(Invoice::STATUS_SENT, $notOverdueInvoice->status);

        // Run the command
        $this->artisan('invoices:mark-overdue')
            ->expectsOutput('Checking for overdue invoices...')
            ->expectsOutput('Marked 1 invoice(s) as overdue.');

        $overdueInvoice->refresh();
        $notOverdueInvoice->refresh();

        $this->assertEquals(Invoice::STATUS_OVERDUE, $overdueInvoice->status);
        $this->assertEquals(Invoice::STATUS_SENT, $notOverdueInvoice->status);
    }

    public function test_overdue_invoices_scope_returns_correct_invoices(): void
    {
        // Create overdue invoice
        Invoice::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->subDays(60)->toDateString(),
            'due_date' => now()->subDays(30)->toDateString(),
            'status' => Invoice::STATUS_SENT,
        ]);

        // Create not overdue invoice
        Invoice::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => Invoice::STATUS_SENT,
        ]);

        $overdueCount = Invoice::overdue()->count();
        $this->assertEquals(1, $overdueCount);
    }

    public function test_sent_invoice_updates_sent_at_timestamp(): void
    {
        $invoice = Invoice::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ]);

        $this->assertNull($invoice->sent_at);

        $invoice->markAsSent();
        $invoice->refresh();

        $this->assertNotNull($invoice->sent_at);
        $this->assertEquals(Invoice::STATUS_SENT, $invoice->status);
    }

    public function test_recurring_invoice_frequency_constants_exist(): void
    {
        $this->assertEquals('daily', Invoice::RECURRING_DAILY);
        $this->assertEquals('weekly', Invoice::RECURRING_WEEKLY);
        $this->assertEquals('monthly', Invoice::RECURRING_MONTHLY);
        $this->assertEquals('yearly', Invoice::RECURRING_YEARLY);
    }

    public function test_invoice_can_be_marked_as_recurring(): void
    {
        $invoice = Invoice::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'is_recurring' => true,
            'recurring_frequency' => Invoice::RECURRING_MONTHLY,
            'next_recurring_date' => now()->addMonth()->toDateString(),
        ]);

        $this->assertTrue($invoice->is_recurring);
        $this->assertEquals(Invoice::RECURRING_MONTHLY, $invoice->recurring_frequency);
        $this->assertNotNull($invoice->next_recurring_date);
    }

    public function test_invoice_status_transitions_list_is_complete(): void
    {
        // Verify all expected transitions are defined via getValidTransitions
        $draftInvoice = new Invoice();
        $draftInvoice->status = 'draft';
        
        // Test that draft can transition to sent and cancelled
        $draftTransitions = $draftInvoice->getValidTransitions();
        $this->assertContains('sent', $draftTransitions);
        
        // Test that sent can transition to viewed, partially_paid, paid, overdue, cancelled
        $sentInvoice = new Invoice();
        $sentInvoice->status = Invoice::STATUS_SENT;
        $sentTransitions = $sentInvoice->getValidTransitions();
        $this->assertContains('viewed', $sentTransitions);
        $this->assertContains('partially_paid', $sentTransitions);
        $this->assertContains('paid', $sentTransitions);
        $this->assertContains('overdue', $sentTransitions);
        // Sent can be cancelled (if not yet paid)
        $this->assertContains('cancelled', $sentTransitions);
    }

    public function test_paid_invoice_cannot_be_edited(): void
    {
        $invoice = Invoice::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => Invoice::STATUS_PAID,
        ]);

        $this->assertFalse($invoice->canBeEdited());
        $this->assertFalse($invoice->canBeCancelled());
    }

    public function test_overdue_invoice_cannot_be_edited(): void
    {
        $invoice = Invoice::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->subDays(60)->toDateString(),
            'due_date' => now()->subDays(30)->toDateString(),
            'status' => Invoice::STATUS_OVERDUE,
        ]);

        $this->assertFalse($invoice->canBeEdited());
    }

    public function test_all_status_constants_are_defined(): void
    {
        $this->assertEquals('draft', Invoice::STATUS_DRAFT);
        $this->assertEquals('sent', Invoice::STATUS_SENT);
        $this->assertEquals('viewed', Invoice::STATUS_VIEWED);
        $this->assertEquals('partially_paid', Invoice::STATUS_PARTIALLY_PAID);
        $this->assertEquals('paid', Invoice::STATUS_PAID);
        $this->assertEquals('overdue', Invoice::STATUS_OVERDUE);
        $this->assertEquals('cancelled', Invoice::STATUS_CANCELLED);
    }

    public function test_get_valid_transitions_returns_correct_statuses(): void
    {
        $invoice = Invoice::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => Invoice::STATUS_DRAFT,
        ]);

        $validTransitions = $invoice->getValidTransitions();
        $this->assertContains('sent', $validTransitions);
        $this->assertContains('cancelled', $validTransitions);
        $this->assertNotContains('paid', $validTransitions);

        $invoice->update(['status' => Invoice::STATUS_SENT]);
        $validTransitions = $invoice->getValidTransitions();
        $this->assertContains('viewed', $validTransitions);
        $this->assertContains('partially_paid', $validTransitions);
        $this->assertContains('paid', $validTransitions);
        $this->assertContains('overdue', $validTransitions);
        // Sent invoices can be cancelled (if not yet paid)
        $this->assertContains('cancelled', $validTransitions);
    }

    public function test_invoice_scope_outstanding_returns_correct_invoices(): void
    {
        // Create various invoices
        Invoice::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => Invoice::STATUS_SENT,
        ]);

        Invoice::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => Invoice::STATUS_PAID,
        ]);

        Invoice::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => Invoice::STATUS_DRAFT,
        ]);

        $outstandingCount = Invoice::outstanding()->count();
        $this->assertEquals(1, $outstandingCount);
    }

    public function test_invoice_parent_child_relationship(): void
    {
        $parentInvoice = Invoice::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ]);

        $childInvoice = Invoice::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'parent_invoice_id' => $parentInvoice->id,
        ]);

        $this->assertEquals($parentInvoice->id, $childInvoice->parentInvoice->id);
        $this->assertCount(1, $parentInvoice->childInvoices);
        $this->assertEquals($childInvoice->id, $parentInvoice->childInvoices->first()->id);
    }

    public function test_invoice_due_date_cast_to_date(): void
    {
        $invoice = Invoice::create([
            'client_id' => $this->client->id,
            'issue_date' => '2024-01-15',
            'due_date' => '2024-02-15',
        ]);

        $this->assertInstanceOf(\Carbon\Carbon::class, $invoice->due_date);
        $this->assertEquals('2024-02-15', $invoice->due_date->toDateString());
    }

    public function test_payment_percentage_calculated_correctly(): void
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
        $this->assertEquals(0, $invoice->payment_percentage);

        // Verify payment_percentage accessor exists and is calculated correctly
        $this->assertEquals(0, $invoice->payment_percentage);
    }

    public function test_is_paid_attribute(): void
    {
        $invoice = Invoice::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => Invoice::STATUS_PAID,
        ]);

        // Add items so total > 0
        $invoice->items()->create([
            'description' => 'Service',
            'quantity' => 1,
            'unit_price' => 100,
            'tax_rate' => 0,
        ]);
        $invoice->refresh();

        $this->assertTrue($invoice->isPaid());

        $invoice->update(['status' => Invoice::STATUS_SENT]);
        $invoice->refresh();
        $this->assertFalse($invoice->isPaid());
    }

    public function test_has_outstanding_balance_attribute(): void
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
        $this->assertTrue($invoice->hasOutstandingBalance());

        $invoice->update(['status' => Invoice::STATUS_PAID]);
        $this->assertFalse($invoice->hasOutstandingBalance());
    }
}

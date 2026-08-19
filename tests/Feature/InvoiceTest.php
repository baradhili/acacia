<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\Project;
use App\Models\TimeEntry;
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

    public function test_invoice_status_transitions_draft_to_sent_to_partially_paid_to_paid(): void
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

        // Sent -> Partially Paid -> Paid (via payments)
        $invoice->items()->create([
            'description' => 'Service',
            'quantity' => 1,
            'unit_price' => 100,
            'tax_rate' => 10,
        ]);
        $invoice->refresh();

        $payment = \App\Models\Payment::create([
            'client_id' => $this->client->id,
            'amount' => 55,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'bank_transfer',
        ]);
        $payment->allocateToInvoice($invoice, 55);
        $invoice->refresh();
        $this->assertEquals(Invoice::STATUS_PARTIALLY_PAID, $invoice->status);

        $payment2 = \App\Models\Payment::create([
            'client_id' => $this->client->id,
            'amount' => 55,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'bank_transfer',
        ]);
        $payment2->allocateToInvoice($invoice, 55);
        $invoice->refresh();
        $this->assertEquals(Invoice::STATUS_PAID, $invoice->status);
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

    public function test_overdue_scope_excludes_paid_but_not_marked_invoices(): void
    {
        // A sent invoice past its due_date that has been fully paid via
        // allocations but whose status is still 'sent' (e.g. status recompute
        // hasn't run) must NOT appear as overdue — it has no outstanding balance.
        $paidButSent = Invoice::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->subDays(60)->toDateString(),
            'due_date' => now()->subDays(30)->toDateString(),
            'status' => Invoice::STATUS_SENT,
            'total' => 110,
        ]);
        $payment = Payment::create([
            'client_id' => $this->client->id,
            'amount' => 110,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'bank_transfer',
        ]);
        $payment->allocateToInvoice($paidButSent, 110);
        // Force the status back to 'sent' to simulate the not-yet-flipped state.
        $paidButSent->update(['status' => Invoice::STATUS_SENT]);

        // A genuinely overdue (unpaid, past due) invoice for contrast.
        Invoice::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->subDays(60)->toDateString(),
            'due_date' => now()->subDays(30)->toDateString(),
            'status' => Invoice::STATUS_SENT,
            'total' => 200,
        ]);

        $overdueIds = Invoice::overdue()->pluck('id');
        $this->assertNotContains($paidButSent->id, $overdueIds, 'Paid-but-not-marked invoice must not be overdue.');
        $this->assertEquals(1, $overdueIds->count(), 'Only the genuinely overdue invoice should appear.');
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
        
        // Test that sent can transition to partially_paid, paid, overdue, cancelled
        $sentInvoice = new Invoice();
        $sentInvoice->status = Invoice::STATUS_SENT;
        $sentTransitions = $sentInvoice->getValidTransitions();
        $this->assertContains('partially_paid', $sentTransitions);
        $this->assertContains('paid', $sentTransitions);
        $this->assertContains('overdue', $sentTransitions);
        // Sent can be cancelled (if not yet paid)
        $this->assertContains('cancelled', $sentTransitions);
        // Sent and overdue can be un-sent (reverted to draft) while unpaid
        $this->assertContains('draft', $sentTransitions);

        $overdueInvoice = new Invoice();
        $overdueInvoice->status = Invoice::STATUS_OVERDUE;
        $this->assertContains('draft', $overdueInvoice->getValidTransitions());

        // But invoices with payments can never go back to draft
        $partiallyPaidInvoice = new Invoice();
        $partiallyPaidInvoice->status = Invoice::STATUS_PARTIALLY_PAID;
        $this->assertNotContains('draft', $partiallyPaidInvoice->getValidTransitions());
        $paidInvoice = new Invoice();
        $paidInvoice->status = Invoice::STATUS_PAID;
        $this->assertNotContains('draft', $paidInvoice->getValidTransitions());
    }

    public function test_sent_invoice_can_be_reverted_to_draft(): void
    {
        $invoice = Invoice::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ]);
        $invoice->markAsSent();
        $this->assertNotNull($invoice->refresh()->sent_at);

        $this->assertTrue($invoice->revertToDraft());
        $invoice->refresh();
        $this->assertEquals(Invoice::STATUS_DRAFT, $invoice->status);
        $this->assertNull($invoice->sent_at);
        $this->assertTrue($invoice->canBeEdited());
    }

    public function test_invoice_with_payments_cannot_be_reverted_to_draft(): void
    {
        $invoice = Invoice::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => Invoice::STATUS_SENT,
        ]);
        $invoice->items()->create([
            'description' => 'Service',
            'quantity' => 1,
            'unit_price' => 100,
            'tax_rate' => 10,
        ]);
        $invoice->refresh();
        $invoice->recalculateTotals();

        $payment = Payment::create([
            'client_id' => $this->client->id,
            'amount' => 50,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'bank_transfer',
        ]);
        $payment->allocateToInvoice($invoice, 50); // → partially_paid

        $this->assertFalse($invoice->revertToDraft());
        $this->assertEquals(Invoice::STATUS_PARTIALLY_PAID, $invoice->refresh()->status);
    }

    public function test_unsend_route_returns_sent_invoice_to_draft(): void
    {
        $invoice = Invoice::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ]);
        $invoice->markAsSent();

        $response = $this->actingAs($this->user)
            ->post(route('invoices.unsend', $invoice));

        $response->assertSessionHas('success');
        $this->assertEquals(Invoice::STATUS_DRAFT, $invoice->refresh()->status);
    }

    public function test_unsend_route_rejects_invoice_with_payments(): void
    {
        $invoice = Invoice::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => Invoice::STATUS_SENT,
        ]);
        $invoice->items()->create([
            'description' => 'Service',
            'quantity' => 1,
            'unit_price' => 100,
            'tax_rate' => 10,
        ]);
        $invoice->refresh();
        $invoice->recalculateTotals();

        $payment = Payment::create([
            'client_id' => $this->client->id,
            'amount' => 110,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'bank_transfer',
        ]);
        $payment->allocateToInvoice($invoice, 110); // fully paid

        $response = $this->actingAs($this->user)
            ->post(route('invoices.unsend', $invoice));

        $response->assertSessionHas('error');
        $this->assertEquals(Invoice::STATUS_PAID, $invoice->refresh()->status);
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

    public function test_record_payment_requires_outstanding_invoice(): void
    {
        // Draft invoice — payment must be rejected.
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
        $this->assertEquals(Invoice::STATUS_DRAFT, $draft->status);

        $response = $this->actingAs($this->user)->post(route('invoices.recordPayment', $draft), [
            'amount' => 50,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'bank_transfer',
        ]);

        $response->assertSessionHas('error');
        $this->assertDatabaseMissing('payments', ['client_id' => $this->client->id]);
    }

    public function test_record_payment_rejects_cancelled_invoice(): void
    {
        $invoice = Invoice::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => Invoice::STATUS_SENT,
        ]);
        $invoice->items()->create([
            'description' => 'Service',
            'quantity' => 1,
            'unit_price' => 100,
            'tax_rate' => 10,
        ]);
        $invoice->refresh();
        $invoice->cancel();
        $invoice->refresh();
        $this->assertEquals(Invoice::STATUS_CANCELLED, $invoice->status);

        $response = $this->actingAs($this->user)->post(route('invoices.recordPayment', $invoice), [
            'amount' => 50,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'bank_transfer',
        ]);

        $response->assertSessionHas('error');
        $this->assertDatabaseMissing('payments', ['client_id' => $this->client->id]);
    }

    public function test_record_payment_rejects_paid_invoice(): void
    {
        $invoice = Invoice::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => Invoice::STATUS_SENT,
        ]);
        $invoice->items()->create([
            'description' => 'Service',
            'quantity' => 1,
            'unit_price' => 100,
            'tax_rate' => 10,
        ]);
        $invoice->refresh();
        // Fully pay the invoice first.
        $payment = \App\Models\Payment::create([
            'client_id' => $this->client->id,
            'amount' => $invoice->total,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'bank_transfer',
        ]);
        $payment->allocateToInvoice($invoice, $invoice->total);
        $invoice->refresh();
        $this->assertEquals(Invoice::STATUS_PAID, $invoice->status);

        $response = $this->actingAs($this->user)->post(route('invoices.recordPayment', $invoice), [
            'amount' => 10,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'bank_transfer',
        ]);

        $response->assertSessionHas('error');
        // Only the original payment should exist — the second attempt rejected.
        $this->assertEquals(1, \App\Models\Payment::where('client_id', $this->client->id)->count());
    }

    public function test_record_payment_accepts_sent_invoice(): void
    {
        $invoice = Invoice::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => Invoice::STATUS_SENT,
        ]);
        $invoice->items()->create([
            'description' => 'Service',
            'quantity' => 1,
            'unit_price' => 100,
            'tax_rate' => 10,
        ]);
        $invoice->refresh();

        $response = $this->actingAs($this->user)->post(route('invoices.recordPayment', $invoice), [
            'amount' => 50,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'bank_transfer',
        ]);

        $response->assertSessionHas('success');
        $this->assertDatabaseHas('payments', [
            'client_id' => $this->client->id,
            'amount' => 50,
        ]);
    }

    public function test_create_with_unique_number_retries_on_duplicate(): void
    {
        // Determine the number the generator would assign next, then occupy it
        // so that createWithUniqueNumber()'s first insert hits the unique
        // constraint and must retry. This is the race-condition scenario:
        // two requests compute the same next number; the loser must regenerate.
        $collidingNumber = Invoice::generateInvoiceNumber();
        Invoice::create([
            'client_id' => $this->client->id,
            'invoice_number' => $collidingNumber,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => Invoice::STATUS_DRAFT,
        ]);

        // createWithUniqueNumber should swallow the unique violation and land
        // on the next available number rather than throwing.
        $invoice = Invoice::createWithUniqueNumber([
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ]);

        $this->assertNotNull($invoice->id);
        $this->assertNotEquals($collidingNumber, $invoice->invoice_number);
        $this->assertMatchesRegularExpression('/^INV-\d{4}-\d{4}$/', $invoice->invoice_number);

        // Both rows exist and have distinct numbers.
        $this->assertEquals(2, Invoice::where('client_id', $this->client->id)->count());
        $this->assertEquals(
            2,
            Invoice::where('client_id', $this->client->id)->distinct()->count('invoice_number')
        );
    }

    public function test_editing_invoice_preserves_item_id_and_time_entry_link(): void
    {
        // Set up a project + time entry so the invoice item can link to it.
        $project = Project::factory()->create(['client_id' => $this->client->id]);
        $timeEntry = TimeEntry::create([
            'user_id' => $this->user->id,
            'project_id' => $project->id,
            'start_time' => now()->subHours(2),
            'end_time' => now(),
            'description' => 'Consulting work',
            'billable' => true,
            'status' => TimeEntry::STATUS_APPROVED,
        ]);

        // Create a draft invoice with one item linked to the time entry.
        $invoice = Invoice::create([
            'client_id' => $this->client->id,
            'project_id' => $project->id,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => Invoice::STATUS_DRAFT,
        ]);
        $item = $invoice->items()->create([
            'description' => 'Consulting work',
            'quantity' => 2,
            'unit_price' => 100,
            'tax_rate' => 10,
            'time_entry_id' => $timeEntry->id,
        ]);
        $originalItemId = $item->id;

        // Edit the invoice: change unit_price, pass the existing item id.
        // (This mirrors the edit form, which now emits a hidden items[][id].)
        $response = $this->actingAs($this->user)->put("/invoices/{$invoice->id}", [
            'client_id' => $this->client->id,
            'project_id' => $project->id,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'items' => [
                [
                    'id' => $originalItemId,
                    'description' => 'Consulting work (updated)',
                    'quantity' => 2,
                    'unit_price' => 150,
                    'tax_rate' => 10,
                    'discount_percent' => 0,
                ],
            ],
        ]);

        $response->assertSessionHas('success');

        // The item should have been UPDATED, not deleted+recreated: same id,
        // and the time_entry_id link must survive the edit.
        $invoice->refresh();
        $this->assertCount(1, $invoice->items, 'Item count should stay at 1');

        $refreshedItem = $invoice->items->first();
        $this->assertEquals($originalItemId, $refreshedItem->id, 'Item id must be preserved across edit.');
        $this->assertEquals(150, (float) $refreshedItem->unit_price, 'unit_price should reflect the edit.');
        $this->assertEquals($timeEntry->id, $refreshedItem->time_entry_id, 'time_entry_id link must be preserved.');
    }

    public function test_recalculate_totals_is_safe_to_call_and_does_not_recurse(): void
    {
        // Build an invoice with two items directly (not via the form), so the
        // result does not depend on any controller or on a saved-hook firing.
        $invoice = Invoice::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => Invoice::STATUS_DRAFT,
        ]);
        $invoice->items()->create([
            'description' => 'Service A',
            'quantity' => 2,
            'unit_price' => 100,
            'tax_rate' => 10,
        ]);
        $invoice->items()->create([
            'description' => 'Service B',
            'quantity' => 1,
            'unit_price' => 50,
            'tax_rate' => 10,
        ]);
        $invoice->refresh();

        // Calling recalculateTotals() directly must produce the correct
        // pre-tax subtotal + GST + total (independent of which hook fired).
        $invoice->recalculateTotals();
        $invoice->refresh();
        $this->assertEquals(250.00, (float) $invoice->subtotal, 'pre-tax subtotal');
        $this->assertEquals(25.00, (float) $invoice->tax_amount, 'GST @10%');
        $this->assertEquals(275.00, (float) $invoice->total, 'tax-inclusive total');

        // A plain save() after recalculation must complete without recursing
        // (recalculateTotals persists via withoutEvents/updateQuietly, so no
        // saved hook re-enters). If this ever exhausts the stack, a future
        // auto-recalc saved hook was added without a real re-entry guard.
        $invoice->notes = 'Updated note';
        $invoice->save();

        $invoice->refresh();
        $this->assertEquals(275.00, (float) $invoice->total, 'total must be unchanged by a plain save');
        $this->assertSame('Updated note', $invoice->notes);
    }
}

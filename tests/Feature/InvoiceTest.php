<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\CompanyProfile;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\TimeEntry;
use App\Models\User;
use Carbon\Carbon;
use IFRS\Models\Currency;
use IFRS\Models\Entity;
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

    public function test_invoice_screen_shows_company_bank_payment_details(): void
    {
        $this->actingAs($this->user)->post('/invoices', [
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
        ])->assertSessionHas('success');

        $invoice = Invoice::query()->firstOrFail();

        // No bank details on the profile: no payment block.
        $this->actingAs($this->user)
            ->get('/invoices/'.$invoice->id)
            ->assertOk()
            ->assertDontSee('Payment Details');

        $entity = Entity::create([
            'name' => 'Invoicee Co',
            'locale' => 'en_AU',
            'year_start' => 7,
            'multi_currency' => false,
        ]);
        $currency = Currency::create([
            'name' => 'Australian Dollar',
            'currency_code' => 'AUD',
            'entity_id' => $entity->id,
        ]);
        $entity->update(['currency_id' => $currency->id]);
        $companyProfile = new CompanyProfile;
        $companyProfile->fill([
            'bank_bsb' => '123456',
            'bank_account_number' => '12345678',
            'bank_account_name' => 'Invoicee Co Operating',
        ]);
        $companyProfile->entity_id = $entity->id;
        $companyProfile->save();

        // With them: the payment block appears below the notes area.
        $this->actingAs($this->user)
            ->get('/invoices/'.$invoice->id)
            ->assertOk()
            ->assertSee('Payment Details')
            ->assertSee('Account Name: Invoicee Co Operating')
            ->assertSee('BSB: 123-456')
            ->assertSee('Account Number: 12345678');
    }

    public function test_invoice_generates_correct_invoice_number(): void
    {
        $invoice = new Invoice;
        $invoice->fill([
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ]);
        $invoice->client_id = $this->client->id;
        $invoice->save();

        $this->assertMatchesRegularExpression('/^INV-'.date('Y').'-\d{4}$/', $invoice->invoice_number);
    }

    public function test_invoice_calculates_totals_correctly(): void
    {
        $invoice = new Invoice;
        $invoice->fill([
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ]);
        $invoice->client_id = $this->client->id;
        $invoice->save();

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

    public function test_invoice_items_accept_four_decimal_precision(): void
    {
        // Client reverse invoices can carry sub-cent unit prices and
        // fractional quantities — both fields must keep 4dp through storage.
        $response = $this->actingAs($this->user)->post('/invoices', [
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'items' => [
                [
                    'description' => 'Reverse charge line',
                    'quantity' => 1000,
                    'unit_price' => 0.0123, // 1000 × 0.0123 = 12.30
                    'tax_rate' => 10,
                ],
                [
                    'description' => 'Fractional quantity line',
                    'quantity' => 0.1234,
                    'unit_price' => 100, // 0.1234 × 100 = 12.34
                    'tax_rate' => 10,
                ],
            ],
        ]);

        $response->assertSessionHas('success');
        $invoice = Invoice::first();
        $items = $invoice->items;

        $this->assertEquals(0.0123, (float) $items[0]->unit_price);
        $this->assertEquals(0.1234, (float) $items[1]->quantity);

        // Line and invoice totals remain cents
        $this->assertEquals(13.53, (float) $items[0]->total);
        $this->assertEquals(13.57, (float) $items[1]->total);
        $this->assertEquals(27.10, (float) $invoice->total);
    }

    public function test_invoice_status_transitions(): void
    {
        $invoice = new Invoice;
        $invoice->fill([
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ]);
        $invoice->client_id = $this->client->id;
        $invoice->save();

        // Draft -> Sent
        $invoice->markAsSent();
        $this->assertEquals(Invoice::STATUS_SENT, $invoice->status);
    }

    public function test_invoice_cannot_transition_to_invalid_status(): void
    {
        $invoice = new Invoice;
        $invoice->fill([
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ]);
        $invoice->client_id = $this->client->id;
        $invoice->save();

        // Paid invoice cannot transition to cancelled
        $invoice->update(['status' => Invoice::STATUS_PAID]);
        $this->assertFalse($invoice->canTransitionTo(Invoice::STATUS_CANCELLED));
    }

    public function test_only_draft_invoices_can_be_edited(): void
    {
        $invoice = new Invoice;
        $invoice->fill([
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ]);
        $invoice->client_id = $this->client->id;
        $invoice->save();

        // Draft can be edited
        $this->assertTrue($invoice->canBeEdited());

        // Sent cannot be edited
        $invoice->update(['status' => Invoice::STATUS_SENT]);
        $this->assertFalse($invoice->canBeEdited());
    }

    public function test_invoice_due_date_detection(): void
    {
        $invoice = new Invoice;
        $invoice->fill([
            'issue_date' => now()->toDateString(),
            'due_date' => now()->subDay()->toDateString(), // Yesterday
        ]);
        $invoice->client_id = $this->client->id;
        $invoice->save();

        $this->assertTrue($invoice->is_overdue);

        $invoice2 = new Invoice;
        $invoice2->fill([
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDay()->toDateString(), // Tomorrow
        ]);
        $invoice2->client_id = $this->client->id;
        $invoice2->save();

        $this->assertFalse($invoice2->is_overdue);
    }

    public function test_invoice_amount_paid_calculation(): void
    {
        $invoice = new Invoice;
        $invoice->fill([
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ]);
        $invoice->client_id = $this->client->id;
        $invoice->save();

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
        $invoice = new Invoice;
        $invoice->fill([
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ]);
        $invoice->client_id = $this->client->id;
        $invoice->save();

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

        $payment = new Payment;
        $payment->fill([
            'amount' => 55,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'bank_transfer',
        ]);
        $payment->client_id = $this->client->id;
        $payment->save();
        $payment->allocateToInvoice($invoice, 55);
        $invoice->refresh();
        $this->assertEquals(Invoice::STATUS_PARTIALLY_PAID, $invoice->status);

        $payment2 = new Payment;
        $payment2->fill([
            'amount' => 55,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'bank_transfer',
        ]);
        $payment2->client_id = $this->client->id;
        $payment2->save();
        $payment2->allocateToInvoice($invoice, 55);
        $invoice->refresh();
        $this->assertEquals(Invoice::STATUS_PAID, $invoice->status);
    }

    public function test_invoice_status_transitions_to_overdue(): void
    {
        $invoice = new Invoice;
        $invoice->fill([
            'issue_date' => now()->subDays(60)->toDateString(),
            'due_date' => now()->subDays(30)->toDateString(), // Already past due
            'status' => Invoice::STATUS_SENT,
        ]);
        $invoice->client_id = $this->client->id;
        $invoice->save();

        // Must explicitly call markAsOverdue
        $invoice->markAsOverdue();

        $this->assertTrue($invoice->is_overdue);
        $this->assertEquals(Invoice::STATUS_OVERDUE, $invoice->status);
    }

    public function test_invoice_cannot_transition_from_paid_to_cancelled(): void
    {
        $invoice = new Invoice;
        $invoice->fill([
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => Invoice::STATUS_PAID,
        ]);
        $invoice->client_id = $this->client->id;
        $invoice->save();

        $this->assertFalse($invoice->canTransitionTo(Invoice::STATUS_CANCELLED));
        $this->assertFalse($invoice->canBeCancelled());
    }

    public function test_invoice_cancellation_only_allowed_in_draft_state(): void
    {
        // Draft invoice can be cancelled
        $draftInvoice = new Invoice;
        $draftInvoice->fill([
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => Invoice::STATUS_DRAFT,
        ]);
        $draftInvoice->client_id = $this->client->id;
        $draftInvoice->save();
        $this->assertTrue($draftInvoice->canBeCancelled());
        $draftInvoice->cancel();
        $this->assertEquals(Invoice::STATUS_CANCELLED, $draftInvoice->status);

        // Sent invoice can be cancelled (if not yet paid)
        $sentInvoice = new Invoice;
        $sentInvoice->fill([
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => Invoice::STATUS_SENT,
        ]);
        $sentInvoice->client_id = $this->client->id;
        $sentInvoice->save();
        $this->assertTrue($sentInvoice->canBeCancelled());
        $sentInvoice->cancel();
        $this->assertEquals(Invoice::STATUS_CANCELLED, $sentInvoice->status);

        // Paid invoice cannot be cancelled
        $paidInvoice = new Invoice;
        $paidInvoice->fill([
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => Invoice::STATUS_PAID,
        ]);
        $paidInvoice->client_id = $this->client->id;
        $paidInvoice->save();
        $this->assertFalse($paidInvoice->canBeCancelled());
    }

    public function test_invoice_cancellation_route_requires_draft_or_sent_status(): void
    {
        $invoice = new Invoice;
        $invoice->fill([
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => Invoice::STATUS_PAID,
        ]);
        $invoice->client_id = $this->client->id;
        $invoice->save();

        $response = $this->actingAs($this->user)->post(route('invoices.cancel', $invoice));
        $response->assertSessionHas('error');
    }

    public function test_automatic_overdue_marking_via_cron_command(): void
    {
        // Create invoices that should be marked overdue
        $overdueInvoice = new Invoice;
        $overdueInvoice->fill([
            'issue_date' => now()->subDays(60)->toDateString(),
            'due_date' => now()->subDays(30)->toDateString(),
            'status' => Invoice::STATUS_SENT,
        ]);
        $overdueInvoice->client_id = $this->client->id;
        $overdueInvoice->save();

        // Create invoice that should NOT be marked overdue
        $notOverdueInvoice = new Invoice;
        $notOverdueInvoice->fill([
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => Invoice::STATUS_SENT,
        ]);
        $notOverdueInvoice->client_id = $this->client->id;
        $notOverdueInvoice->save();

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
        $invoice = new Invoice;
        $invoice->fill([
            'issue_date' => now()->subDays(60)->toDateString(),
            'due_date' => now()->subDays(30)->toDateString(),
            'status' => Invoice::STATUS_SENT,
        ]);
        $invoice->client_id = $this->client->id;
        $invoice->save();

        // Create not overdue invoice
        $invoice = new Invoice;
        $invoice->fill([
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => Invoice::STATUS_SENT,
        ]);
        $invoice->client_id = $this->client->id;
        $invoice->save();

        $overdueCount = Invoice::overdue()->count();
        $this->assertEquals(1, $overdueCount);
    }

    public function test_overdue_scope_excludes_paid_but_not_marked_invoices(): void
    {
        // A sent invoice past its due_date that has been fully paid via
        // allocations but whose status is still 'sent' (e.g. status recompute
        // hasn't run) must NOT appear as overdue — it has no outstanding balance.
        $paidButSent = new Invoice;
        $paidButSent->fill([
            'issue_date' => now()->subDays(60)->toDateString(),
            'due_date' => now()->subDays(30)->toDateString(),
            'status' => Invoice::STATUS_SENT,
            'total' => 110,
        ]);
        $paidButSent->client_id = $this->client->id;
        $paidButSent->save();
        $payment = new Payment;
        $payment->fill([
            'amount' => 110,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'bank_transfer',
        ]);
        $payment->client_id = $this->client->id;
        $payment->save();
        $payment->allocateToInvoice($paidButSent, 110);
        // Force the status back to 'sent' to simulate the not-yet-flipped state.
        $paidButSent->update(['status' => Invoice::STATUS_SENT]);

        // A genuinely overdue (unpaid, past due) invoice for contrast.
        $invoice = new Invoice;
        $invoice->fill([
            'issue_date' => now()->subDays(60)->toDateString(),
            'due_date' => now()->subDays(30)->toDateString(),
            'status' => Invoice::STATUS_SENT,
            'total' => 200,
        ]);
        $invoice->client_id = $this->client->id;
        $invoice->save();

        $overdueIds = Invoice::overdue()->pluck('id');
        $this->assertNotContains($paidButSent->id, $overdueIds, 'Paid-but-not-marked invoice must not be overdue.');
        $this->assertEquals(1, $overdueIds->count(), 'Only the genuinely overdue invoice should appear.');
    }

    public function test_sent_invoice_updates_sent_at_timestamp(): void
    {
        $invoice = new Invoice;
        $invoice->fill([
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ]);
        $invoice->client_id = $this->client->id;
        $invoice->save();

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
        $invoice = new Invoice;
        $invoice->fill([
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'is_recurring' => true,
            'recurring_frequency' => Invoice::RECURRING_MONTHLY,
            'next_recurring_date' => now()->addMonth()->toDateString(),
        ]);
        $invoice->client_id = $this->client->id;
        $invoice->save();

        $this->assertTrue($invoice->is_recurring);
        $this->assertEquals(Invoice::RECURRING_MONTHLY, $invoice->recurring_frequency);
        $this->assertNotNull($invoice->next_recurring_date);
    }

    public function test_invoice_status_transitions_list_is_complete(): void
    {
        // Verify all expected transitions are defined via getValidTransitions
        $draftInvoice = new Invoice;
        $draftInvoice->status = 'draft';

        // Test that draft can transition to sent and cancelled
        $draftTransitions = $draftInvoice->getValidTransitions();
        $this->assertContains('sent', $draftTransitions);

        // Test that sent can transition to partially_paid, paid, overdue, cancelled
        $sentInvoice = new Invoice;
        $sentInvoice->status = Invoice::STATUS_SENT;
        $sentTransitions = $sentInvoice->getValidTransitions();
        $this->assertContains('partially_paid', $sentTransitions);
        $this->assertContains('paid', $sentTransitions);
        $this->assertContains('overdue', $sentTransitions);
        // Sent can be cancelled (if not yet paid)
        $this->assertContains('cancelled', $sentTransitions);
        // Sent and overdue can be un-sent (reverted to draft) while unpaid
        $this->assertContains('draft', $sentTransitions);

        $overdueInvoice = new Invoice;
        $overdueInvoice->status = Invoice::STATUS_OVERDUE;
        $this->assertContains('draft', $overdueInvoice->getValidTransitions());

        // But invoices with payments can never go back to draft
        $partiallyPaidInvoice = new Invoice;
        $partiallyPaidInvoice->status = Invoice::STATUS_PARTIALLY_PAID;
        $this->assertNotContains('draft', $partiallyPaidInvoice->getValidTransitions());
        $paidInvoice = new Invoice;
        $paidInvoice->status = Invoice::STATUS_PAID;
        $this->assertNotContains('draft', $paidInvoice->getValidTransitions());
    }

    public function test_sent_invoice_can_be_reverted_to_draft(): void
    {
        $invoice = new Invoice;
        $invoice->fill([
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ]);
        $invoice->client_id = $this->client->id;
        $invoice->save();
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
        $invoice = new Invoice;
        $invoice->fill([
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => Invoice::STATUS_SENT,
        ]);
        $invoice->client_id = $this->client->id;
        $invoice->save();
        $invoice->items()->create([
            'description' => 'Service',
            'quantity' => 1,
            'unit_price' => 100,
            'tax_rate' => 10,
        ]);
        $invoice->refresh();
        $invoice->recalculateTotals();

        $payment = new Payment;
        $payment->fill([
            'amount' => 50,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'bank_transfer',
        ]);
        $payment->client_id = $this->client->id;
        $payment->save();
        $payment->allocateToInvoice($invoice, 50); // → partially_paid

        $this->assertFalse($invoice->revertToDraft());
        $this->assertEquals(Invoice::STATUS_PARTIALLY_PAID, $invoice->refresh()->status);
    }

    public function test_unsend_route_returns_sent_invoice_to_draft(): void
    {
        $invoice = new Invoice;
        $invoice->fill([
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ]);
        $invoice->client_id = $this->client->id;
        $invoice->save();
        $invoice->markAsSent();

        $response = $this->actingAs($this->user)
            ->post(route('invoices.unsend', $invoice));

        $response->assertSessionHas('success');
        $this->assertEquals(Invoice::STATUS_DRAFT, $invoice->refresh()->status);
    }

    public function test_unsend_route_rejects_invoice_with_payments(): void
    {
        $invoice = new Invoice;
        $invoice->fill([
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => Invoice::STATUS_SENT,
        ]);
        $invoice->client_id = $this->client->id;
        $invoice->save();
        $invoice->items()->create([
            'description' => 'Service',
            'quantity' => 1,
            'unit_price' => 100,
            'tax_rate' => 10,
        ]);
        $invoice->refresh();
        $invoice->recalculateTotals();

        $payment = new Payment;
        $payment->fill([
            'amount' => 110,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'bank_transfer',
        ]);
        $payment->client_id = $this->client->id;
        $payment->save();
        $payment->allocateToInvoice($invoice, 110); // fully paid

        $response = $this->actingAs($this->user)
            ->post(route('invoices.unsend', $invoice));

        $response->assertSessionHas('error');
        $this->assertEquals(Invoice::STATUS_PAID, $invoice->refresh()->status);
    }

    public function test_paid_invoice_cannot_be_edited(): void
    {
        $invoice = new Invoice;
        $invoice->fill([
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => Invoice::STATUS_PAID,
        ]);
        $invoice->client_id = $this->client->id;
        $invoice->save();

        $this->assertFalse($invoice->canBeEdited());
        $this->assertFalse($invoice->canBeCancelled());
    }

    public function test_overdue_invoice_cannot_be_edited(): void
    {
        $invoice = new Invoice;
        $invoice->fill([
            'issue_date' => now()->subDays(60)->toDateString(),
            'due_date' => now()->subDays(30)->toDateString(),
            'status' => Invoice::STATUS_OVERDUE,
        ]);
        $invoice->client_id = $this->client->id;
        $invoice->save();

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
        $invoice = new Invoice;
        $invoice->fill([
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => Invoice::STATUS_DRAFT,
        ]);
        $invoice->client_id = $this->client->id;
        $invoice->save();

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
        $invoice = new Invoice;
        $invoice->fill([
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => Invoice::STATUS_SENT,
        ]);
        $invoice->client_id = $this->client->id;
        $invoice->save();

        $invoice = new Invoice;
        $invoice->fill([
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => Invoice::STATUS_PAID,
        ]);
        $invoice->client_id = $this->client->id;
        $invoice->save();

        $invoice = new Invoice;
        $invoice->fill([
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => Invoice::STATUS_DRAFT,
        ]);
        $invoice->client_id = $this->client->id;
        $invoice->save();

        $outstandingCount = Invoice::outstanding()->count();
        $this->assertEquals(1, $outstandingCount);
    }

    public function test_invoice_parent_child_relationship(): void
    {
        $parentInvoice = new Invoice;
        $parentInvoice->fill([
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ]);
        $parentInvoice->client_id = $this->client->id;
        $parentInvoice->save();

        $childInvoice = new Invoice;
        $childInvoice->fill([
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ]);
        $childInvoice->client_id = $this->client->id;
        $childInvoice->parent_invoice_id = $parentInvoice->id;
        $childInvoice->save();

        $this->assertEquals($parentInvoice->id, $childInvoice->parentInvoice->id);
        $this->assertCount(1, $parentInvoice->childInvoices);
        $this->assertEquals($childInvoice->id, $parentInvoice->childInvoices->first()->id);
    }

    public function test_invoice_due_date_cast_to_date(): void
    {
        $invoice = new Invoice;
        $invoice->fill([
            'issue_date' => '2024-01-15',
            'due_date' => '2024-02-15',
        ]);
        $invoice->client_id = $this->client->id;
        $invoice->save();

        $this->assertInstanceOf(Carbon::class, $invoice->due_date);
        $this->assertEquals('2024-02-15', $invoice->due_date->toDateString());
    }

    public function test_payment_percentage_calculated_correctly(): void
    {
        $invoice = new Invoice;
        $invoice->fill([
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ]);
        $invoice->client_id = $this->client->id;
        $invoice->save();

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
        $invoice = new Invoice;
        $invoice->fill([
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => Invoice::STATUS_PAID,
        ]);
        $invoice->client_id = $this->client->id;
        $invoice->save();

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
        $invoice = new Invoice;
        $invoice->fill([
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ]);
        $invoice->client_id = $this->client->id;
        $invoice->save();

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
        $draft = new Invoice;
        $draft->fill([
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ]);
        $draft->client_id = $this->client->id;
        $draft->save();
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
        $invoice = new Invoice;
        $invoice->fill([
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => Invoice::STATUS_SENT,
        ]);
        $invoice->client_id = $this->client->id;
        $invoice->save();
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
        $invoice = new Invoice;
        $invoice->fill([
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => Invoice::STATUS_SENT,
        ]);
        $invoice->client_id = $this->client->id;
        $invoice->save();
        $invoice->items()->create([
            'description' => 'Service',
            'quantity' => 1,
            'unit_price' => 100,
            'tax_rate' => 10,
        ]);
        $invoice->refresh();
        // Fully pay the invoice first.
        $payment = new Payment;
        $payment->fill([
            'amount' => $invoice->total,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'bank_transfer',
        ]);
        $payment->client_id = $this->client->id;
        $payment->save();
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
        $this->assertEquals(1, Payment::where('client_id', $this->client->id)->count());
    }

    public function test_record_payment_accepts_sent_invoice(): void
    {
        $invoice = new Invoice;
        $invoice->fill([
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => Invoice::STATUS_SENT,
        ]);
        $invoice->client_id = $this->client->id;
        $invoice->save();
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
        $invoice = new Invoice;
        $invoice->fill([
            'invoice_number' => $collidingNumber,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => Invoice::STATUS_DRAFT,
        ]);
        $invoice->client_id = $this->client->id;
        $invoice->save();

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
        $timeEntry = new TimeEntry;
        $timeEntry->fill([
            'start_time' => now()->subHours(2),
            'end_time' => now(),
            'description' => 'Consulting work',
            'billable' => true,
            'status' => TimeEntry::STATUS_APPROVED,
        ]);
        $timeEntry->user_id = $this->user->id;
        $timeEntry->project_id = $project->id;
        $timeEntry->save();

        // Create a draft invoice with one item linked to the time entry.
        $invoice = new Invoice;
        $invoice->fill([
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => Invoice::STATUS_DRAFT,
        ]);
        $invoice->client_id = $this->client->id;
        $invoice->project_id = $project->id;
        $invoice->save();
        $item = $invoice->items()->make([
            'description' => 'Consulting work',
            'quantity' => 2,
            'unit_price' => 100,
            'tax_rate' => 10,
        ]);
        // time_entry_id is an unfillable FK — link it explicitly.
        $item->time_entry_id = $timeEntry->id;
        $item->save();
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
        $invoice = new Invoice;
        $invoice->fill([
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => Invoice::STATUS_DRAFT,
        ]);
        $invoice->client_id = $this->client->id;
        $invoice->save();
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

    private function poLinkedInvoice(PurchaseOrder $po, string $status = 'draft'): Invoice
    {
        $invoice = new Invoice;
        $invoice->fill([
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ]);
        $invoice->client_id = $this->client->id;
        $invoice->purchase_order_id = $po->id;
        $invoice->save();
        $invoice->items()->create([
            'description' => 'Test Service',
            'quantity' => 1,
            'unit_price' => 100,
            'tax_rate' => 10,
        ]);
        $invoice->recalculateTotals();
        $invoice->refresh();

        if ($status !== 'draft') {
            $invoice->update(['status' => $status]);
            // The status change recalculated the PO's used_amount on a
            // separate instance; reload so the memoized relation is fresh.
            $invoice->refresh();
        }

        return $invoice;
    }

    public function test_po_remaining_after_subtracts_draft_invoice_total(): void
    {
        $po = new PurchaseOrder;
        $po->fill([
            'title' => 'PO Work',
            'budgeted_amount' => 10000,
            'used_amount' => 0,
            'status' => 'open',
        ]);
        $po->client_id = $this->client->id;
        $po->save();

        // A previously sent invoice counts against the budget...
        $this->poLinkedInvoice($po, 'sent');
        $this->poLinkedInvoice($po, 'sent');
        $po->refresh();
        $this->assertEquals(220.00, (float) $po->used_amount);

        // ...a draft one does not, so its total is subtracted on top.
        $draft = $this->poLinkedInvoice($po, 'draft');
        $this->assertEquals(9670.00, $draft->po_remaining_after);
    }

    public function test_po_remaining_after_counts_sent_invoice_via_used_amount(): void
    {
        $po = new PurchaseOrder;
        $po->fill([
            'title' => 'PO Work',
            'budgeted_amount' => 10000,
            'used_amount' => 0,
            'status' => 'open',
        ]);
        $po->client_id = $this->client->id;
        $po->save();

        $sent = $this->poLinkedInvoice($po, 'sent');
        $po->refresh();
        $this->assertEquals(110.00, (float) $po->used_amount);

        // Already counted in used_amount — must not be subtracted twice.
        $this->assertEquals(9890.00, $sent->po_remaining_after);
    }

    public function test_po_remaining_after_is_null_without_linked_po(): void
    {
        $invoice = new Invoice;
        $invoice->fill([
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ]);
        $invoice->client_id = $this->client->id;
        $invoice->save();
        $invoice->items()->create([
            'description' => 'Test Service',
            'quantity' => 1,
            'unit_price' => 100,
            'tax_rate' => 10,
        ]);
        $invoice->recalculateTotals();

        $this->assertNull($invoice->po_remaining_after);
    }

    public function test_invoice_create_form_with_client_preselect_loads(): void
    {
        // Regression: create() queries whereDoesntHave('invoiceItem') on
        // TimeEntry — before the relation existed this threw
        // RelationNotFoundException for any ?client_id= preselect.
        $project = Project::factory()->create(['client_id' => $this->client->id]);
        $timeEntry = new TimeEntry;
        $timeEntry->fill([
            'start_time' => now()->subHours(2),
            'end_time' => now(),
            'description' => 'Consulting work',
            'billable' => true,
            'status' => TimeEntry::STATUS_APPROVED,
        ]);
        $timeEntry->user_id = $this->user->id;
        $timeEntry->project_id = $project->id;
        $timeEntry->save();

        $response = $this->actingAs($this->user)
            ->get('/invoices/create?client_id='.$this->client->id);

        $response->assertOk();
        $response->assertSee('Consulting work');
    }

    public function test_deleting_invoice_releases_its_time_entries(): void
    {
        $project = Project::factory()->create(['client_id' => $this->client->id]);
        $timeEntry = new TimeEntry;
        $timeEntry->fill([
            'start_time' => now()->subHours(2),
            'end_time' => now(),
            'description' => 'Consulting work',
            'billable' => true,
            'status' => TimeEntry::STATUS_APPROVED,
        ]);
        $timeEntry->user_id = $this->user->id;
        $timeEntry->project_id = $project->id;
        $timeEntry->save();

        $invoice = new Invoice;
        $invoice->fill([
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => Invoice::STATUS_DRAFT,
        ]);
        $invoice->client_id = $this->client->id;
        $invoice->save();
        $item = $invoice->items()->make([
            'description' => 'Consulting work',
            'quantity' => 2,
            'unit_price' => 100,
            'tax_rate' => 10,
        ]);
        // time_entry_id is an unfillable FK — link it explicitly.
        $item->time_entry_id = $timeEntry->id;
        $item->save();

        $this->assertTrue($timeEntry->invoiceItem()->exists());

        $response = $this->actingAs($this->user)
            ->delete("/invoices/{$invoice->id}");

        $response->assertRedirect(route('invoices.index'));
        // Invoice deletion cascades items, which releases the entry.
        $this->assertFalse($timeEntry->invoiceItem()->exists());
        $this->assertDatabaseMissing('invoices', ['id' => $invoice->id]);
    }

    public function test_cancelling_invoice_releases_its_time_entries(): void
    {
        $project = Project::factory()->create(['client_id' => $this->client->id]);
        $timeEntry = new TimeEntry;
        $timeEntry->fill([
            'start_time' => now()->subHours(2),
            'end_time' => now(),
            'description' => 'Consulting work',
            'billable' => true,
            'status' => TimeEntry::STATUS_APPROVED,
        ]);
        $timeEntry->user_id = $this->user->id;
        $timeEntry->project_id = $project->id;
        $timeEntry->save();

        $invoice = new Invoice;
        $invoice->fill([
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => Invoice::STATUS_SENT,
        ]);
        $invoice->client_id = $this->client->id;
        $invoice->save();
        $item = $invoice->items()->make([
            'description' => 'Consulting work',
            'quantity' => 2,
            'unit_price' => 100,
            'tax_rate' => 10,
        ]);
        // time_entry_id is an unfillable FK — link it explicitly.
        $item->time_entry_id = $timeEntry->id;
        $item->save();

        $this->assertTrue($timeEntry->invoiceItem()->exists());

        $invoice->cancel();

        // The invoiceItem relation excludes cancelled invoices, so the
        // entry is available for re-invoicing without deleting anything.
        $this->assertFalse($timeEntry->invoiceItem()->exists());
        $this->assertTrue(
            TimeEntry::whereDoesntHave('invoiceItem')->whereKey($timeEntry->id)->exists()
        );
    }

    public function test_store_links_checked_time_entries_as_invoice_items(): void
    {
        $project = Project::factory()->create([
            'client_id' => $this->client->id,
            'hourly_rate' => 100,
        ]);
        $timeEntry = new TimeEntry;
        $timeEntry->fill([
            'start_time' => now()->subHours(2),
            'end_time' => now(),
            'description' => 'Consulting work',
            'billable' => true,
            'status' => TimeEntry::STATUS_APPROVED,
        ]);
        $timeEntry->user_id = $this->user->id;
        $timeEntry->project_id = $project->id;
        $timeEntry->save();

        $response = $this->actingAs($this->user)->post('/invoices', [
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'items' => [
                [
                    'description' => 'Manual line',
                    'quantity' => 1,
                    'unit_price' => 100,
                    'tax_rate' => 10,
                ],
            ],
            'time_entry_ids' => [$timeEntry->id],
        ]);

        $response->assertSessionHas('success');

        $invoice = Invoice::where('client_id', $this->client->id)->first();
        $this->assertCount(2, $invoice->items);

        $linked = $invoice->items->firstWhere('time_entry_id', $timeEntry->id);
        $this->assertNotNull($linked, 'Checked entry must produce a linked invoice item.');
        $this->assertEquals(2, (float) $linked->quantity);
        $this->assertEquals(100, (float) $linked->unit_price);
        $this->assertEquals($project->name.' - Consulting work', $linked->description);
        $this->assertEquals(1, $linked->sort_order, 'Entry line follows manual lines.');

        // Manual 100 + entry 2h @ 100 = 200, GST 10% on 300.
        $this->assertEquals(300.00, (float) $invoice->subtotal);
        $this->assertEquals(330.00, (float) $invoice->total);

        $this->assertTrue($timeEntry->invoiceItem()->exists());
    }

    public function test_store_creates_invoice_from_time_entries_without_manual_items(): void
    {
        $project = Project::factory()->create([
            'client_id' => $this->client->id,
            'hourly_rate' => 150,
        ]);
        $timeEntry = new TimeEntry;
        $timeEntry->fill([
            'start_time' => now()->subHours(3),
            'end_time' => now(),
            'description' => 'Design work',
            'billable' => true,
            'status' => TimeEntry::STATUS_APPROVED,
        ]);
        $timeEntry->user_id = $this->user->id;
        $timeEntry->project_id = $project->id;
        $timeEntry->save();

        $response = $this->actingAs($this->user)->post('/invoices', [
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'time_entry_ids' => [$timeEntry->id],
        ]);

        $response->assertSessionHas('success');
        $invoice = Invoice::where('client_id', $this->client->id)->first();
        $this->assertCount(1, $invoice->items);
        $this->assertEquals($timeEntry->id, $invoice->items[0]->time_entry_id);
        $this->assertEquals(450.00, (float) $invoice->subtotal);
    }

    public function test_store_rejects_uninvoiceable_time_entries(): void
    {
        $project = Project::factory()->create(['client_id' => $this->client->id]);

        $draftEntry = new TimeEntry;
        $draftEntry->fill([
            'start_time' => now()->subHours(1),
            'end_time' => now(),
            'status' => TimeEntry::STATUS_DRAFT,
            'billable' => true,
        ]);
        $draftEntry->user_id = $this->user->id;
        $draftEntry->project_id = $project->id;
        $draftEntry->save();

        $unbillableEntry = new TimeEntry;
        $unbillableEntry->fill([
            'start_time' => now()->subHours(1),
            'end_time' => now(),
            'status' => TimeEntry::STATUS_APPROVED,
            'billable' => false,
        ]);
        $unbillableEntry->user_id = $this->user->id;
        $unbillableEntry->project_id = $project->id;
        $unbillableEntry->save();

        $invoicedEntry = new TimeEntry;
        $invoicedEntry->fill([
            'start_time' => now()->subHours(1),
            'end_time' => now(),
            'status' => TimeEntry::STATUS_APPROVED,
            'billable' => true,
        ]);
        $invoicedEntry->user_id = $this->user->id;
        $invoicedEntry->project_id = $project->id;
        $invoicedEntry->save();
        $otherInvoice = new Invoice;
        $otherInvoice->fill([
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => Invoice::STATUS_DRAFT,
        ]);
        $otherInvoice->client_id = $this->client->id;
        $otherInvoice->save();
        $otherItem = $otherInvoice->items()->make([
            'description' => 'Already billed',
            'quantity' => 1,
            'unit_price' => 50,
            'tax_rate' => 10,
        ]);
        // time_entry_id is an unfillable FK — link it explicitly.
        $otherItem->time_entry_id = $invoicedEntry->id;
        $otherItem->save();

        $response = $this->actingAs($this->user)->post('/invoices', [
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'items' => [
                ['description' => 'Manual line', 'quantity' => 1, 'unit_price' => 100, 'tax_rate' => 10],
            ],
            'time_entry_ids' => [$draftEntry->id, $unbillableEntry->id, $invoicedEntry->id],
        ]);

        $response->assertSessionHas('errors');
        // Only the pre-existing invoice; no new invoice created.
        $this->assertDatabaseCount('invoices', 1);
        $errors = session('errors')->get('time_entry_ids');
        $this->assertStringContainsString("#{$draftEntry->id} is not approved", implode(' ', (array) $errors));
        $this->assertStringContainsString("#{$unbillableEntry->id} is not billable", implode(' ', (array) $errors));
        $this->assertStringContainsString("#{$invoicedEntry->id} is already invoiced", implode(' ', (array) $errors));
    }
}

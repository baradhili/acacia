<?php

namespace Tests\Feature;

use App\Console\Commands\MarkOverdueInvoices;
use App\Mail\InvoiceMail;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class InvoiceAdvancedTest extends TestCase
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

    public function test_automatic_overdue_marking_via_cron_for_invoices_past_due_date(): void
    {
        // Create a sent invoice past due date
        $overdueInvoice = Invoice::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->subDays(60)->toDateString(),
            'due_date' => now()->subDays(30)->toDateString(),
            'status' => Invoice::STATUS_SENT,
        ]);

        // Create a sent invoice not yet due
        $currentInvoice = Invoice::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->subDays(10)->toDateString(),
            'due_date' => now()->addDays(20)->toDateString(),
            'status' => Invoice::STATUS_SENT,
        ]);

        // Run the cron command
        Artisan::call('invoices:mark-overdue');

        $overdueInvoice->refresh();
        $currentInvoice->refresh();

        // Overdue invoice should be marked as overdue
        $this->assertEquals(Invoice::STATUS_OVERDUE, $overdueInvoice->status);

        // Current invoice should not be marked overdue
        $this->assertEquals(Invoice::STATUS_SENT, $currentInvoice->status);
    }

    public function test_invoice_email_is_dispatched_when_marking_as_sent(): void
    {
        Mail::fake();

        $invoice = Invoice::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => Invoice::STATUS_DRAFT,
        ]);

        // Send the invoice via the controller
        $response = $this->actingAs($this->user)->post(route('invoices.send', $invoice));

        $response->assertSessionHas('success');

        // Verify email was dispatched
        Mail::assertSent(InvoiceMail::class, function ($mail) use ($invoice) {
            return $mail->invoice->id === $invoice->id;
        });
    }

    public function test_invoice_pdf_view_renders(): void
    {
        $invoice = Invoice::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => Invoice::STATUS_DRAFT,
        ]);

        $invoice->items()->create([
            'description' => 'Consulting Services',
            'quantity' => 10,
            'unit_price' => 150,
            'tax_rate' => 10,
        ]);

        // Generate PDF view
        $response = $this->actingAs($this->user)->get(route('invoices.pdf', $invoice));

        $response->assertStatus(200);
    }

    public function test_invoice_cancellation_allowed_in_sent_state(): void
    {
        // Sent invoice can be cancelled according to transitions
        $sentInvoice = Invoice::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => Invoice::STATUS_SENT,
        ]);

        // Can transition to cancelled
        $this->assertTrue($sentInvoice->canBeCancelled());

        // Paid invoice cannot be cancelled
        $paidInvoice = Invoice::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => Invoice::STATUS_PAID,
        ]);

        $this->assertFalse($paidInvoice->canBeCancelled());

        $response = $this->actingAs($this->user)->post(route('invoices.cancel', $paidInvoice));
        $response->assertSessionHas('error');
    }

    public function test_complete_invoice_status_transitions_draft_to_overdue(): void
    {
        $invoice = Invoice::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => Invoice::STATUS_DRAFT,
        ]);

        // Draft -> Sent
        $invoice->markAsSent();
        $this->assertEquals(Invoice::STATUS_SENT, $invoice->status);

        // Sent -> Viewed
        $invoice->markAsViewed();
        $this->assertEquals(Invoice::STATUS_VIEWED, $invoice->status);

        // Viewed -> Partially Paid
        $invoice->update(['status' => Invoice::STATUS_PARTIALLY_PAID]);
        $this->assertEquals(Invoice::STATUS_PARTIALLY_PAID, $invoice->status);

        // Partially Paid -> Paid
        $invoice->update(['status' => Invoice::STATUS_PAID]);
        $this->assertEquals(Invoice::STATUS_PAID, $invoice->status);
    }

    public function test_invalid_invoice_status_transitions_are_prevented(): void
    {
        $invoice = Invoice::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => Invoice::STATUS_DRAFT,
        ]);

        // Cannot transition from draft directly to paid
        $this->assertFalse($invoice->canTransitionTo(Invoice::STATUS_PAID));

        // Cannot transition from cancelled to any state
        $invoice->update(['status' => Invoice::STATUS_CANCELLED]);
        $this->assertFalse($invoice->canTransitionTo(Invoice::STATUS_SENT));
        $this->assertFalse($invoice->canTransitionTo(Invoice::STATUS_PAID));
    }

    public function test_invoice_overdue_status_transition(): void
    {
        $invoice = Invoice::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->subDays(60)->toDateString(),
            'due_date' => now()->subDays(30)->toDateString(),
            'status' => Invoice::STATUS_SENT,
        ]);

        // Transition to overdue using transitionTo
        $invoice->transitionTo(Invoice::STATUS_OVERDUE);
        $this->assertEquals(Invoice::STATUS_OVERDUE, $invoice->status);

        // Overdue can transition to paid
        $invoice->update(['status' => Invoice::STATUS_PAID]);
        $this->assertEquals(Invoice::STATUS_PAID, $invoice->status);
    }
}

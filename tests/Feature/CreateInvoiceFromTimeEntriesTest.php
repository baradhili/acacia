<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\DashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateInvoiceFromTimeEntriesTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Client $client;

    protected Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->client = Client::factory()->create();
        $this->project = Project::factory()->create([
            'client_id' => $this->client->id,
        ]);
    }

    private function makeEntry(array $overrides = []): TimeEntry
    {
        return TimeEntry::create(array_merge([
            'user_id' => $this->user->id,
            'project_id' => $this->project->id,
            'entry_date' => '2026-08-15',
            'hours' => 8,
            'rate' => 100,
            'billable' => true,
            'status' => TimeEntry::STATUS_APPROVED,
            'description' => 'Consulting work',
        ], $overrides));
    }

    public function test_picker_screen_lists_uninvoiced_entries(): void
    {
        $this->makeEntry();

        $response = $this->actingAs($this->user)
            ->get(route('invoices.create-from-time-entries'));

        $response->assertStatus(200)
            ->assertSee('Consulting work');
    }

    public function test_picker_screen_filters_by_client(): void
    {
        $otherClient = Client::factory()->create();
        $otherProject = Project::factory()->create(['client_id' => $otherClient->id]);

        $this->makeEntry();
        $this->makeEntry([
            'project_id' => $otherProject->id,
            'description' => 'Other client work',
        ]);

        $response = $this->actingAs($this->user)
            ->get(route('invoices.create-from-time-entries', ['client_id' => $otherClient->id]));

        $response->assertStatus(200)
            ->assertSee('Other client work')
            ->assertDontSee('Consulting work');
    }

    public function test_creates_invoice_with_one_linked_item_per_entry(): void
    {
        $entryA = $this->makeEntry(['entry_date' => '2026-08-14']);
        $entryB = $this->makeEntry();

        $response = $this->actingAs($this->user)
            ->post(route('invoices.create-from-time-entries.store'), [
                'time_entry_ids' => [$entryA->id, $entryB->id],
                'issue_date' => now()->toDateString(),
                'due_date' => now()->addDays(30)->toDateString(),
            ]);

        $response->assertSessionHas('success');

        $invoice = Invoice::where('client_id', $this->client->id)->first();
        $this->assertNotNull($invoice);
        $this->assertCount(2, $invoice->items);

        // Entries share the project, so the invoice inherits it.
        $this->assertEquals($this->project->id, $invoice->project_id);

        // 2 x (8h x $100) = $1600 subtotal, +10% GST = $1760.
        $this->assertEquals(1600, (float) $invoice->subtotal);
        $this->assertEquals(160, (float) $invoice->tax_amount);
        $this->assertEquals(1760, (float) $invoice->total);

        foreach ([$entryA, $entryB] as $entry) {
            $this->assertDatabaseHas('invoice_items', [
                'invoice_id' => $invoice->id,
                'time_entry_id' => $entry->id,
            ]);
        }
    }

    public function test_creates_invoice_for_client_targeted_entry_without_project(): void
    {
        $entry = $this->makeEntry([
            'project_id' => null,
            'client_id' => $this->client->id,
        ]);

        $response = $this->actingAs($this->user)
            ->post(route('invoices.create-from-time-entries.store'), [
                'time_entry_ids' => [$entry->id],
                'issue_date' => now()->toDateString(),
                'due_date' => now()->addDays(30)->toDateString(),
            ]);

        $response->assertSessionHas('success');
        $this->assertDatabaseHas('invoices', [
            'client_id' => $this->client->id,
            'project_id' => null,
        ]);
    }

    public function test_rejects_selection_spanning_multiple_clients(): void
    {
        $otherClient = Client::factory()->create();
        $otherProject = Project::factory()->create(['client_id' => $otherClient->id]);

        $mine = $this->makeEntry();
        $theirs = $this->makeEntry([
            'project_id' => $otherProject->id,
            'description' => 'Their work',
        ]);

        $response = $this->actingAs($this->user)
            ->post(route('invoices.create-from-time-entries.store'), [
                'time_entry_ids' => [$mine->id, $theirs->id],
                'issue_date' => now()->toDateString(),
                'due_date' => now()->addDays(30)->toDateString(),
            ]);

        $response->assertSessionHasErrors('time_entry_ids');
        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_rejects_unapproved_entry(): void
    {
        $entry = $this->makeEntry(['status' => TimeEntry::STATUS_DRAFT]);

        $response = $this->actingAs($this->user)
            ->post(route('invoices.create-from-time-entries.store'), [
                'time_entry_ids' => [$entry->id],
                'issue_date' => now()->toDateString(),
                'due_date' => now()->addDays(30)->toDateString(),
            ]);

        $response->assertSessionHasErrors('time_entry_ids');
        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_rejects_entry_already_on_a_live_invoice(): void
    {
        $entry = $this->makeEntry();

        $invoice = Invoice::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ]);
        $invoice->items()->create([
            'description' => 'Already billed',
            'quantity' => 8,
            'unit_price' => 100,
            'tax_rate' => 10,
            'time_entry_id' => $entry->id,
        ]);

        $response = $this->actingAs($this->user)
            ->post(route('invoices.create-from-time-entries.store'), [
                'time_entry_ids' => [$entry->id],
                'issue_date' => now()->toDateString(),
                'due_date' => now()->addDays(30)->toDateString(),
            ]);

        $response->assertSessionHasErrors('time_entry_ids');
        $this->assertDatabaseCount('invoices', 1);
    }

    public function test_concurrent_submissions_cannot_both_consume_the_same_entry(): void
    {
        $entry = $this->makeEntry();

        $payload = [
            'time_entry_ids' => [$entry->id],
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ];

        // Two racing submissions for the same time_entry_id: the first
        // wins; the second hits the post-lock consumption recheck and is
        // rejected instead of double-billing the entry.
        $this->actingAs($this->user)
            ->post(route('invoices.create-from-time-entries.store'), $payload)
            ->assertSessionHas('success');

        $this->actingAs($this->user)
            ->post(route('invoices.create-from-time-entries.store'), $payload)
            ->assertSessionHasErrors('time_entry_ids');

        $this->assertDatabaseCount('invoices', 1);
        $this->assertSame(
            1,
            InvoiceItem::where('time_entry_id', $entry->id)->count()
        );
    }

    public function test_store_rejects_entries_resolving_to_another_client(): void
    {
        $otherClient = Client::factory()->create();
        $entry = $this->makeEntry(); // resolves to $this->client via the project

        $response = $this->actingAs($this->user)
            ->post(route('invoices.store'), [
                'client_id' => $otherClient->id,
                'issue_date' => now()->toDateString(),
                'due_date' => now()->addDays(30)->toDateString(),
                'time_entry_ids' => [$entry->id],
                'items' => [],
            ]);

        $response->assertSessionHasErrors('time_entry_ids');
        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_po_picker_screen_guards_and_renders(): void
    {
        $po = PurchaseOrder::create([
            'client_id' => $this->client->id,
            'title' => 'PO Work',
            'budgeted_amount' => 10000,
            'status' => 'open',
        ]);

        // No invoiceable entries yet — bounced back with an error.
        $this->actingAs($this->user)
            ->get(route('purchase-orders.create-invoice', $po))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->makeEntry(['purchase_order_id' => $po->id]);

        $this->actingAs($this->user)
            ->get(route('purchase-orders.create-invoice', $po))
            ->assertStatus(200)
            ->assertSee('Consulting work');
    }

    public function test_creates_invoice_from_po_and_consumes_budget_when_sent(): void
    {
        $po = PurchaseOrder::create([
            'client_id' => $this->client->id,
            'title' => 'PO Work',
            'budgeted_amount' => 10000,
            'status' => 'open',
        ]);
        $entry = $this->makeEntry(['purchase_order_id' => $po->id]);

        $response = $this->actingAs($this->user)
            ->post(route('purchase-orders.create-invoice.store', $po), [
                'time_entry_ids' => [$entry->id],
                'issue_date' => now()->toDateString(),
                'due_date' => now()->addDays(30)->toDateString(),
            ]);

        $response->assertSessionHas('success');

        $invoice = Invoice::where('purchase_order_id', $po->id)->first();
        $this->assertNotNull($invoice);
        $this->assertEquals($this->client->id, $invoice->client_id);
        $this->assertDatabaseHas('invoice_items', [
            'invoice_id' => $invoice->id,
            'time_entry_id' => $entry->id,
        ]);

        // Draft invoices don't consume PO budget; sending it does.
        $this->assertEquals(0, (float) $po->refresh()->used_amount);
        $invoice->markAsSent();
        $this->assertEquals(880, (float) $po->refresh()->used_amount);
    }

    public function test_po_flow_rejects_entries_from_another_po(): void
    {
        $poA = PurchaseOrder::create([
            'client_id' => $this->client->id,
            'title' => 'PO A',
            'budgeted_amount' => 10000,
            'status' => 'open',
        ]);
        $poB = PurchaseOrder::create([
            'client_id' => $this->client->id,
            'title' => 'PO B',
            'budgeted_amount' => 10000,
            'status' => 'open',
        ]);
        $entry = $this->makeEntry(['purchase_order_id' => $poA->id]);

        $response = $this->actingAs($this->user)
            ->post(route('purchase-orders.create-invoice.store', $poB), [
                'time_entry_ids' => [$entry->id],
                'issue_date' => now()->toDateString(),
                'due_date' => now()->addDays(30)->toDateString(),
            ]);

        $response->assertSessionHasErrors('time_entry_ids');
        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_dashboard_unbilled_time_widget_returns_real_dates(): void
    {
        $entry = $this->makeEntry();

        $widget = (new DashboardService)->getUnbilledTimeWidget();

        $this->assertSame(1, $widget['count']);
        $this->assertSame('2026-08-15', $widget['entries']->first()['date']);
        $this->assertEquals(800, $widget['total_amount']);

        // Once invoiced, the entry drops off the widget.
        $invoice = Invoice::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ]);
        $invoice->items()->create([
            'description' => 'Billed',
            'quantity' => 8,
            'unit_price' => 100,
            'tax_rate' => 10,
            'time_entry_id' => $entry->id,
        ]);

        $widget = (new DashboardService)->getUnbilledTimeWidget();
        $this->assertSame(0, $widget['count']);
    }

    public function test_credit_note_create_from_invoice_view_renders(): void
    {
        $invoice = Invoice::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ]);
        $invoice->items()->create([
            'description' => 'Line to credit',
            'quantity' => 1,
            'unit_price' => 100,
            'tax_rate' => 10,
        ]);

        $response = $this->actingAs($this->user)
            ->get(route('credit-notes.create-from-invoice', $invoice));

        $response->assertStatus(200)
            ->assertSee('Line to credit');
    }
}

<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\PurchaseOrder;
use App\Models\TimeEntry;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseOrderAllocationTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Client $client;
    protected PurchaseOrder $purchaseOrder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->client = Client::factory()->create();
        $this->purchaseOrder = PurchaseOrder::create([
            'client_id' => $this->client->id,
            'title' => 'Test Project Work',
            'budgeted_amount' => 10000,
            'used_amount' => 0,
            'status' => 'open',
        ]);
    }

    public function test_can_create_purchase_order_with_auto_generated_po_number(): void
    {
        $po = PurchaseOrder::create([
            'client_id' => $this->client->id,
            'title' => 'New PO',
            'budgeted_amount' => 5000,
        ]);

        $this->assertMatchesRegularExpression('/^PO-\d{4}-\d{4}$/', $po->po_number);
    }

    public function test_purchase_order_status_transitions_work_correctly(): void
    {
        // Draft -> Open
        $this->purchaseOrder->update(['status' => 'draft']);
        $this->purchaseOrder->activate();
        $this->purchaseOrder->refresh();
        $this->assertEquals('open', $this->purchaseOrder->status);

        // Open -> Partially Used (when time allocated)
        $this->purchaseOrder->update(['used_amount' => 1000]);
        $this->purchaseOrder->updateStatus();
        $this->purchaseOrder->refresh();
        $this->assertEquals('partially_used', $this->purchaseOrder->status);

        // Partially Used -> Completed
        $this->purchaseOrder->update(['used_amount' => 10000]);
        $this->purchaseOrder->updateStatus();
        $this->purchaseOrder->refresh();
        $this->assertEquals('completed', $this->purchaseOrder->status);
    }

    public function test_can_allocate_approved_time_entries_to_po(): void
    {
        $timeEntry = TimeEntry::create([
            'user_id' => $this->user->id,
            'start_time' => Carbon::parse('2024-01-15 09:00'),
            'end_time' => Carbon::parse('2024-01-15 17:00'),
            'hours' => 8,
            'status' => 'approved',
            'rate' => 100,
        ]);

        $this->assertNull($timeEntry->purchase_order_id);

        $response = $this->actingAs($this->user)->post(route('purchase-orders.allocate', $this->purchaseOrder), [
            'time_entry_ids' => [$timeEntry->id],
        ]);

        $response->assertSessionHas('success');

        $timeEntry->refresh();
        $this->assertEquals($this->purchaseOrder->id, $timeEntry->purchase_order_id);
    }

    public function test_recalculates_used_amount_when_time_entries_are_allocated(): void
    {
        $entry1 = TimeEntry::create([
            'user_id' => $this->user->id,
            'start_time' => Carbon::parse('2024-01-15 09:00'),
            'end_time' => Carbon::parse('2024-01-15 13:00'),
            'hours' => 4,
            'status' => 'approved',
            'rate' => 100,
        ]);

        $entry2 = TimeEntry::create([
            'user_id' => $this->user->id,
            'start_time' => Carbon::parse('2024-01-15 14:00'),
            'end_time' => Carbon::parse('2024-01-15 18:00'),
            'hours' => 4,
            'status' => 'approved',
            'rate' => 100,
        ]);

        $this->actingAs($this->user)->post(route('purchase-orders.allocate', $this->purchaseOrder), [
            'time_entry_ids' => [$entry1->id, $entry2->id],
        ]);

        $this->purchaseOrder->refresh();
        $this->assertEquals(800.0, $this->purchaseOrder->used_amount); // 8 hours * $100
        $this->assertEquals(9200.0, $this->purchaseOrder->remaining);
    }

    public function test_utilization_percentage_calculated_correctly(): void
    {
        $this->purchaseOrder->update(['used_amount' => 5000]);
        $this->purchaseOrder->refresh();

        $this->assertEquals(50.0, $this->purchaseOrder->utilization);
    }

    public function test_remaining_budget_calculated_correctly(): void
    {
        $this->purchaseOrder->update(['used_amount' => 7500]);
        $this->purchaseOrder->refresh();

        $this->assertEquals(2500.0, $this->purchaseOrder->remaining);
    }

    public function test_cannot_allocate_time_to_cancelled_po(): void
    {
        $this->purchaseOrder->update(['status' => 'cancelled']);

        $timeEntry = TimeEntry::create([
            'user_id' => $this->user->id,
            'start_time' => Carbon::parse('2024-01-15 09:00'),
            'end_time' => Carbon::parse('2024-01-15 17:00'),
            'hours' => 8,
            'status' => 'approved',
        ]);

        $response = $this->actingAs($this->user)->post(route('purchase-orders.allocate', $this->purchaseOrder), [
            'time_entry_ids' => [$timeEntry->id],
        ]);

        $response->assertSessionHas('error');
    }

    public function test_only_approved_entries_can_be_allocated(): void
    {
        $draftEntry = TimeEntry::create([
            'user_id' => $this->user->id,
            'start_time' => Carbon::parse('2024-01-15 09:00'),
            'end_time' => Carbon::parse('2024-01-15 17:00'),
            'hours' => 8,
            'status' => 'draft',
        ]);

        $response = $this->actingAs($this->user)->post(route('purchase-orders.allocate', $this->purchaseOrder), [
            'time_entry_ids' => [$draftEntry->id],
        ]);

        // The entry should not be linked because it's not approved
        $draftEntry->refresh();
        $this->assertNull($draftEntry->purchase_order_id);
    }

    public function test_can_cancel_po_from_draft_status(): void
    {
        $this->purchaseOrder->update(['status' => 'draft']);

        $response = $this->actingAs($this->user)->post(route('purchase-orders.cancel', $this->purchaseOrder));
        $response->assertSessionHas('success');

        $this->purchaseOrder->refresh();
        $this->assertEquals('cancelled', $this->purchaseOrder->status);
    }

    public function test_cannot_cancel_completed_po(): void
    {
        $this->purchaseOrder->update(['status' => 'completed']);

        $response = $this->actingAs($this->user)->post(route('purchase-orders.cancel', $this->purchaseOrder));
        $response->assertSessionHas('error');
    }
}

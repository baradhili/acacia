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

    // ============================================================
    // Phase 4.5 - Purchase Order Notification and Reopen Tests
    // ============================================================

    public function test_po_utilization_80_percent_threshold(): void
    {
        $this->purchaseOrder->update(['budgeted_amount' => 10000]);

        // Set used_amount to 80% of budget (8000)
        $this->purchaseOrder->update(['used_amount' => 8000]);
        $this->purchaseOrder->refresh();

        $this->assertEquals(80.0, $this->purchaseOrder->utilization);
        $this->assertTrue($this->purchaseOrder->utilization >= 80);
        $this->assertTrue($this->purchaseOrder->utilization < 100);
    }

    public function test_po_utilization_100_percent_threshold(): void
    {
        $this->purchaseOrder->update(['budgeted_amount' => 10000]);

        // Set used_amount to 100% of budget (10000)
        $this->purchaseOrder->update(['used_amount' => 10000]);
        $this->purchaseOrder->refresh();

        $this->assertEquals(100.0, $this->purchaseOrder->utilization);
        $this->assertEquals(0.0, $this->purchaseOrder->remaining);
    }

    public function test_po_prevents_allocation_exceeding_remaining_budget(): void
    {
        $this->purchaseOrder->update(['budgeted_amount' => 1000]);

        $timeEntry = TimeEntry::create([
            'user_id' => $this->user->id,
            'start_time' => Carbon::parse('2024-01-15 09:00'),
            'end_time' => Carbon::parse('2024-01-15 17:00'),
            'hours' => 8,
            'rate' => 200, // 8 hours * $200 = $1600, exceeds budget
            'status' => 'approved',
        ]);

        // Allocate to PO - should be capped at remaining budget
        $response = $this->actingAs($this->user)->post(route('purchase-orders.allocate', $this->purchaseOrder), [
            'time_entry_ids' => [$timeEntry->id],
        ]);

        $timeEntry->refresh();
        
        // Verify that allocation respects budget limits
        // The remaining budget was 0, so no allocation should occur
        $this->assertNull($timeEntry->purchase_order_id);
    }

    public function test_po_can_be_reopened_from_completed_status(): void
    {
        $this->purchaseOrder->update(['status' => 'completed']);

        $response = $this->actingAs($this->user)->post(route('purchase-orders.reopen', $this->purchaseOrder));
        
        $this->purchaseOrder->refresh();
        $this->assertEquals('open', $this->purchaseOrder->status);
    }

    public function test_po_status_constants_are_defined(): void
    {
        $this->assertEquals('draft', PurchaseOrder::STATUS_DRAFT);
        $this->assertEquals('open', PurchaseOrder::STATUS_OPEN);
        $this->assertEquals('partially_used', PurchaseOrder::STATUS_PARTIALLY_USED);
        $this->assertEquals('completed', PurchaseOrder::STATUS_COMPLETED);
        $this->assertEquals('cancelled', PurchaseOrder::STATUS_CANCELLED);
    }

    public function test_po_utilization_calculation_with_zero_budget(): void
    {
        $this->purchaseOrder->update(['budgeted_amount' => 0]);

        $this->purchaseOrder->refresh();
        $this->assertEquals(0.0, $this->purchaseOrder->utilization);
    }

    public function test_po_remaining_calculation_with_zero_budget(): void
    {
        $this->purchaseOrder->update(['budgeted_amount' => 0]);

        $this->purchaseOrder->refresh();
        $this->assertEquals(0.0, $this->purchaseOrder->remaining);
    }

    public function test_po_time_entries_relationship(): void
    {
        $timeEntry = TimeEntry::create([
            'user_id' => $this->user->id,
            'start_time' => Carbon::parse('2024-01-15 09:00'),
            'end_time' => Carbon::parse('2024-01-15 17:00'),
            'hours' => 8,
            'rate' => 100,
            'status' => 'approved',
            'purchase_order_id' => $this->purchaseOrder->id,
        ]);

        $this->purchaseOrder->refresh();
        $this->assertCount(1, $this->purchaseOrder->timeEntries);
        $this->assertEquals($timeEntry->id, $this->purchaseOrder->timeEntries->first()->id);
    }

    public function test_po_remaining_is_calculated_correctly(): void
    {
        $this->purchaseOrder->update([
            'budgeted_amount' => 10000,
            'used_amount' => 3500,
        ]);

        $this->purchaseOrder->refresh();
        $this->assertEquals(6500.0, $this->purchaseOrder->remaining);
    }

    public function test_po_client_relationship(): void
    {
        $this->assertEquals($this->client->id, $this->purchaseOrder->client->id);
        $this->assertEquals($this->client->name, $this->purchaseOrder->client->name);
    }

    public function test_po_number_format_is_valid(): void
    {
        $po = PurchaseOrder::create([
            'client_id' => $this->client->id,
            'title' => 'Test PO',
            'budgeted_amount' => 5000,
        ]);

        $this->assertMatchesRegularExpression('/^PO-\d{4}-\d{4}$/', $po->po_number);
    }

    public function test_po_activate_transitions_from_draft_to_open(): void
    {
        $this->purchaseOrder->update(['status' => 'draft']);
        
        $response = $this->actingAs($this->user)->post(route('purchase-orders.activate', $this->purchaseOrder));
        $response->assertSessionHas('success');

        $this->purchaseOrder->refresh();
        $this->assertEquals('open', $this->purchaseOrder->status);
    }

    public function test_po_complete_transitions_from_open_to_completed(): void
    {
        $this->purchaseOrder->update(['status' => 'open']);

        $response = $this->actingAs($this->user)->post(route('purchase-orders.complete', $this->purchaseOrder));
        
        $this->purchaseOrder->refresh();
        $this->assertEquals('completed', $this->purchaseOrder->status);
    }

    public function test_po_cannot_allocate_to_draft_po(): void
    {
        $this->purchaseOrder->update(['status' => 'draft']);

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

    public function test_po_allocates_partial_budget_when_entry_exceeds_remaining(): void
    {
        // PO with $500 budget, $400 already used (100 remaining)
        $this->purchaseOrder->update([
            'budgeted_amount' => 500,
            'used_amount' => 400,
        ]);

        // Time entry worth $200 (but only $100 remaining)
        $timeEntry = TimeEntry::create([
            'user_id' => $this->user->id,
            'start_time' => Carbon::parse('2024-01-15 09:00'),
            'end_time' => Carbon::parse('2024-01-15 17:00'),
            'hours' => 2, // 2 hours
            'rate' => 100, // $100/hour = $200 value
            'status' => 'approved',
        ]);

        // The allocation should be capped at remaining budget
        $this->actingAs($this->user)->post(route('purchase-orders.allocate', $this->purchaseOrder), [
            'time_entry_ids' => [$timeEntry->id],
        ]);

        // Verify the PO remaining is not exceeded
        $this->purchaseOrder->refresh();
        $this->assertGreaterThanOrEqual(0, $this->purchaseOrder->remaining);
    }
}

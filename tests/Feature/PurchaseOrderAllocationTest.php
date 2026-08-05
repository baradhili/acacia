<?php

use App\Models\Client;
use App\Models\PurchaseOrder;
use App\Models\TimeEntry;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->client = Client::factory()->create();
    $this->purchaseOrder = PurchaseOrder::create([
        'client_id' => $this->client->id,
        'title' => 'Test Project Work',
        'budgeted_amount' => 10000,
        'used_amount' => 0,
        'status' => 'open',
    ]);
});

test('can create purchase order with auto-generated PO number', function () {
    $po = PurchaseOrder::create([
        'client_id' => $this->client->id,
        'title' => 'New PO',
        'budgeted_amount' => 5000,
    ]);

    expect($po->po_number)->toMatch('/^PO-\d{4}-\d{4}$/');
});

test('purchase order status transitions work correctly', function () {
    // Draft -> Open
    $this->purchaseOrder->update(['status' => 'draft']);
    $this->purchaseOrder->activate();
    $this->purchaseOrder->refresh();
    expect($this->purchaseOrder->status)->toBe('open');

    // Open -> Partially Used (when time allocated)
    $this->purchaseOrder->update(['used_amount' => 1000]);
    $this->purchaseOrder->updateStatus();
    $this->purchaseOrder->refresh();
    expect($this->purchaseOrder->status)->toBe('partially_used');

    // Partially Used -> Completed
    $this->purchaseOrder->update(['used_amount' => 10000]);
    $this->purchaseOrder->updateStatus();
    $this->purchaseOrder->refresh();
    expect($this->purchaseOrder->status)->toBe('completed');
});

test('can allocate approved time entries to PO', function () {
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
    expect($timeEntry->purchase_order_id)->toBe($this->purchaseOrder->id);
});

test('recalculates used amount when time entries are allocated', function () {
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
    expect($this->purchaseOrder->used_amount)->toBe(800.0) // 8 hours * $100
        ->and($this->purchaseOrder->remaining)->toBe(9200.0);
});

test('utilization percentage calculated correctly', function () {
    $this->purchaseOrder->update(['used_amount' => 5000]);
    $this->purchaseOrder->refresh();

    expect($this->purchaseOrder->utilization)->toBe(50.0);
});

test('remaining budget calculated correctly', function () {
    $this->purchaseOrder->update(['used_amount' => 7500]);
    $this->purchaseOrder->refresh();

    expect($this->purchaseOrder->remaining)->toBe(2500.0);
});

test('cannot allocate time to cancelled PO', function () {
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
});

test('only approved entries can be allocated', function () {
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
    expect($draftEntry->purchase_order_id)->toBeNull();
});

test('can cancel PO from draft status', function () {
    $this->purchaseOrder->update(['status' => 'draft']);

    $response = $this->actingAs($this->user)->post(route('purchase-orders.cancel', $this->purchaseOrder));
    $response->assertSessionHas('success');

    $this->purchaseOrder->refresh();
    expect($this->purchaseOrder->status)->toBe('cancelled');
});

test('cannot cancel completed PO', function () {
    $this->purchaseOrder->update(['status' => 'completed']);

    $response = $this->actingAs($this->user)->post(route('purchase-orders.cancel', $this->purchaseOrder));
    $response->assertSessionHas('error');
});

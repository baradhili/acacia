<?php

use App\Models\Client;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->client = Client::factory()->create();
    $this->project = Project::factory()->create([
        'client_id' => $this->client->id,
        'hourly_rate' => 100,
    ]);
});

test('can create time entry with start/end times', function () {
    $response = $this->actingAs($this->user)->post(route('time-entries.store'), [
        'project_id' => $this->project->id,
        'start_time' => '2024-01-15T09:00',
        'end_time' => '2024-01-15T17:00',
        'description' => 'Development work',
        'billable' => true,
    ]);

    $response->assertRedirect(route('time-entries.index'));

    $this->assertDatabaseHas('time_entries', [
        'user_id' => $this->user->id,
        'project_id' => $this->project->id,
        'description' => 'Development work',
        'status' => 'draft',
    ]);

    $entry = TimeEntry::first();
    expect($entry->hours)->toBe(8.0);
});

test('can calculate hours from start/end times', function () {
    $entry = TimeEntry::create([
        'user_id' => $this->user->id,
        'project_id' => $this->project->id,
        'start_time' => Carbon::parse('2024-01-15 09:00'),
        'end_time' => Carbon::parse('2024-01-15 12:30'),
        'description' => 'Morning work',
    ]);

    expect($entry->hours)->toBe(3.5);
});

test('can submit time entry for approval', function () {
    $entry = TimeEntry::create([
        'user_id' => $this->user->id,
        'project_id' => $this->project->id,
        'start_time' => Carbon::parse('2024-01-15 09:00'),
        'end_time' => Carbon::parse('2024-01-15 17:00'),
        'hours' => 8,
        'description' => 'Full day work',
    ]);

    $response = $this->actingAs($this->user)->post(route('time-entries.submit', $entry));
    $response->assertSessionHas('success');

    $entry->refresh();
    expect($entry->status)->toBe('submitted');
});

test('only draft entries can be submitted', function () {
    $entry = TimeEntry::create([
        'user_id' => $this->user->id,
        'project_id' => $this->project->id,
        'start_time' => Carbon::parse('2024-01-15 09:00'),
        'end_time' => Carbon::parse('2024-01-15 17:00'),
        'hours' => 8,
        'status' => 'submitted',
    ]);

    $response = $this->actingAs($this->user)->post(route('time-entries.submit', $entry));
    $response->assertSessionHas('error');
});

test('can approve time entry', function () {
    $approver = User::factory()->create();

    $entry = TimeEntry::create([
        'user_id' => $this->user->id,
        'project_id' => $this->project->id,
        'start_time' => Carbon::parse('2024-01-15 09:00'),
        'end_time' => Carbon::parse('2024-01-15 17:00'),
        'hours' => 8,
        'status' => 'submitted',
    ]);

    $response = $this->actingAs($approver)->post(route('time-entries.approve', $entry));
    $response->assertSessionHas('success');

    $entry->refresh();
    expect($entry->status)->toBe('approved')
        ->and($entry->approved_by)->toBe($approver->id)
        ->and($entry->approved_at)->not->toBeNull();
});

test('can reject time entry with reason', function () {
    $approver = User::factory()->create();

    $entry = TimeEntry::create([
        'user_id' => $this->user->id,
        'project_id' => $this->project->id,
        'start_time' => Carbon::parse('2024-01-15 09:00'),
        'end_time' => Carbon::parse('2024-01-15 17:00'),
        'hours' => 8,
        'status' => 'submitted',
    ]);

    $response = $this->actingAs($approver)->post(route('time-entries.reject', $entry), [
        'reason' => 'Timesheet incomplete',
    ]);
    $response->assertSessionHas('success');

    $entry->refresh();
    expect($entry->status)->toBe('rejected')
        ->and($entry->rejection_reason)->toBe('Timesheet incomplete');
});

test('only draft entries can be edited', function () {
    $entry = TimeEntry::create([
        'user_id' => $this->user->id,
        'project_id' => $this->project->id,
        'start_time' => Carbon::parse('2024-01-15 09:00'),
        'end_time' => Carbon::parse('2024-01-15 17:00'),
        'hours' => 8,
        'status' => 'submitted',
    ]);

    $response = $this->actingAs($this->user)->get(route('time-entries.edit', $entry));
    $response->assertRedirect(route('time-entries.show', $entry))
        ->assertSessionHas('error');
});

test('only draft entries can be deleted', function () {
    $entry = TimeEntry::create([
        'user_id' => $this->user->id,
        'project_id' => $this->project->id,
        'start_time' => Carbon::parse('2024-01-15 09:00'),
        'end_time' => Carbon::parse('2024-01-15 17:00'),
        'hours' => 8,
        'status' => 'approved',
    ]);

    $response = $this->actingAs($this->user)->delete(route('time-entries.destroy', $entry));
    $response->assertSessionHas('error');

    $this->assertDatabaseHas('time_entries', ['id' => $entry->id]);
});

test('total calculated correctly', function () {
    $entry = TimeEntry::create([
        'user_id' => $this->user->id,
        'project_id' => $this->project->id,
        'start_time' => Carbon::parse('2024-01-15 09:00'),
        'end_time' => Carbon::parse('2024-01-15 17:00'),
        'hours' => 8,
    ]);

    expect($entry->total)->toBe(800.0); // 8 hours * $100 hourly rate
});

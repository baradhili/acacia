<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TimeEntryLifecycleTest extends TestCase
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
            'hourly_rate' => 100,
        ]);
    }

    public function test_can_create_time_entry_with_start_end_times(): void
    {
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
        $this->assertEquals(8.0, $entry->hours);
    }

    public function test_can_calculate_hours_from_start_end_times(): void
    {
        $entry = TimeEntry::create([
            'user_id' => $this->user->id,
            'project_id' => $this->project->id,
            'start_time' => Carbon::parse('2024-01-15 09:00'),
            'end_time' => Carbon::parse('2024-01-15 12:30'),
            'description' => 'Morning work',
        ]);

        $this->assertEquals(3.5, $entry->hours);
    }

    public function test_can_submit_time_entry_for_approval(): void
    {
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
        $this->assertEquals('submitted', $entry->status);
    }

    public function test_only_draft_entries_can_be_submitted(): void
    {
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
    }

    public function test_can_approve_time_entry(): void
    {
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
        $this->assertEquals('approved', $entry->status);
        $this->assertEquals($approver->id, $entry->approved_by);
        $this->assertNotNull($entry->approved_at);
    }

    public function test_can_reject_time_entry_with_reason(): void
    {
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
        $this->assertEquals('rejected', $entry->status);
        $this->assertEquals('Timesheet incomplete', $entry->rejection_reason);
    }

    public function test_only_draft_entries_can_be_edited(): void
    {
        $entry = TimeEntry::create([
            'user_id' => $this->user->id,
            'project_id' => $this->project->id,
            'start_time' => Carbon::parse('2024-01-15 09:00'),
            'end_time' => Carbon::parse('2024-01-15 17:00'),
            'hours' => 8,
            'status' => 'submitted',
        ]);

        $response = $this->actingAs($this->user)->get(route('time-entries.edit', $entry));
        $response->assertRedirect(route('time-entries.show', $entry));
        $response->assertSessionHas('error');
    }

    public function test_only_draft_entries_can_be_deleted(): void
    {
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
    }

    public function test_total_calculated_correctly(): void
    {
        $entry = TimeEntry::create([
            'user_id' => $this->user->id,
            'project_id' => $this->project->id,
            'start_time' => Carbon::parse('2024-01-15 09:00'),
            'end_time' => Carbon::parse('2024-01-15 17:00'),
            'hours' => 8,
        ]);

        $this->assertEquals(800.0, $entry->total); // 8 hours * $100 hourly rate
    }
}

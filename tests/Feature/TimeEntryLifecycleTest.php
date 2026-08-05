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

    // ============================================================
    // Phase 4.5 - Timesheet View Tests
    // ============================================================

    public function test_weekly_timesheet_route_requires_authentication(): void
    {
        $response = $this->get(route('timesheets.weekly'));
        $response->assertRedirect('/login');
    }

    public function test_monthly_timesheet_route_requires_authentication(): void
    {
        $response = $this->get(route('timesheets.monthly'));
        $response->assertRedirect('/login');
    }

    public function test_weekly_timesheet_view_shows_current_week_entries(): void
    {
        $this->actingAs($this->user);

        // Create time entry for current week
        TimeEntry::create([
            'user_id' => $this->user->id,
            'project_id' => $this->project->id,
            'start_time' => now()->startOfWeek()->addHours(9),
            'end_time' => now()->startOfWeek()->addHours(17),
            'hours' => 8,
            'description' => 'Week entry',
            'status' => 'approved',
        ]);

        $response = $this->get(route('timesheets.weekly'));

        $response->assertStatus(200);
        $response->assertSee('Week entry');
    }

    public function test_monthly_timesheet_view_shows_current_month_entries(): void
    {
        $this->actingAs($this->user);

        // Create time entry for current month
        TimeEntry::create([
            'user_id' => $this->user->id,
            'project_id' => $this->project->id,
            'start_time' => now()->startOfMonth()->addDays(5)->addHours(9),
            'end_time' => now()->startOfMonth()->addDays(5)->addHours(17),
            'hours' => 8,
            'description' => 'Month entry',
            'status' => 'approved',
        ]);

        $response = $this->get(route('timesheets.monthly'));

        $response->assertStatus(200);
        $response->assertSee('Month entry');
    }

    public function test_weekly_timesheet_totals_hours(): void
    {
        $this->actingAs($this->user);

        // Create multiple entries for current week
        TimeEntry::create([
            'user_id' => $this->user->id,
            'project_id' => $this->project->id,
            'start_time' => now()->startOfWeek()->addHours(9),
            'end_time' => now()->startOfWeek()->addHours(13),
            'hours' => 4,
            'status' => 'approved',
        ]);

        TimeEntry::create([
            'user_id' => $this->user->id,
            'project_id' => $this->project->id,
            'start_time' => now()->startOfWeek()->addDays(1)->addHours(9),
            'end_time' => now()->startOfWeek()->addDays(1)->addHours(17),
            'hours' => 8,
            'status' => 'approved',
        ]);

        $response = $this->get(route('timesheets.weekly'));

        $response->assertStatus(200);
        // Should show total of 12 hours
        $response->assertSee('12');
    }

    public function test_monthly_timesheet_totals_hours(): void
    {
        $this->actingAs($this->user);

        // Create multiple entries for current month
        for ($i = 0; $i < 5; $i++) {
            TimeEntry::create([
                'user_id' => $this->user->id,
                'project_id' => $this->project->id,
                'start_time' => now()->startOfMonth()->addDays($i)->setHour(9),
                'end_time' => now()->startOfMonth()->addDays($i)->setHour(17),
                'hours' => 8,
                'status' => 'approved',
            ]);
        }

        $response = $this->get(route('timesheets.monthly'));

        $response->assertStatus(200);
        // Should show total of 40 hours
        $response->assertSee('40');
    }

    public function test_weekly_timesheet_excludes_other_users_entries(): void
    {
        $otherUser = User::factory()->create();

        $this->actingAs($this->user);

        // Create entry for other user
        TimeEntry::create([
            'user_id' => $otherUser->id,
            'project_id' => $this->project->id,
            'start_time' => now()->startOfWeek()->addHours(9),
            'end_time' => now()->startOfWeek()->addHours(17),
            'hours' => 8,
            'status' => 'approved',
            'description' => 'Other user entry',
        ]);

        $response = $this->get(route('timesheets.weekly'));

        $response->assertStatus(200);
        $response->assertDontSee('Other user entry');
    }

    public function test_monthly_timesheet_excludes_other_users_entries(): void
    {
        $otherUser = User::factory()->create();

        $this->actingAs($this->user);

        // Create entry for other user
        TimeEntry::create([
            'user_id' => $otherUser->id,
            'project_id' => $this->project->id,
            'start_time' => now()->startOfMonth()->addDays(5)->addHours(9),
            'end_time' => now()->startOfMonth()->addDays(5)->addHours(17),
            'hours' => 8,
            'status' => 'approved',
            'description' => 'Other user entry',
        ]);

        $response = $this->get(route('timesheets.monthly'));

        $response->assertStatus(200);
        $response->assertDontSee('Other user entry');
    }

    public function test_time_entry_status_constants_are_defined(): void
    {
        $this->assertEquals('draft', TimeEntry::STATUS_DRAFT);
        $this->assertEquals('submitted', TimeEntry::STATUS_SUBMITTED);
        $this->assertEquals('approved', TimeEntry::STATUS_APPROVED);
        $this->assertEquals('rejected', TimeEntry::STATUS_REJECTED);
    }

    public function test_time_entry_billable_attribute(): void
    {
        $entry = TimeEntry::create([
            'user_id' => $this->user->id,
            'project_id' => $this->project->id,
            'start_time' => Carbon::parse('2024-01-15 09:00'),
            'end_time' => Carbon::parse('2024-01-15 17:00'),
            'hours' => 8,
            'billable' => true,
        ]);

        $this->assertTrue($entry->billable);

        $entry2 = TimeEntry::create([
            'user_id' => $this->user->id,
            'project_id' => $this->project->id,
            'start_time' => Carbon::parse('2024-01-16 09:00'),
            'end_time' => Carbon::parse('2024-01-16 17:00'),
            'hours' => 8,
            'billable' => false,
        ]);

        $this->assertFalse($entry2->billable);
    }

    public function test_time_entry_rejection_reason_is_recorded(): void
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

        $this->actingAs($approver)->post(route('time-entries.reject', $entry), [
            'reason' => 'Timesheet incomplete - missing details',
        ]);

        $entry->refresh();
        $this->assertEquals('Timesheet incomplete - missing details', $entry->rejection_reason);
    }

    public function test_approved_time_entry_has_approved_by_and_timestamp(): void
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

        $this->actingAs($approver)->post(route('time-entries.approve', $entry));

        $entry->refresh();
        $this->assertEquals($approver->id, $entry->approved_by);
        $this->assertNotNull($entry->approved_at);
    }

    public function test_submitted_time_entry_cannot_be_edited(): void
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

    public function test_submitted_time_entry_cannot_be_deleted(): void
    {
        $entry = TimeEntry::create([
            'user_id' => $this->user->id,
            'project_id' => $this->project->id,
            'start_time' => Carbon::parse('2024-01-15 09:00'),
            'end_time' => Carbon::parse('2024-01-15 17:00'),
            'hours' => 8,
            'status' => 'submitted',
        ]);

        $response = $this->actingAs($this->user)->delete(route('time-entries.destroy', $entry));

        $response->assertSessionHas('error');
        $this->assertDatabaseHas('time_entries', ['id' => $entry->id]);
    }

    public function test_approved_time_entry_cannot_be_edited(): void
    {
        $entry = TimeEntry::create([
            'user_id' => $this->user->id,
            'project_id' => $this->project->id,
            'start_time' => Carbon::parse('2024-01-15 09:00'),
            'end_time' => Carbon::parse('2024-01-15 17:00'),
            'hours' => 8,
            'status' => 'approved',
        ]);

        $response = $this->actingAs($this->user)->get(route('time-entries.edit', $entry));

        $response->assertRedirect(route('time-entries.show', $entry));
        $response->assertSessionHas('error');
    }

    public function test_time_entry_project_relationship(): void
    {
        $entry = TimeEntry::create([
            'user_id' => $this->user->id,
            'project_id' => $this->project->id,
            'start_time' => Carbon::parse('2024-01-15 09:00'),
            'end_time' => Carbon::parse('2024-01-15 17:00'),
            'hours' => 8,
        ]);

        $this->assertEquals($this->project->id, $entry->project->id);
    }

    public function test_time_entry_user_relationship(): void
    {
        $entry = TimeEntry::create([
            'user_id' => $this->user->id,
            'project_id' => $this->project->id,
            'start_time' => Carbon::parse('2024-01-15 09:00'),
            'end_time' => Carbon::parse('2024-01-15 17:00'),
            'hours' => 8,
        ]);

        $this->assertEquals($this->user->id, $entry->user->id);
    }
}

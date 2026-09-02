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
            'entry_date' => '2024-01-15',
            'start_time' => '09:00',
            'end_time' => '17:00',
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
        $this->assertEquals('2024-01-15 09:00', $entry->start_time->format('Y-m-d H:i'));
        $this->assertEquals('2024-01-15 17:00', $entry->end_time->format('Y-m-d H:i'));
    }

    public function test_project_client_sync_overrides_a_supplied_client(): void
    {
        $other = Client::factory()->create();

        // A project always wins: even a freshly supplied, non-null
        // client_id cannot desynchronise the denormalised column.
        $entry = TimeEntry::create([
            'user_id' => $this->user->id,
            'project_id' => $this->project->id,
            'client_id' => $other->id,
            'entry_date' => '2024-01-15',
            'hours' => 2,
            'description' => 'Project time for another client',
            'billable' => true,
        ]);

        $this->assertSame($this->client->id, $entry->client_id);

        // Re-saving keeps the column in step even when project_id itself
        // is untouched (e.g. the project's client changed since).
        $this->project->update(['client_id' => $other->id]);
        $entry->save();

        $this->assertSame($other->id, $entry->fresh()->client_id);
    }

    public function test_can_create_manual_hours_entry_without_times(): void
    {
        // The default entry shape: a date and the hours worked, no times.
        $response = $this->actingAs($this->user)->post(route('time-entries.store'), [
            'project_id' => $this->project->id,
            'entry_date' => '2024-01-15',
            'hours' => 6.5,
            'description' => 'Manual day',
        ]);

        $response->assertRedirect(route('time-entries.index'));

        $entry = TimeEntry::first();
        $this->assertNull($entry->start_time);
        $this->assertNull($entry->end_time);
        $this->assertEquals(6.5, (float) $entry->hours);
        $this->assertEquals('2024-01-15', $entry->entry_date->toDateString());
    }

    public function test_hours_required_when_no_times_given(): void
    {
        $response = $this->actingAs($this->user)->post(route('time-entries.store'), [
            'project_id' => $this->project->id,
            'entry_date' => '2024-01-15',
        ]);

        $response->assertSessionHasErrors('hours');
    }

    public function test_breaks_are_deducted_from_timed_entry(): void
    {
        // 09:00–17:00 = 8h, minus 30min lunch and 15min coffee = 7.25h
        $response = $this->actingAs($this->user)->post(route('time-entries.store'), [
            'project_id' => $this->project->id,
            'entry_date' => '2024-01-15',
            'start_time' => '09:00',
            'end_time' => '17:00',
            'hours' => '8.00', // JS-derived; server recomputes with breaks
            'breaks' => [
                ['start' => '12:30', 'end' => '13:00'],
                ['start' => '15:00', 'end' => '15:15'],
            ],
            'description' => 'Full day with breaks',
        ]);

        $response->assertRedirect(route('time-entries.index'));

        $entry = TimeEntry::first();
        $this->assertEquals(7.25, (float) $entry->hours);
        $this->assertEquals(2, $entry->breaks()->count());
    }

    public function test_break_outside_the_timed_span_is_rejected(): void
    {
        $response = $this->actingAs($this->user)->post(route('time-entries.store'), [
            'project_id' => $this->project->id,
            'entry_date' => '2024-01-15',
            'start_time' => '09:00',
            'end_time' => '17:00',
            'hours' => '8.00',
            'breaks' => [
                ['start' => '12:00', 'end' => '18:00'], // ends after the entry
            ],
        ]);

        $response->assertSessionHasErrors('breaks');
    }

    public function test_overlapping_breaks_are_rejected(): void
    {
        $response = $this->actingAs($this->user)->post(route('time-entries.store'), [
            'project_id' => $this->project->id,
            'entry_date' => '2024-01-15',
            'start_time' => '09:00',
            'end_time' => '17:00',
            'hours' => '8.00',
            'breaks' => [
                ['start' => '12:00', 'end' => '13:00'],
                ['start' => '12:30', 'end' => '13:30'],
            ],
        ]);

        $response->assertSessionHasErrors('breaks');
    }

    public function test_breaks_without_times_are_rejected(): void
    {
        $response = $this->actingAs($this->user)->post(route('time-entries.store'), [
            'project_id' => $this->project->id,
            'entry_date' => '2024-01-15',
            'hours' => 8,
            'breaks' => [
                ['start' => '12:00', 'end' => '13:00'],
            ],
        ]);

        $response->assertSessionHasErrors('breaks');
    }

    public function test_updating_times_recomputes_hours_with_breaks(): void
    {
        $entry = TimeEntry::create([
            'user_id' => $this->user->id,
            'project_id' => $this->project->id,
            'entry_date' => '2024-01-15',
            'start_time' => Carbon::parse('2024-01-15 09:00'),
            'end_time' => Carbon::parse('2024-01-15 17:00'),
            'hours' => 8,
        ]);
        $entry->breaks()->createMany([
            ['start_time' => '12:30', 'end_time' => '13:00'],
        ]);
        $entry->recalculateHours();
        $this->assertEquals(7.5, (float) $entry->fresh()->hours);

        // Shorten the day: 09:00–13:00 with the same break = 3.5h
        $response = $this->actingAs($this->user)->put(route('time-entries.update', $entry), [
            'project_id' => $this->project->id,
            'entry_date' => '2024-01-15',
            'start_time' => '09:00',
            'end_time' => '13:00',
            'hours' => '3.50',
            'breaks' => [
                ['start' => '12:30', 'end' => '13:00'],
            ],
        ]);

        $response->assertSessionHas('success');
        $this->assertEquals(3.5, (float) $entry->fresh()->hours);
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

    public function test_time_entry_can_target_a_client_directly(): void
    {
        $response = $this->actingAs($this->user)->post(route('time-entries.store'), [
            'client_id' => $this->client->id,
            'entry_date' => '2024-01-15',
            'hours' => 3,
            'description' => 'Ad-hoc client call',
            'billable' => true,
        ]);

        $response->assertRedirect(route('time-entries.index'));
        $this->assertDatabaseHas('time_entries', [
            'client_id' => $this->client->id,
            'project_id' => null,
            'hours' => 3,
        ]);
    }

    public function test_time_entry_can_be_internal_with_no_target(): void
    {
        $response = $this->actingAs($this->user)->post(route('time-entries.store'), [
            'entry_date' => '2024-01-15',
            'hours' => 2,
            'description' => 'Internal admin',
            'billable' => false,
        ]);

        $response->assertRedirect(route('time-entries.index'));
        $this->assertDatabaseHas('time_entries', [
            'client_id' => null,
            'project_id' => null,
            'billable' => false,
        ]);
    }

    public function test_project_selection_forces_the_projects_client(): void
    {
        $otherClient = Client::factory()->create();

        // Even if the posted client disagrees, the project's client wins.
        $response = $this->actingAs($this->user)->post(route('time-entries.store'), [
            'client_id' => $otherClient->id,
            'project_id' => $this->project->id,
            'entry_date' => '2024-01-15',
            'hours' => 4,
            'billable' => true,
        ]);

        $response->assertRedirect(route('time-entries.index'));
        $this->assertDatabaseHas('time_entries', [
            'project_id' => $this->project->id,
            'client_id' => $this->client->id,
        ]);
    }

    public function test_changing_the_project_updates_the_denormalised_client(): void
    {
        $entry = TimeEntry::create([
            'user_id' => $this->user->id,
            'project_id' => $this->project->id,
            'entry_date' => '2024-01-15',
            'hours' => 4,
            'status' => TimeEntry::STATUS_DRAFT,
        ]);
        $this->assertEquals($this->client->id, $entry->client_id);

        $otherClient = Client::factory()->create();
        $otherProject = Project::factory()->create(['client_id' => $otherClient->id]);

        $entry->update(['project_id' => $otherProject->id]);

        $this->assertEquals($otherClient->id, $entry->refresh()->client_id);
    }

    public function test_create_form_lists_clients_and_projects(): void
    {
        $response = $this->actingAs($this->user)->get(route('time-entries.create'));

        $response->assertOk();
        $response->assertSee('No client (internal time)');
        $response->assertSee($this->project->name);
        // Project options carry their client for the JS auto-fill.
        $response->assertSee('data-client-id="'.$this->client->id.'"', false);
    }
}

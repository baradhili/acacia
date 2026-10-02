<?php

namespace Modules\Practice\Tests;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\ProjectStaff;
use App\Models\TimeEntry;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ReportTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected User $staff;

    protected Client $client;

    protected Project $project;

    /**
     * Time entries with their ownership (user/project/client) assigned
     * directly — those FKs are no longer mass-assignable on the core
     * model, so ::create() would silently drop them and orphan the row.
     */
    protected function createTimeEntry(array $attributes = []): TimeEntry
    {
        $entry = new TimeEntry;
        $entry->fill(collect($attributes)->except(['user_id', 'project_id', 'client_id'])->all());

        foreach (['user_id', 'project_id', 'client_id'] as $fk) {
            if (array_key_exists($fk, $attributes)) {
                $entry->{$fk} = $attributes[$fk];
            }
        }

        $entry->save();

        return $entry;
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Create roles using Spatie
        Role::firstOrCreate(['name' => 'admin']);
        Role::firstOrCreate(['name' => 'accountant']);
        Role::firstOrCreate(['name' => 'staff']);

        $this->user = User::factory()->create();
        $this->user->assignRole('admin');

        $this->staff = User::factory()->create();
        $this->staff->assignRole('staff');

        $this->client = Client::factory()->create();
        $this->project = Project::factory()->create([
            'client_id' => $this->client->id,
            'hourly_rate' => 100,
        ]);
    }

    public function test_time_by_client_route_requires_authentication(): void
    {
        $response = $this->get(route('reports.time-by-client'));
        $response->assertRedirect('/login');
    }

    public function test_time_by_staff_route_requires_authentication(): void
    {
        $response = $this->get(route('reports.time-by-staff'));
        $response->assertRedirect('/login');
    }

    public function test_time_by_client_report_filters_correctly(): void
    {
        $this->actingAs($this->user);

        // Create time entries for the client
        $this->createTimeEntry([
            'user_id' => $this->staff->id,
            'project_id' => $this->project->id,
            'start_time' => Carbon::parse('2024-01-15 09:00'),
            'end_time' => Carbon::parse('2024-01-15 17:00'),
            'hours' => 8,
            'billable' => true,
        ]);

        $response = $this->get(route('reports.time-by-client', [
            'client_id' => $this->client->id,
        ]));

        $response->assertStatus(200);
    }

    public function test_time_by_staff_report_filters_correctly(): void
    {
        $this->actingAs($this->user);

        // Create time entry for staff member
        $this->createTimeEntry([
            'user_id' => $this->staff->id,
            'project_id' => $this->project->id,
            'start_time' => Carbon::parse('2024-01-15 09:00'),
            'end_time' => Carbon::parse('2024-01-15 17:00'),
            'hours' => 8,
            'billable' => true,
        ]);

        $response = $this->get(route('reports.time-by-staff', [
            'user_id' => $this->staff->id,
        ]));

        $response->assertStatus(200);
    }

    public function test_time_report_with_date_range(): void
    {
        $this->actingAs($this->user);

        // One entry inside the requested range, one outside it: the
        // project timesheet must filter on start_date/end_date and
        // honour the project filter.
        $this->createTimeEntry([
            'user_id' => $this->staff->id,
            'project_id' => $this->project->id,
            'client_id' => $this->client->id,
            'entry_date' => '2024-01-10',
            'hours' => 5,
            'billable' => true,
            'status' => TimeEntry::STATUS_APPROVED,
        ]);
        $this->createTimeEntry([
            'user_id' => $this->staff->id,
            'project_id' => $this->project->id,
            'client_id' => $this->client->id,
            'entry_date' => '2024-03-05',
            'hours' => 3,
            'billable' => true,
            'status' => TimeEntry::STATUS_APPROVED,
        ]);

        $response = $this->get(route('reports.project-timesheet', [
            'project_id' => $this->project->id,
            'start_date' => '2024-01-01',
            'end_date' => '2024-02-29',
        ]));

        $response->assertStatus(200);
        $response->assertSee('5.00 hours');  // in-range entry summed
        $response->assertDontSee('3.00');    // out-of-range entry excluded
    }

    public function test_project_profitability_calculation(): void
    {
        $this->actingAs($this->user);

        // Create billable time entries
        $this->createTimeEntry([
            'user_id' => $this->staff->id,
            'project_id' => $this->project->id,
            'start_time' => Carbon::parse('2024-01-15 09:00'),
            'end_time' => Carbon::parse('2024-01-15 17:00'),
            'hours' => 8,
            'rate' => 100, // $100/hour = $800 staff cost
            'billable' => true,
        ]);

        // Create invoice for the project
        $invoice = new Invoice;
        $invoice->fill([
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => Invoice::STATUS_PAID,
        ]);
        $invoice->client_id = $this->client->id;
        $invoice->project_id = $this->project->id;
        $invoice->save();

        $invoice->items()->create([
            'description' => 'Project Work',
            'quantity' => 10,
            'unit_price' => 100,
            'tax_rate' => 10, // $1100 revenue
        ]);

        $response = $this->get(route('projects.profitability.show', $this->project));

        $response->assertStatus(200);
    }

    public function test_project_profitability_costs_staff_rates_not_charge_rates(): void
    {
        // The staff member's assignment costs $60/h while the work
        // charges out at $100/h: cost follows the assignment, revenue
        // the charge rate, and non-billable work still costs.
        $assignment = new ProjectStaff;
        $assignment->fill([
            'hourly_rate' => 60,
            'is_active' => true,
        ]);
        $assignment->project_id = $this->project->id;
        $assignment->user_id = $this->staff->id;
        $assignment->save();

        $this->createTimeEntry([
            'user_id' => $this->staff->id,
            'project_id' => $this->project->id,
            'entry_date' => '2024-01-15',
            'hours' => 4,
            'rate' => 100,
            'billable' => true,
            'status' => TimeEntry::STATUS_APPROVED,
        ]);
        $this->createTimeEntry([
            'user_id' => $this->staff->id,
            'project_id' => $this->project->id,
            'entry_date' => '2024-01-16',
            'hours' => 2,
            'billable' => false,
            'status' => TimeEntry::STATUS_APPROVED,
        ]);

        $response = $this->actingAs($this->user)
            ->get(route('projects.profitability.show', $this->project));

        $response->assertOk()
            ->assertSee('$400.00') // revenue: 4 billable hours @ $100
            ->assertSee('$360.00') // staff cost: 6 hours @ $60
            ->assertSee('$40.00'); // profit
    }

    public function test_staff_user_can_view_own_time_reports(): void
    {
        $this->actingAs($this->staff);

        // Create time entry for this staff
        $this->createTimeEntry([
            'user_id' => $this->staff->id,
            'project_id' => $this->project->id,
            'start_time' => Carbon::parse('2024-01-15 09:00'),
            'end_time' => Carbon::parse('2024-01-15 17:00'),
            'hours' => 8,
            'billable' => true,
        ]);

        $response = $this->get(route('reports.time-by-staff', [
            'user_id' => $this->staff->id,
        ]));

        $response->assertStatus(200);
    }

    public function test_time_report_totals_calculation(): void
    {
        $this->actingAs($this->user);

        // Two half-day entries on the same date: the project
        // timesheet's grand total must sum them.
        $this->createTimeEntry([
            'user_id' => $this->staff->id,
            'project_id' => $this->project->id,
            'client_id' => $this->client->id,
            'entry_date' => '2024-01-15',
            'hours' => 4,
            'rate' => 100,
            'billable' => true,
            'status' => TimeEntry::STATUS_APPROVED,
        ]);

        $this->createTimeEntry([
            'user_id' => $this->staff->id,
            'project_id' => $this->project->id,
            'client_id' => $this->client->id,
            'entry_date' => '2024-01-15',
            'hours' => 4,
            'rate' => 100,
            'billable' => true,
            'status' => TimeEntry::STATUS_APPROVED,
        ]);

        $response = $this->get(route('reports.project-timesheet', [
            'project_id' => $this->project->id,
            'start_date' => '2024-01-01',
            'end_date' => '2024-01-31',
        ]));

        $response->assertStatus(200);
        // Total should be 8 hours
        $response->assertSee('8.00 hours');
    }

    public function test_report_routes_exist(): void
    {
        $this->actingAs($this->user);

        $routes = [
            'reports.time-by-client',
            'reports.time-by-staff',
            'reports.project-timesheet',
        ];

        foreach ($routes as $route) {
            $response = $this->get(route($route));
            // Should not 404
            $this->assertNotEquals(404, $response->status());
        }
    }

    public function test_time_by_client_groups_project_less_entries_under_their_client(): void
    {
        $this->actingAs($this->user);

        // Ad-hoc entry targeted at the client directly, no project.
        $this->createTimeEntry([
            'user_id' => $this->staff->id,
            'client_id' => $this->client->id,
            'entry_date' => '2024-01-16',
            'hours' => 2.5,
            'billable' => true,
            'status' => TimeEntry::STATUS_APPROVED,
        ]);

        $response = $this->get(route('reports.time-by-client', [
            'start_date' => '2024-01-01',
            'end_date' => '2024-01-31',
        ]));

        $response->assertStatus(200);
        $response->assertSee($this->client->name);
        $response->assertSee('2.5');

        // The client filter finds the project-less entry too.
        $filtered = $this->get(route('reports.time-by-client', [
            'start_date' => '2024-01-01',
            'end_date' => '2024-01-31',
            'client_id' => $this->client->id,
        ]));

        $filtered->assertStatus(200);
        $filtered->assertSee($this->client->name);
    }

    public function test_project_timesheet_sums_hours_by_week_and_month(): void
    {
        // Two January 2024 weeks (Mondays 8th and 15th) and one
        // February entry — three distinct weeks across two months.
        foreach (['2024-01-08', '2024-01-10', '2024-01-15', '2024-02-05'] as $date) {
            $this->createTimeEntry([
                'user_id' => $this->staff->id,
                'project_id' => $this->project->id,
                'client_id' => $this->client->id,
                'entry_date' => $date,
                'hours' => 5,
                'rate' => 100,
                'billable' => true,
                'status' => TimeEntry::STATUS_APPROVED,
            ]);
        }

        $response = $this->actingAs($this->user)->get(route('reports.project-timesheet', [
            'start_date' => '2024-01-01',
            'end_date' => '2024-02-29',
        ]));

        $response->assertStatus(200);
        $response->assertSee('By week');
        $response->assertSee('By month');
        $response->assertSee('08 Jan 2024'); // week-of labels
        $response->assertSee('15 Jan 2024');
        $response->assertSee('05 Feb 2024');
        $response->assertSee('Jan 2024');    // month rows
        $response->assertSee('Feb 2024');
        $response->assertSee('20.00 hours'); // grand total across 4 × 5h entries

        // Week sums: 10.00 in each January week, 5.00 in February's.
        $response->assertSee('<td class="py-2 text-right">10.00</td>', false);
        $response->assertSee('<td class="py-2 text-right">5.00</td>', false);

        // Filtering to a different client hides the project entirely.
        $other = Client::factory()->create();
        $filtered = $this->get(route('reports.project-timesheet', [
            'start_date' => '2024-01-01',
            'end_date' => '2024-02-29',
            'client_id' => $other->id,
        ]));

        $filtered->assertStatus(200);
        $filtered->assertSee('No approved project time entries');
    }
}

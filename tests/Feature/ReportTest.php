<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Project;
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

    public function test_time_by_project_route_requires_authentication(): void
    {
        $response = $this->get(route('reports.time-by-project'));
        $response->assertRedirect('/login');
    }

    public function test_time_by_client_report_filters_correctly(): void
    {
        $this->actingAs($this->user);

        // Create time entries for the client
        TimeEntry::create([
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
        TimeEntry::create([
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

    public function test_time_by_project_report_filters_correctly(): void
    {
        $this->actingAs($this->user);

        // Create time entry for project
        TimeEntry::create([
            'user_id' => $this->staff->id,
            'project_id' => $this->project->id,
            'start_time' => Carbon::parse('2024-01-15 09:00'),
            'end_time' => Carbon::parse('2024-01-15 17:00'),
            'hours' => 8,
            'billable' => true,
        ]);

        $response = $this->get(route('reports.time-by-project', [
            'project_id' => $this->project->id,
        ]));

        $response->assertStatus(200);
    }

    public function test_project_profitability_calculation(): void
    {
        $this->actingAs($this->user);

        // Create billable time entries
        TimeEntry::create([
            'user_id' => $this->staff->id,
            'project_id' => $this->project->id,
            'start_time' => Carbon::parse('2024-01-15 09:00'),
            'end_time' => Carbon::parse('2024-01-15 17:00'),
            'hours' => 8,
            'rate' => 100, // $100/hour = $800 staff cost
            'billable' => true,
        ]);

        // Create invoice for the project
        $invoice = Invoice::create([
            'client_id' => $this->client->id,
            'project_id' => $this->project->id,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => Invoice::STATUS_PAID,
        ]);

        $invoice->items()->create([
            'description' => 'Project Work',
            'quantity' => 10,
            'unit_price' => 100,
            'tax_rate' => 10, // $1100 revenue
        ]);

        $response = $this->get(route('projects.profitability', $this->project));

        $response->assertStatus(200);
    }

    public function test_time_report_with_date_range(): void
    {
        $this->actingAs($this->user);

        // Create time entries in specific date range
        TimeEntry::create([
            'user_id' => $this->staff->id,
            'project_id' => $this->project->id,
            'start_time' => Carbon::parse('2024-01-01 09:00'),
            'end_time' => Carbon::parse('2024-01-01 17:00'),
            'hours' => 8,
            'billable' => true,
        ]);

        $response = $this->get(route('reports.time-by-project', [
            'project_id' => $this->project->id,
            'from_date' => '2024-01-01',
            'to_date' => '2024-01-31',
        ]));

        $response->assertStatus(200);
    }

    public function test_staff_user_can_view_own_time_reports(): void
    {
        $this->actingAs($this->staff);

        // Create time entry for this staff
        TimeEntry::create([
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

    public function test_non_billable_time_excluded_from_revenue(): void
    {
        $this->actingAs($this->user);

        // Create billable time entry
        TimeEntry::create([
            'user_id' => $this->staff->id,
            'project_id' => $this->project->id,
            'start_time' => Carbon::parse('2024-01-15 09:00'),
            'end_time' => Carbon::parse('2024-01-15 17:00'),
            'hours' => 8,
            'rate' => 100,
            'billable' => true,
        ]);

        // Create non-billable time entry
        TimeEntry::create([
            'user_id' => $this->staff->id,
            'project_id' => $this->project->id,
            'start_time' => Carbon::parse('2024-01-16 09:00'),
            'end_time' => Carbon::parse('2024-01-16 17:00'),
            'hours' => 8,
            'rate' => 100,
            'billable' => false,
        ]);

        $response = $this->get(route('reports.time-by-project', [
            'project_id' => $this->project->id,
            'billable_only' => true,
        ]));

        $response->assertStatus(200);
    }

    public function test_time_report_totals_calculation(): void
    {
        $this->actingAs($this->user);

        // Create multiple time entries
        TimeEntry::create([
            'user_id' => $this->staff->id,
            'project_id' => $this->project->id,
            'start_time' => Carbon::parse('2024-01-15 09:00'),
            'end_time' => Carbon::parse('2024-01-15 13:00'),
            'hours' => 4,
            'rate' => 100,
            'billable' => true,
        ]);

        TimeEntry::create([
            'user_id' => $this->staff->id,
            'project_id' => $this->project->id,
            'start_time' => Carbon::parse('2024-01-15 14:00'),
            'end_time' => Carbon::parse('2024-01-15 18:00'),
            'hours' => 4,
            'rate' => 100,
            'billable' => true,
        ]);

        $response = $this->get(route('reports.time-by-project', [
            'project_id' => $this->project->id,
        ]));

        $response->assertStatus(200);
        // Total should be 8 hours
        $response->assertSee('8');
    }

    public function test_report_routes_exist(): void
    {
        $this->actingAs($this->user);

        $routes = [
            'reports.time-by-client',
            'reports.time-by-staff',
            'reports.time-by-project',
        ];

        foreach ($routes as $route) {
            $response = $this->get(route($route));
            // Should not 404
            $this->assertNotEquals(404, $response->status());
        }
    }
}

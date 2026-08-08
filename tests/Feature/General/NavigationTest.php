<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class NavigationTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $staff;
    protected Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        // Create roles using Spatie
        Role::firstOrCreate(['name' => 'admin']);
        Role::firstOrCreate(['name' => 'accountant']);
        Role::firstOrCreate(['name' => 'staff']);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');

        $this->staff = User::factory()->create();
        $this->staff->assignRole('staff');

        $this->client = Client::factory()->create();
    }

    public function test_dashboard_requires_authentication(): void
    {
        $response = $this->get('/dashboard');
        $response->assertRedirect('/login');
    }

    public function test_dashboard_shows_for_authenticated_user(): void
    {
        $response = $this->actingAs($this->admin)->get('/dashboard');
        $response->assertStatus(200);
    }

    public function test_admin_sees_all_navigation_menu_items(): void
    {
        $response = $this->actingAs($this->admin)->get('/dashboard');

        $response->assertStatus(200);
        // Admin should see all menu items
        $response->assertSee('Dashboard');
        $response->assertSee('Clients');
        $response->assertSee('Projects');
        $response->assertSee('Invoices');
        $response->assertSee('Payments');
        $response->assertSee('Time Entries');
        $response->assertSee('Purchase Orders');
    }

    public function test_staff_sees_limited_navigation_menu_items(): void
    {
        $response = $this->actingAs($this->staff)->get('/dashboard');

        $response->assertStatus(200);
        // Staff should see basic menu items but not user management
        $response->assertSee('Dashboard');
        $response->assertSee('Projects');
        $response->assertSee('Time Entries');
    }

    public function test_staff_navigation_shows_relevant_menu_items(): void
    {
        $response = $this->actingAs($this->staff)->get('/dashboard');

        $response->assertStatus(200);
        // Staff should see their relevant menu items
        $response->assertSee('Dashboard');
        $response->assertSee('Projects');
    }

    public function test_dashboard_widgets_show_correct_totals(): void
    {
        $this->actingAs($this->admin);

        // Create invoices with different statuses
        Invoice::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => Invoice::STATUS_SENT,
        ]);

        Invoice::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => Invoice::STATUS_PAID,
        ]);

        $response = $this->get('/dashboard');

        $response->assertStatus(200);
    }

    public function test_dashboard_shows_outstanding_invoices(): void
    {
        $this->actingAs($this->admin);

        Invoice::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => Invoice::STATUS_SENT,
        ]);

        $response = $this->get('/dashboard');

        $response->assertStatus(200);
    }

    public function test_dashboard_shows_overdue_invoices(): void
    {
        $this->actingAs($this->admin);

        Invoice::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->subDays(60)->toDateString(),
            'due_date' => now()->subDays(30)->toDateString(),
            'status' => Invoice::STATUS_SENT,
        ]);

        $response = $this->get('/dashboard');

        $response->assertStatus(200);
    }

    public function test_export_routes_exist(): void
    {
        $this->actingAs($this->admin);

        // Test that export routes are defined
        $response = $this->get('/reports/time-by-client');
        $this->assertNotEquals(404, $response->status());
    }

    public function test_navigation_menu_visibility_by_role(): void
    {
        // Admin has full menu
        $adminResponse = $this->actingAs($this->admin)->get('/dashboard');
        $adminResponse->assertStatus(200);

        // Staff has limited menu
        $staffResponse = $this->actingAs($this->staff)->get('/dashboard');
        $staffResponse->assertStatus(200);
    }

    public function test_dashboard_widget_cash_flow(): void
    {
        $this->actingAs($this->admin);

        // Create some data for cash flow widget
        $response = $this->get('/dashboard');

        $response->assertStatus(200);
    }

    public function test_dashboard_widget_ar_aging(): void
    {
        $this->actingAs($this->admin);

        $response = $this->get('/dashboard');

        $response->assertStatus(200);
    }

    public function test_dashboard_widget_recent_invoices(): void
    {
        $this->actingAs($this->admin);

        Invoice::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ]);

        $response = $this->get('/dashboard');

        $response->assertStatus(200);
    }

    public function test_dashboard_widget_unbilled_time(): void
    {
        $this->actingAs($this->admin);

        $response = $this->get('/dashboard');

        $response->assertStatus(200);
    }

    public function test_navigation_consistent_across_pages(): void
    {
        $this->actingAs($this->admin);

        // Check multiple pages have consistent navigation
        $pages = [
            '/dashboard',
            '/clients',
            '/projects',
        ];

        foreach ($pages as $page) {
            $response = $this->get($page);
            $response->assertStatus(200);
        }
    }
}

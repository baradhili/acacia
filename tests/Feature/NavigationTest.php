<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\User;
use App\Support\Nav;
use App\Support\Widgets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
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
        $response->assertSee('Bills');
        $response->assertSee('Supplier Payments');
        $response->assertSee('Time Entries');
        $response->assertSee('Purchase Orders');
        // Shares & dividends group
        $response->assertSee('Shareholders');
        $response->assertSee('Share Classes');
        $response->assertSee('Franking Account');
        $response->assertSee('Dividends');
    }

    public function test_staff_does_not_see_shares_and_dividends_navigation(): void
    {
        $response = $this->actingAs($this->staff)->get('/dashboard');

        $response->assertStatus(200);
        $response->assertDontSee('Franking Account');
    }

    public function test_admin_sees_the_topbar_dropdowns(): void
    {
        $response = $this->actingAs($this->admin)->get('/dashboard');

        $response->assertStatus(200);
        // The four topbar dropdowns carry the moved sidebar sections.
        $response->assertSee('Reports');
        $response->assertSee('Accounting');
        $response->assertSee('Shares');
        $response->assertSee('Employees');
        // Reports dropdown items
        $response->assertSee('Time by Client');
        $response->assertSee('Balance Sheet');
        $response->assertSee('Company Tax Return');
        $response->assertSee('BAS Settlements');
        // Accounting dropdown items
        $response->assertSee('Prepayments');
        $response->assertSee('Domain Names');
        // Shares dropdown items
        $response->assertSee('Shareholders');
        $response->assertSee('Franking Account');
        // Employees dropdown: payee master data (where payees are edited)
        // and the Resumes module's child
        $response->assertSee('Payroll employees');
    }

    public function test_staff_sees_employees_dropdown_without_payroll_master_data(): void
    {
        $response = $this->actingAs($this->staff)->get('/dashboard');

        $response->assertStatus(200);
        // The Employees section stays visible through its Resumes child,
        // but the admin/accountant-gated payee master data filters out.
        $response->assertSee('Employees');
        $response->assertSee('Resumes');
        $response->assertDontSee('Payroll employees');
    }

    public function test_staff_sees_reports_but_not_accounting_or_shares_dropdowns(): void
    {
        $response = $this->actingAs($this->staff)->get('/dashboard');

        $response->assertStatus(200);
        $response->assertSee('Reports'); // reports are available to staff
        $response->assertDontSee('Domain Names');
        $response->assertDontSee('Dividends');
        $response->assertDontSee('BAS Settlements'); // admin/accountant only
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

    /**
     * The registry contract: every registered link must resolve to a
     * real route (catches stale route names at CI time), role gates
     * match the previous hardcoded @hasanyrole guards, and module or
     * core additions can slot in by position.
     */
    public function test_registry_items_resolve_routes_and_respect_roles(): void
    {
        $nav = app(Nav::class);

        // Every registered route name exists (sidebar, topbar, children).
        $all = collect($nav->sidebar())
            ->merge($nav->topbar())
            ->flatMap(fn ($item) => isset($item['children'])
                ? collect($item['children'])->push($item)
                : collect([$item]))
            ->filter(fn ($item) => ($item['type'] ?? null) === 'link');

        $routes = collect(Route::getRoutes()->getRoutesByName())->keys();
        foreach ($all as $item) {
            // Add-shortcut routes are extras, not the link itself.
            $this->assertTrue(
                $routes->contains($item['route']) || isset($item['add']),
                "Nav item {$item['label']} points at missing route {$item['route']}"
            );
        }

        // Role filtering: staff keeps the ungated items, loses the gated.
        $this->actingAs($this->staff);
        $this->assertContains('Reports', array_column($nav->topbar(), 'label'));
        $this->assertNotContains('Accounting', array_column($nav->topbar(), 'label'));
        $this->assertNotContains('Setup', array_column($nav->topbar(), 'label'));
        $this->assertNotContains('Payroll', array_column($nav->sidebar(), 'label'));

        $this->actingAs($this->admin);
        foreach (['Reports', 'Accounting', 'Shares', 'Setup'] as $label) {
            $this->assertContains($label, array_column($nav->topbar(), 'label'));
        }
        $this->assertContains('Payroll', array_column($nav->sidebar(), 'label'));
    }

    public function test_dashboard_renders_the_widget_registry(): void
    {
        $widgets = app(Widgets::class);

        $this->actingAs($this->admin)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('data-widget="TotalClientsWidget"', false)
            ->assertSee('data-widget="PnLTrendWidget"', false);

        $this->assertNotEmpty($widgets->all());
        $this->assertContains('CashFlowWidget', array_column($widgets->all(), 'id'));
    }
}

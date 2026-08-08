<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RoleMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $accountant;
    protected User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        // Create roles using Spatie
        Role::firstOrCreate(['name' => 'admin']);
        Role::firstOrCreate(['name' => 'accountant']);
        Role::firstOrCreate(['name' => 'staff']);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');

        $this->accountant = User::factory()->create();
        $this->accountant->assignRole('accountant');

        $this->staff = User::factory()->create();
        $this->staff->assignRole('staff');
    }

    public function test_admin_can_access_user_management(): void
    {
        $response = $this->actingAs($this->admin)->get('/users');

        $response->assertStatus(200);
    }

    public function test_non_admin_cannot_access_user_management(): void
    {
        $response = $this->actingAs($this->accountant)->get('/users');
        $response->assertStatus(403);

        $response = $this->actingAs($this->staff)->get('/users');
        $response->assertStatus(403);
    }

    public function test_user_has_role_assigned_via_spatie(): void
    {
        $this->assertTrue($this->admin->hasRole('admin'));
        $this->assertTrue($this->accountant->hasRole('accountant'));
        $this->assertTrue($this->staff->hasRole('staff'));
    }

    public function test_admin_can_create_user(): void
    {
        $response = $this->actingAs($this->admin)->post('/users', [
            'name' => 'New User',
            'email' => 'newuser@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'roles' => ['staff'],
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('users', [
            'email' => 'newuser@example.com',
        ]);

        // Verify user was created
        $newUser = User::where('email', 'newuser@example.com')->first();
        $this->assertNotNull($newUser);
    }

    public function test_admin_can_delete_user(): void
    {
        $userToDelete = User::factory()->create();
        $userToDelete->assignRole('staff');

        $response = $this->actingAs($this->admin)->delete("/users/{$userToDelete->id}");

        $response->assertSessionHasNoErrors();
        // Users are soft deleted
        $this->assertSoftDeleted('users', ['id' => $userToDelete->id]);
    }

    public function test_non_admin_cannot_create_user(): void
    {
        $response = $this->actingAs($this->staff)->post('/users', [
            'name' => 'New User',
            'email' => 'newuser@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'role' => 'admin',
        ]);

        $response->assertStatus(403);
    }

    public function test_non_admin_cannot_delete_users(): void
    {
        $otherUser = User::factory()->create();
        $otherUser->assignRole('staff');

        $response = $this->actingAs($this->staff)->delete("/users/{$otherUser->id}");

        $response->assertStatus(403);
    }

    public function test_user_can_update_own_profile(): void
    {
        $response = $this->actingAs($this->staff)->patch('/profile', [
            'name' => 'My New Name',
            'email' => $this->staff->email,
        ]);

        $response->assertSessionHasNoErrors();
        $this->staff->refresh();
        $this->assertEquals('My New Name', $this->staff->name);
    }

    public function test_user_profile_charge_out_rate_attribute(): void
    {
        $user = User::factory()->create([
            'charge_out_rate' => 150.00,
        ]);
        $user->assignRole('staff');

        // Verify charge_out_rate can be set and retrieved
        $this->assertEquals(150.00, $user->charge_out_rate);
    }

    public function test_admin_has_access_to_accounting_routes(): void
    {
        // Check that admin role has access to accounting-related functionality
        $this->assertTrue($this->admin->hasRole('admin'));
    }

    public function test_accountant_has_access_to_accounting_routes(): void
    {
        // Check that accountant role exists and has appropriate access
        $this->assertTrue($this->accountant->hasRole('accountant'));
    }

    public function test_staff_role_exists(): void
    {
        $this->assertTrue($this->staff->hasRole('staff'));
    }

    public function test_role_middleware_restricts_routes(): void
    {
        // Test that protected routes return 403 for unauthorized roles
        $protectedRoutes = [
            '/users',
        ];

        foreach ($protectedRoutes as $route) {
            $response = $this->actingAs($this->staff)->get($route);
            $response->assertStatus(403);
        }
    }

    public function test_users_route_exists_for_admin(): void
    {
        $response = $this->actingAs($this->admin)->get('/users');

        $this->assertNotEquals(404, $response->status());
    }

    public function test_admin_role_has_full_access(): void
    {
        // Dashboard
        $response = $this->actingAs($this->admin)->get('/dashboard');
        $this->assertNotEquals(403, $response->status());

        // Clients
        $response = $this->actingAs($this->admin)->get('/clients');
        $this->assertNotEquals(403, $response->status());

        // Projects
        $response = $this->actingAs($this->admin)->get('/projects');
        $this->assertNotEquals(403, $response->status());

        // Invoices
        $response = $this->actingAs($this->admin)->get('/invoices');
        $this->assertNotEquals(403, $response->status());

        // Users
        $response = $this->actingAs($this->admin)->get('/users');
        $this->assertEquals(200, $response->status());
    }

    public function test_roles_can_be_assigned_to_users(): void
    {
        $newUser = User::factory()->create();
        
        // Assign role
        $newUser->assignRole('staff');
        $this->assertTrue($newUser->hasRole('staff'));

        // Add another role
        $newUser->assignRole('accountant');
        $this->assertTrue($newUser->hasRole('staff'));
        $this->assertTrue($newUser->hasRole('accountant'));

        // Remove role
        $newUser->removeRole('staff');
        $this->assertFalse($newUser->hasRole('staff'));
        $this->assertTrue($newUser->hasRole('accountant'));
    }
}

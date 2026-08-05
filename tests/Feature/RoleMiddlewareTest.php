<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $accountant;
    protected User $staff;
    protected User $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->accountant = User::factory()->create(['role' => 'accountant']);
        $this->staff = User::factory()->create(['role' => 'staff']);
        $this->client = User::factory()->create(['role' => 'client']);
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

        $response = $this->actingAs($this->client)->get('/users');
        $response->assertStatus(403);
    }

    public function test_role_constants_are_defined(): void
    {
        $this->assertEquals('admin', User::ROLE_ADMIN);
        $this->assertEquals('accountant', User::ROLE_ACCOUNTANT);
        $this->assertEquals('staff', User::ROLE_STAFF);
        $this->assertEquals('client', User::ROLE_CLIENT);
    }

    public function test_admin_can_create_user(): void
    {
        $response = $this->actingAs($this->admin)->post('/users', [
            'name' => 'New User',
            'email' => 'newuser@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'role' => 'staff',
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('users', [
            'email' => 'newuser@example.com',
            'role' => 'staff',
        ]);
    }

    public function test_admin_can_edit_user(): void
    {
        $userToEdit = User::factory()->create(['role' => 'staff']);

        $response = $this->actingAs($this->admin)->patch("/users/{$userToEdit->id}", [
            'name' => 'Updated Name',
            'email' => $userToEdit->email,
            'role' => 'accountant',
        ]);

        $response->assertSessionHasNoErrors();
        $userToEdit->refresh();
        $this->assertEquals('Updated Name', $userToEdit->name);
        $this->assertEquals('accountant', $userToEdit->role);
    }

    public function test_admin_can_delete_user(): void
    {
        $userToDelete = User::factory()->create(['role' => 'staff']);

        $response = $this->actingAs($this->admin)->delete("/users/{$userToDelete->id}");

        $response->assertSessionHasNoErrors();
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

    public function test_non_admin_cannot_edit_other_users(): void
    {
        $otherUser = User::factory()->create(['role' => 'staff']);

        $response = $this->actingAs($this->staff)->patch("/users/{$otherUser->id}", [
            'name' => 'Hacked Name',
            'email' => $otherUser->email,
        ]);

        $response->assertStatus(403);
    }

    public function test_non_admin_cannot_delete_other_users(): void
    {
        $otherUser = User::factory()->create(['role' => 'staff']);

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

    public function test_user_profile_includes_salary_rate_fields(): void
    {
        $user = User::factory()->create([
            'hourly_rate' => 150.00,
        ]);

        $this->actingAs($user);

        $response = $this->patch('/profile', [
            'name' => $user->name,
            'email' => $user->email,
            'hourly_rate' => 175.00,
        ]);

        $response->assertSessionHasNoErrors();
        $user->refresh();
        $this->assertEquals(175.00, $user->hourly_rate);
    }

    public function test_admin_can_access_journal_entries(): void
    {
        $response = $this->actingAs($this->admin)->get('/accounting/journal-entries');

        // Should either show page or redirect to login
        $this->assertNotEquals(403, $response->status());
    }

    public function test_accountant_can_access_journal_entries(): void
    {
        $response = $this->actingAs($this->accountant)->get('/accounting/journal-entries');

        // Should either show page or redirect to login
        $this->assertNotEquals(403, $response->status());
    }

    public function test_staff_cannot_access_journal_entries(): void
    {
        $response = $this->actingAs($this->staff)->get('/accounting/journal-entries');

        $response->assertStatus(403);
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
}

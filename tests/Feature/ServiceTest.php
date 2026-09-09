<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ServiceTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'accountant', 'staff'] as $role) {
            Role::firstOrCreate(['name' => $role]);
        }

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');

        $this->staff = User::factory()->create();
        $this->staff->assignRole('staff');
    }

    public function test_index_lists_services_ordered_by_name(): void
    {
        Service::create(['name' => 'Bookkeeping', 'hourly_rate' => 95.5]);
        Service::create(['name' => 'BAS Preparation', 'hourly_rate' => 150, 'description' => 'Quarterly BAS lodgement']);

        $response = $this->actingAs($this->admin)->get(route('services.index'));

        $response->assertOk()
            ->assertSeeInOrder(['BAS Preparation', 'Bookkeeping'])
            ->assertSee('150');
    }

    public function test_admin_can_create_a_service(): void
    {
        $response = $this->actingAs($this->admin)->post(route('services.store'), [
            'name' => 'Tax Return',
            'description' => 'Annual company tax return',
            'hourly_rate' => '180.50',
        ]);

        $response->assertRedirect(route('services.index'));

        $service = Service::query()->where('name', 'Tax Return')->first();
        $this->assertNotNull($service);
        $this->assertEquals('180.5000', $service->hourly_rate);
        $this->assertEquals('Annual company tax return', $service->description);
    }

    public function test_service_can_be_fixed_fee_without_a_rate(): void
    {
        $response = $this->actingAs($this->admin)->post(route('services.store'), [
            'name' => 'Company Setup',
            'description' => null,
            'hourly_rate' => null,
        ]);

        $response->assertRedirect(route('services.index'));

        $service = Service::query()->where('name', 'Company Setup')->first();
        $this->assertNotNull($service);
        $this->assertNull($service->hourly_rate);
    }

    public function test_rate_allows_up_to_four_decimal_places(): void
    {
        $response = $this->actingAs($this->admin)->post(route('services.store'), [
            'name' => 'Reverse-invoice processing',
            'hourly_rate' => '87.1234',
        ]);

        $response->assertRedirect(route('services.index'));

        $service = Service::query()->where('name', 'Reverse-invoice processing')->first();
        $this->assertEquals('87.1234', $service->hourly_rate);
    }

    public function test_store_requires_a_name(): void
    {
        $response = $this->actingAs($this->admin)->post(route('services.store'), [
            'name' => '',
            'hourly_rate' => 100,
        ]);

        $response->assertSessionHasErrors('name');
        $this->assertDatabaseCount('services', 0);
    }

    public function test_store_rejects_a_rate_with_too_many_decimals(): void
    {
        $response = $this->actingAs($this->admin)->post(route('services.store'), [
            'name' => 'Bookkeeping',
            'hourly_rate' => '87.12345',
        ]);

        $response->assertSessionHasErrors('hourly_rate');
        $this->assertDatabaseCount('services', 0);
    }

    public function test_store_rejects_a_negative_rate(): void
    {
        $response = $this->actingAs($this->admin)->post(route('services.store'), [
            'name' => 'Bookkeeping',
            'hourly_rate' => '-10',
        ]);

        $response->assertSessionHasErrors('hourly_rate');
        $this->assertDatabaseCount('services', 0);
    }

    public function test_admin_can_update_a_service(): void
    {
        $service = Service::create(['name' => 'Bookkeeping', 'hourly_rate' => 95]);

        $response = $this->actingAs($this->admin)->put(route('services.update', $service), [
            'name' => 'Bookkeeping & Payroll',
            'description' => 'Weekly payroll processing',
            'hourly_rate' => '110.25',
        ]);

        $response->assertRedirect(route('services.index'));

        $service->refresh();
        $this->assertEquals('Bookkeeping & Payroll', $service->name);
        $this->assertEquals('110.2500', $service->hourly_rate);
        $this->assertEquals('Weekly payroll processing', $service->description);
    }

    public function test_admin_can_delete_a_service(): void
    {
        $service = Service::create(['name' => 'Bookkeeping']);

        $response = $this->actingAs($this->admin)->delete(route('services.destroy', $service));

        $response->assertRedirect(route('services.index'));
        $this->assertDatabaseMissing('services', ['id' => $service->id]);
    }

    public function test_show_displays_a_service(): void
    {
        $service = Service::create([
            'name' => 'BAS Preparation',
            'description' => 'Quarterly BAS lodgement',
            'hourly_rate' => 150,
        ]);

        $response = $this->actingAs($this->admin)->get(route('services.show', $service));

        $response->assertOk()
            ->assertSee('BAS Preparation')
            ->assertSee('Quarterly BAS lodgement');
    }

    public function test_missing_service_returns_404(): void
    {
        $response = $this->actingAs($this->admin)->get(route('services.show', 999));

        $response->assertNotFound();
    }

    public function test_staff_cannot_manage_services(): void
    {
        $service = Service::create(['name' => 'Bookkeeping']);

        $this->actingAs($this->staff)->get(route('services.index'))->assertForbidden();
        $this->actingAs($this->staff)->get(route('services.create'))->assertForbidden();
        $this->actingAs($this->staff)->post(route('services.store'), ['name' => 'Nope'])->assertForbidden();
        $this->actingAs($this->staff)->put(route('services.update', $service), ['name' => 'Nope'])->assertForbidden();
        $this->actingAs($this->staff)->delete(route('services.destroy', $service))->assertForbidden();
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('services.index'))->assertRedirect('/login');
    }

    public function test_navigation_shows_services_for_admin_but_not_staff(): void
    {
        $this->actingAs($this->admin)->get('/dashboard')->assertSee('href="'.route('services.index').'"', escape: false);
        $this->actingAs($this->staff)->get('/dashboard')->assertDontSee('href="'.route('services.index').'"', escape: false);
    }
}

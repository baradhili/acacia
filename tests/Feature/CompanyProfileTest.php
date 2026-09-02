<?php

namespace Tests\Feature;

use App\Models\CompanyDirector;
use App\Models\CompanyProfile;
use App\Models\CompanyShareholder;
use App\Models\User;
use IFRS\Models\Currency;
use IFRS\Models\Entity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CompanyProfileTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Entity $entity;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate');

        foreach (['admin', 'accountant', 'staff'] as $role) {
            Role::firstOrCreate(['name' => $role]);
        }

        $this->entity = Entity::create([
            'name' => 'Test Entity',
            'currency_id' => 1,
            'year_start' => 7,
            'multi_currency' => false,
        ]);

        $currency = Currency::create([
            'name' => 'Australian Dollar',
            'currency_code' => 'AUD',
            'entity_id' => $this->entity->id,
        ]);
        $this->entity->update(['currency_id' => $currency->id]);
        $this->entity->refresh();

        $this->admin = User::factory()->create();
        $this->admin->entity_id = $this->entity->id;
        $this->admin->save();
        $this->admin->assignRole('admin');
    }

    public function test_company_profile_page_loads_for_admin(): void
    {
        $response = $this->actingAs($this->admin)
            ->get(route('company-profile.index'));

        $response->assertStatus(200);
        $response->assertSee('Company Details');
        $response->assertSee('Test Entity');
        $response->assertSee('Directors');
        $response->assertSee('Shareholders');
    }

    public function test_company_profile_requires_authentication(): void
    {
        $this->get(route('company-profile.index'))->assertRedirect('/login');
    }

    public function test_company_profile_denies_staff_role(): void
    {
        $staff = User::factory()->create();
        $staff->entity_id = $this->entity->id;
        $staff->save();
        $staff->assignRole('staff');

        $this->actingAs($staff)
            ->get(route('company-profile.index'))
            ->assertStatus(403);
    }

    public function test_company_profile_update_saves_identity_directors_and_shareholders(): void
    {
        $response = $this->actingAs($this->admin)
            ->put(route('company-profile.update'), [
                'name' => 'Test Entity Pty Ltd',
                'trading_name' => 'TestCo Services',
                'abn' => '51824753556',
                'tfn' => '123456789',
                'acn' => '123456789',
                'address_line1' => '12 Market Street',
                'suburb' => 'Sydney',
                'state' => 'NSW',
                'postcode' => '2000',
                'country' => 'AU',
                'email' => 'accounts@example.com.au',
                'phone' => '0291234567',
                'directors' => [
                    ['name' => 'Jane Doe', 'appointment_date' => '2020-07-01', 'email' => 'jane@example.com.au'],
                    ['name' => '  ', 'email' => 'skipped-when-nameless@example.com'],
                ],
                'shareholders' => [
                    ['name' => 'John Smith', 'share_class' => 'ORD', 'shares_held' => '80', 'resident_for_tax' => '1', 'status' => 'A'],
                    ['name' => 'Acme Super Fund', 'share_class' => 'PREF', 'shares_held' => '20', 'resident_for_tax' => '1', 'status' => 'A', 'abn' => '12345678901', 'email' => 'super@acme.au'],
                ],
            ]);

        $response->assertRedirect(route('company-profile.index'));
        $response->assertSessionHas('success');

        $profile = CompanyProfile::where('entity_id', $this->entity->id)->firstOrFail();
        $this->assertSame('51824753556', $profile->abn);
        $this->assertSame('123456789', $profile->tfn);
        $this->assertSame('Sydney', $profile->suburb);

        // The legal name moved onto the entity; the trading name stayed here.
        $this->assertSame('Test Entity Pty Ltd', $this->entity->fresh()->name);
        $this->assertSame('TestCo Services', $profile->trading_name);

        // The nameless director row is dropped, not stored.
        $this->assertSame(['Jane Doe'], $profile->fresh()->directors()->pluck('name')->all());
        $this->assertSame(1, CompanyDirector::count());

        $shareholders = $profile->fresh()->allShareholders;
        $this->assertCount(2, $shareholders);
        $this->assertSame(80, (int) $shareholders->firstWhere('name', 'John Smith')->shares_held);
        $this->assertSame('12345678901', $shareholders->firstWhere('name', 'Acme Super Fund')->abn);

        // The saved screen re-displays the values.
        $this->actingAs($this->admin)
            ->get(route('company-profile.index'))
            ->assertSee('Test Entity Pty Ltd')
            ->assertSee('TestCo Services')
            ->assertSee('51824753556')
            ->assertSee('Jane Doe')
            ->assertSee('Acme Super Fund');
    }

    public function test_company_profile_update_requires_a_legal_name_and_clears_trading_name(): void
    {
        // The legal name is mandatory on every save.
        $this->actingAs($this->admin)
            ->put(route('company-profile.update'), ['trading_name' => 'TestCo'])
            ->assertSessionHasErrors('name');

        // A blank trading name clears it; the legal name can stay as-is.
        $this->entity->update(['name' => 'Kept Legal Name Pty Ltd']);
        CompanyProfile::create([
            'entity_id' => $this->entity->id,
            'trading_name' => 'Old Trading Name',
            'country' => 'AU',
        ]);

        $this->actingAs($this->admin)
            ->put(route('company-profile.update'), ['name' => 'Kept Legal Name Pty Ltd', 'trading_name' => ''])
            ->assertSessionHas('success');

        $profile = CompanyProfile::where('entity_id', $this->entity->id)->firstOrFail();
        $this->assertNull($profile->trading_name);
        $this->assertSame('Kept Legal Name Pty Ltd', $this->entity->fresh()->name);

        // The page shows the legal name with the trading name omitted.
        $this->actingAs($this->admin)
            ->get(route('company-profile.index'))
            ->assertOk()
            ->assertSee('Kept Legal Name Pty Ltd')
            ->assertDontSee('trading as');
    }

    public function test_company_profile_update_replaces_registry_rows(): void
    {
        $profile = CompanyProfile::create(['entity_id' => $this->entity->id, 'country' => 'AU']);
        $profile->directors()->create(['name' => 'Old Director']);
        $profile->allShareholders()->create(['name' => 'Old Holder', 'share_class' => 'ORD', 'shares_held' => 10]);

        $this->actingAs($this->admin)
            ->put(route('company-profile.update'), [
                'name' => 'Test Entity',
                'directors' => [['name' => 'New Director']],
                'shareholders' => [['name' => 'New Holder', 'share_class' => 'ORD', 'shares_held' => 100, 'resident_for_tax' => '1', 'status' => 'A']],
            ])
            ->assertRedirect(route('company-profile.index'));

        $this->assertSame(['New Director'], CompanyDirector::pluck('name')->all());
        $this->assertSame(['New Holder'], CompanyShareholder::pluck('name')->all());
        $this->assertSame(100, (int) CompanyShareholder::first()->shares_held);
    }

    public function test_company_profile_validates_identifiers(): void
    {
        $response = $this->actingAs($this->admin)
            ->put(route('company-profile.update'), [
                'name' => 'Test Entity',
                'abn' => '12345', // too short
                'tfn' => 'not-digits',
                'email' => 'not-an-email',
            ]);

        $response->assertSessionHasErrors(['abn', 'tfn', 'email']);
        $this->assertSame(0, CompanyProfile::count());
    }

    public function test_company_tax_report_prefers_profile_abn_tfn_over_config(): void
    {
        config(['australian.abn' => '', 'australian.tfn' => '']);

        CompanyProfile::create([
            'entity_id' => $this->entity->id,
            'abn' => '51824753556',
            'tfn' => '987654321',
            'country' => 'AU',
        ]);

        $this->actingAs($this->admin)
            ->get(route('reports.company-tax'))
            ->assertStatus(200)
            ->assertSee('51824753556')
            ->assertSee('987654321');
    }
}

<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\CompanyProfile;
use App\Models\User;
use IFRS\Models\Currency;
use IFRS\Models\Entity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * ABN/ACN/TFN entry with the usual spaces: values normalise to bare
 * digits on the way into the models and re-display in the ATO/ASIC
 * grouping on the screens that show them.
 */
class AuNumberFormattingTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Entity $entity;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'accountant', 'staff'] as $role) {
            Role::firstOrCreate(['name' => $role]);
        }

        $this->entity = Entity::create([
            'name' => 'Spaced Entity',
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

        $this->admin = tap(User::factory()->create(['entity_id' => $this->entity->id]))->assignRole('admin');
    }

    public function test_company_profile_accepts_spaced_identifiers_and_redisplays_formatted(): void
    {
        $profile = CompanyProfile::create(['entity_id' => $this->entity->id, 'country' => 'AU']);

        $this->actingAs($this->admin)
            ->put(route('company-profile.update'), [
                'name' => 'Spaced Pty Ltd',
                'abn' => '51 824 753 556',
                'tfn' => '123 456 789',
                'acn' => '123 456 789',
            ])
            ->assertRedirect(route('company-profile.index'));

        $profile->refresh();
        $this->assertSame('51824753556', $profile->abn);   // stored bare
        $this->assertSame('123456789', $profile->tfn);
        $this->assertSame('123456789', $profile->acn);

        $this->assertSame('51 824 753 556', $profile->formatted_abn); // shown grouped
        $this->assertSame('123 456 789', $profile->formatted_acn);

        $this->actingAs($this->admin)
            ->get(route('company-profile.index'))
            ->assertOk()
            ->assertSee('value="51 824 753 556"', false);
    }

    public function test_wrong_length_identifiers_still_fail_validation(): void
    {
        $this->actingAs($this->admin)
            ->put(route('company-profile.update'), [
                'name' => 'Spaced Pty Ltd',
                'abn' => '51 824 753', // spaces fine, length is not
                'tfn' => 'not digits at all',
            ])
            ->assertSessionHasErrors(['abn', 'tfn']);
    }

    public function test_clients_store_spaced_abn_and_display_formatted(): void
    {
        $this->actingAs($this->admin)
            ->post('/clients', ['name' => 'Spaced Client', 'abn' => '51 824 753 556'])
            ->assertRedirect();

        $client = Client::firstOrFail();
        $this->assertSame('51824753556', $client->abn);

        $this->actingAs($this->admin)
            ->get('/clients')
            ->assertOk()
            ->assertSee('51 824 753 556');
    }
}

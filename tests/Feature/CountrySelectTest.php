<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\User;
use App\Services\Countries;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CountrySelectTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    /** Position of $needle in the response HTML (asserted present). */
    protected function pos(string $html, string $needle): int
    {
        $position = strpos($html, $needle);
        $this->assertNotFalse($position, "Expected to find [{$needle}] in the response.");

        return $position;
    }

    public function test_client_create_form_renders_country_dropdown_with_pinned_first(): void
    {
        $response = $this->actingAs($this->user)->get('/clients/create');

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('<select name="country"', $html);
        $this->assertStringNotContainsString('<input type="text" name="country"', $html);

        // Pinned countries lead in configured order, ahead of the
        // alphabetical "All countries" group.
        $this->assertLessThan(
            $this->pos($html, '<option value="New Zealand"'),
            $this->pos($html, '<option value="Australia" selected')
        );
        $this->assertLessThan(
            $this->pos($html, '<optgroup label="All countries">'),
            $this->pos($html, '<option value="New Zealand"')
        );
        $this->assertLessThan(
            $this->pos($html, '<option value="Afghanistan"'),
            $this->pos($html, '<optgroup label="All countries">')
        );
    }

    public function test_supplier_create_form_renders_dropdown_defaulting_to_australia(): void
    {
        $response = $this->actingAs($this->user)->get('/suppliers/create');

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('<select name="country"', $html);
        $this->assertStringContainsString('<option value="Australia" selected', $html);
    }

    public function test_edit_form_selects_the_current_country(): void
    {
        $client = Client::factory()->create(['country' => 'Singapore']);

        $response = $this->actingAs($this->user)->get("/clients/{$client->id}/edit");

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('<option value="Singapore" selected', $html);
    }

    public function test_edit_form_keeps_legacy_country_value_selectable(): void
    {
        // Historic free-text entries must stay selected and submittable —
        // editing an old record must not silently wipe the stored country.
        $client = Client::factory()->create(['country' => 'Oz']);

        $response = $this->actingAs($this->user)->get("/clients/{$client->id}/edit");

        $response->assertOk();
        $this->assertStringContainsString('<option value="Oz" selected', $response->getContent());
    }

    public function test_pinned_countries_are_configurable(): void
    {
        config(['countries.pinned' => 'United Kingdom,Ireland,Australia']);

        $this->assertEquals(['United Kingdom', 'Ireland', 'Australia'], Countries::pinned());

        [$pinned, $rest] = Countries::dropdown();
        $this->assertEquals(['United Kingdom', 'Ireland', 'Australia'], $pinned);
        $this->assertNotContains('Australia', $rest, 'Pinned countries must not repeat in the alphabetical group.');
    }

    public function test_unknown_pinned_countries_are_ignored(): void
    {
        config(['countries.pinned' => 'Australia,Atlantis']);

        $this->assertEquals(['Australia'], Countries::pinned());
    }

    public function test_client_stores_country_from_dropdown(): void
    {
        $response = $this->actingAs($this->user)->post('/clients', [
            'name' => 'Overseas Client',
            'email' => 'overseas@example.com',
            'country' => 'Singapore',
        ]);

        $response->assertSessionHas('success');
        $this->assertDatabaseHas('clients', ['name' => 'Overseas Client', 'country' => 'Singapore']);
    }

    public function test_client_country_can_be_cleared_via_blank_option(): void
    {
        $client = Client::factory()->create(['country' => 'Australia']);

        $response = $this->actingAs($this->user)->put("/clients/{$client->id}", [
            'name' => $client->name,
            'email' => $client->email,
            'country' => '',
        ]);

        $response->assertSessionHas('success');
        $this->assertDatabaseHas('clients', ['id' => $client->id, 'country' => null]);
    }
}

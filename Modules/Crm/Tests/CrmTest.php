<?php

namespace Modules\Crm\Tests;

use App\Models\Client;
use App\Models\User;
use App\Support\Nav;
use App\Support\Widgets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Crm\Models\Lead;
use Modules\Crm\Models\LeadActivity;
use Modules\Crm\Models\SalesTarget;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The CRM module: leads through the funnel (valid moves only, loss
 * reasons), activity logging, conversion to a Client (the ERP seam),
 * monthly targets measured against won value, and the shell
 * contracts (sidebar Sales section + dashboard widget).
 */
class CrmTest extends TestCase
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

        $this->admin = tap(User::factory()->create())->assignRole('admin');
        $this->staff = tap(User::factory()->create())->assignRole('staff');
    }

    protected function lead(array $overrides = []): Lead
    {
        return Lead::create(array_merge([
            'name' => 'Jane Buyer',
            'company' => 'BuyerCo',
            'email' => 'jane@buyerco.example',
            'source' => 'referral',
            'status' => Lead::STATUS_NEW,
            'estimated_value' => 12000,
            'probability' => 30,
            'owner_id' => $this->admin->id,
        ], $overrides));
    }

    public function test_leads_crud_round_trip(): void
    {
        $this->actingAs($this->admin)->post('/crm/leads', [
            'name' => 'Jane Buyer',
            'company' => 'BuyerCo',
            'email' => 'jane@buyerco.example',
            'source' => 'referral',
            'estimated_value' => 12000,
            'probability' => 30,
            'next_follow_up' => now()->addWeek()->toDateString(),
            'notes' => 'Needs a migration project quote.',
        ])->assertRedirect(route('crm.leads.index'))->assertSessionHas('success');

        $lead = Lead::query()->firstOrFail();
        $this->assertSame(Lead::STATUS_NEW, $lead->status);
        $this->assertEquals($this->admin->id, $lead->owner_id); // defaults to creator

        $this->actingAs($this->admin)
            ->get("/crm/leads/{$lead->id}/edit")
            ->assertOk()
            ->assertSee('Jane Buyer');

        $this->actingAs($this->admin)->put("/crm/leads/{$lead->id}", [
            'name' => 'Jane Buyer',
            'estimated_value' => 15000,
            'probability' => 45,
        ])->assertSessionHas('success');

        $this->assertEqualsWithDelta(15000.0, (float) $lead->fresh()->estimated_value, 0.001);
    }

    public function test_funnel_moves_are_guarded_and_loss_reasons_recorded(): void
    {
        $lead = $this->lead();

        // new → won is not a funnel move; it happens by converting.
        $this->actingAs($this->admin)
            ->post("/crm/leads/{$lead->id}/status", ['status' => Lead::STATUS_WON])
            ->assertSessionHas('error');
        $this->assertSame(Lead::STATUS_NEW, $lead->fresh()->status);

        $this->actingAs($this->admin)
            ->post("/crm/leads/{$lead->id}/status", ['status' => Lead::STATUS_CONTACTED])
            ->assertSessionHas('success');

        $this->actingAs($this->admin)
            ->post("/crm/leads/{$lead->id}/status", ['status' => Lead::STATUS_LOST, 'loss_reason' => 'Went to a competitor'])
            ->assertSessionHas('success');

        $lead = $lead->fresh();
        $this->assertSame(Lead::STATUS_LOST, $lead->status);
        $this->assertSame('Went to a competitor', $lead->loss_reason);
        $this->assertNull($lead->next_follow_up); // no plan on a lost lead

        // Lost leads re-open.
        $this->assertTrue($lead->canTransitionTo(Lead::STATUS_NEW));
    }

    public function test_index_shows_funnel_stats(): void
    {
        $this->lead(['status' => Lead::STATUS_NEW, 'estimated_value' => 10000, 'probability' => 20]);
        $this->lead(['status' => Lead::STATUS_PROPOSAL, 'estimated_value' => 5000, 'probability' => 60]);
        $this->lead(['status' => Lead::STATUS_LOST, 'estimated_value' => 99999, 'probability' => 100]);

        $response = $this->actingAs($this->admin)->get('/crm/leads');

        $response->assertOk()
            ->assertSee('Open pipeline')
            // 10,000 + 5,000 — the lost lead is not pipeline
            ->assertSee('$'.number_format(15000, 2))
            // 10,000×20% + 5,000×60% = 5,000
            ->assertSee('$'.number_format(5000, 2));
    }

    public function test_activities_log_against_the_lead(): void
    {
        $lead = $this->lead();

        $this->actingAs($this->staff)
            ->post("/crm/leads/{$lead->id}/activities", [
                'type' => 'call',
                'summary' => 'Discovery call — migration pain around Xero.',
            ])->assertSessionHas('success');

        $this->actingAs($this->admin)
            ->get("/crm/leads/{$lead->id}")
            ->assertOk()
            ->assertSee('Discovery call');

        $this->assertSame('call', LeadActivity::query()->firstOrFail()->type);
    }

    public function test_converting_a_proposal_lead_creates_a_client(): void
    {
        $lead = $this->lead(['status' => Lead::STATUS_PROPOSAL]);

        $response = $this->actingAs($this->admin)
            ->post("/crm/leads/{$lead->id}/convert", ['client_name' => 'BuyerCo Pty Ltd']);

        $client = Client::query()->firstOrFail();
        $response->assertRedirect(route('clients.show', $client));

        $this->assertSame('BuyerCo Pty Ltd', $client->name);
        $this->assertSame('jane@buyerco.example', $client->email); // contact details carry over

        $lead = $lead->fresh();
        $this->assertSame(Lead::STATUS_WON, $lead->status);
        $this->assertEquals($client->id, $lead->client_id);
        $this->assertNotNull($lead->converted_at);

        // Won is final — converting again is refused.
        $this->actingAs($this->admin)
            ->post("/crm/leads/{$lead->id}/convert", ['client_name' => 'Again Co'])
            ->assertSessionHas('error');
        $this->assertSame(1, Client::count());
    }

    public function test_only_proposal_leads_convert(): void
    {
        $lead = $this->lead(['status' => Lead::STATUS_NEW]);

        $this->actingAs($this->admin)
            ->post("/crm/leads/{$lead->id}/convert", ['client_name' => 'Early Co'])
            ->assertSessionHas('error');

        $this->assertSame(0, Client::count());
    }

    public function test_targets_track_won_value_and_are_management_only(): void
    {
        $this->actingAs($this->staff)->get('/crm/targets')->assertForbidden();

        $this->lead(['status' => Lead::STATUS_PROPOSAL, 'estimated_value' => 8000, 'probability' => 100])
            ->update(['status' => Lead::STATUS_WON, 'converted_at' => now()]);

        $this->actingAs($this->admin)
            ->post('/crm/targets', [
                'month' => now()->startOfMonth()->toDateString(),
                'amount' => 10000,
            ])->assertSessionHas('success');

        $target = SalesTarget::forMonth(now());
        $this->assertNotNull($target);
        $this->assertEqualsWithDelta(8000.0, $target->achieved(), 0.001);
        $this->assertEqualsWithDelta(80.0, $target->progressPercent(), 0.01);

        $this->actingAs($this->admin)
            ->get('/crm/targets')
            ->assertOk()
            ->assertSee('$'.number_format(8000, 2))
            ->assertSee('80%');
    }

    public function test_target_months_must_start_on_the_first(): void
    {
        $this->actingAs($this->admin)
            ->post('/crm/targets', ['month' => now()->startOfMonth()->addDays(3)->toDateString(), 'amount' => 1000])
            ->assertSessionHasErrors('month');

        $this->assertSame(0, SalesTarget::count());
    }

    public function test_the_proposal_shortcut_pre_fills_and_links_the_estimate(): void
    {
        $client = Client::factory()->create(['name' => 'BuyerCo Pty Ltd']);
        $lead = $this->lead([
            'status' => Lead::STATUS_PROPOSAL,
            'estimated_value' => 12500,
            'notes' => 'Migration project — two phases.',
        ]);

        // The shortcut lands on the estimate form pre-filled from the lead.
        $this->actingAs($this->admin)
            ->get("/crm/leads/{$lead->id}/estimate")
            ->assertRedirect(route('estimates.create', ['lead_id' => $lead->id]));

        $this->actingAs($this->admin)
            ->get(route('estimates.create', ['lead_id' => $lead->id]))
            ->assertOk()
            ->assertSee('Preparing the estimate for lead')
            ->assertSee('Jane Buyer')
            ->assertSee('Services for BuyerCo')
            ->assertSee('Migration project');

        // Creating the estimate links it back on the lead.
        $this->actingAs($this->admin)->post('/estimates', [
            'lead_id' => $lead->id,
            'client_id' => $client->id,
            'issue_date' => now()->toDateString(),
            'valid_until' => now()->addDays(30)->toDateString(),
            'items' => [
                ['description' => 'Migration project', 'quantity' => 1, 'unit_price' => 12500, 'tax_rate' => 10],
            ],
        ])->assertSessionHas('success');

        $estimate = $lead->fresh()->estimate;
        $this->assertNotNull($estimate);
        $this->assertEqualsWithDelta(13750.0, (float) $estimate->total, 0.001);

        // The lead screen shows the linked estimate instead of the shortcut.
        $this->actingAs($this->admin)
            ->get("/crm/leads/{$lead->id}")
            ->assertOk()
            ->assertSee($estimate->estimate_number)
            ->assertDontSee('Prepare Estimate');
    }

    public function test_the_shortcut_refuses_closed_leads(): void
    {
        $lost = $this->lead(['status' => Lead::STATUS_LOST, 'loss_reason' => 'Gone']);

        $this->actingAs($this->admin)
            ->get("/crm/leads/{$lost->id}/estimate")
            ->assertSessionHas('error');

        $won = $this->lead(['status' => Lead::STATUS_WON]);
        $this->actingAs($this->admin)
            ->get("/crm/leads/{$won->id}/estimate")
            ->assertSessionHas('error');
    }

    public function test_the_shell_contracts_pick_up_the_module(): void
    {
        $nav = app(Nav::class);
        $this->actingAs($this->admin);
        $this->assertContains('Leads', array_column($nav->sidebar(), 'label'));

        $this->actingAs($this->staff);
        $sidebar = array_column($nav->sidebar(), 'label');
        $this->assertContains('Leads', $sidebar);      // daily sales work
        $this->assertNotContains('Sales Targets', $sidebar); // management only

        $widgets = array_column(app(Widgets::class)->all(), 'id');
        $this->assertContains('PipelineWidget', $widgets);

        $this->actingAs($this->admin)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('data-widget="PipelineWidget"', false)
            ->assertSee('Sales pipeline');
    }
}

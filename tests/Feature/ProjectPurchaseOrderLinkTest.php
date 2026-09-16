<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProjectPurchaseOrderLinkTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->client = Client::factory()->create();
    }

    protected function makePo(?Client $client = null, string $status = PurchaseOrder::STATUS_OPEN): PurchaseOrder
    {
        return PurchaseOrder::create([
            'client_id' => ($client ?? $this->client)->id,
            'title' => 'PO '.uniqid(),
            'budgeted_amount' => 5000,
            'status' => $status,
        ]);
    }

    public function test_a_project_requires_a_purchase_order(): void
    {
        $this->actingAs($this->user)->post(route('projects.store'), [
            'client_id' => $this->client->id,
            'name' => 'PO-less project',
        ])->assertSessionHasErrors('purchase_order_id');

        $this->assertSame(0, Project::count());
    }

    public function test_a_projects_po_must_belong_to_its_client(): void
    {
        $foreignPo = $this->makePo(Client::factory()->create());

        $this->actingAs($this->user)->post(route('projects.store'), [
            'client_id' => $this->client->id,
            'purchase_order_id' => $foreignPo->id,
            'name' => 'Cross-client project',
        ])->assertSessionHasErrors('purchase_order_id');

        $this->assertSame(0, Project::count());
    }

    public function test_a_po_already_linked_to_another_project_is_refused(): void
    {
        $taken = $this->makePo();
        Project::factory()->create([
            'client_id' => $this->client->id,
            'purchase_order_id' => $taken->id,
        ]);

        $this->actingAs($this->user)->post(route('projects.store'), [
            'client_id' => $this->client->id,
            'purchase_order_id' => $taken->id,
            'name' => 'Second project on the same PO',
        ])->assertSessionHasErrors('purchase_order_id');

        // The update path keeps a project's own PO but not another's.
        $ownPo = $this->makePo();
        $project = Project::factory()->create(['client_id' => $this->client->id]);

        $this->actingAs($this->user)->put(route('projects.update', $project), [
            'client_id' => $this->client->id,
            'purchase_order_id' => $taken->id,
            'name' => $project->name,
        ])->assertSessionHasErrors('purchase_order_id');

        $this->actingAs($this->user)->put(route('projects.update', $project), [
            'client_id' => $this->client->id,
            'purchase_order_id' => $ownPo->id,
            'name' => $project->name,
        ])->assertSessionHas('success');

        $this->assertEquals($project->id, $ownPo->fresh()->project_id);
    }

    public function test_saving_a_project_back_fills_the_po_link(): void
    {
        $po = $this->makePo();

        $project = Project::factory()->create([
            'client_id' => $this->client->id,
            'purchase_order_id' => $po->id,
        ]);

        $this->assertEquals($project->id, $po->fresh()->project_id);

        // Changing the project's PO moves the backlink.
        $replacement = $this->makePo();
        $project->update(['purchase_order_id' => $replacement->id]);

        $this->assertEquals($project->id, $replacement->fresh()->project_id);
        $this->assertNull($po->fresh()->project_id);
    }

    public function test_available_po_endpoint_excludes_linked_pos_and_keeps_the_projects_own(): void
    {
        $unlinked = $this->makePo();
        $linked = $this->makePo();
        $project = Project::factory()->create([
            'client_id' => $this->client->id,
            'purchase_order_id' => $linked->id,
        ]);

        $ids = fn ($response) => collect($response->json())->pluck('id');

        $response = $this->actingAs($this->user)
            ->get(route('clients.purchase-orders', $this->client).'?available=1')
            ->assertOk();

        $this->assertContains($unlinked->id, $ids($response));
        $this->assertNotContains($linked->id, $ids($response));

        // for_project keeps the project's own PO selectable...
        $response = $this->actingAs($this->user)
            ->get(route('clients.purchase-orders', $this->client).'?available=1&for_project='.$project->id)
            ->assertOk();

        $this->assertContains($unlinked->id, $ids($response));
        $this->assertContains($linked->id, $ids($response));

        // ...even once it is completed (its budget no longer matters).
        $linked->update(['status' => PurchaseOrder::STATUS_COMPLETED]);

        $response = $this->actingAs($this->user)
            ->get(route('clients.purchase-orders', $this->client).'?available=1&for_project='.$project->id)
            ->assertOk();

        $this->assertContains($linked->id, $ids($response));
    }

    public function test_po_create_form_accepts_a_client_preselect(): void
    {
        $this->actingAs($this->user)
            ->get(route('purchase-orders.create', ['client_id' => $this->client->id]))
            ->assertOk()
            ->assertSee('value="'.$this->client->id.'" selected', false);
    }

    public function test_a_newly_selected_po_must_still_be_open(): void
    {
        $completed = $this->makePo(status: PurchaseOrder::STATUS_COMPLETED);
        $partiallyUsed = $this->makePo(status: PurchaseOrder::STATUS_PARTIALLY_USED);

        // New projects can only claim open/partially_used POs.
        $this->actingAs($this->user)->post(route('projects.store'), [
            'client_id' => $this->client->id,
            'purchase_order_id' => $completed->id,
            'name' => 'Project on a completed PO',
        ])->assertSessionHasErrors('purchase_order_id');

        $this->actingAs($this->user)->post(route('projects.store'), [
            'client_id' => $this->client->id,
            'purchase_order_id' => $partiallyUsed->id,
            'name' => 'Project on a partially used PO',
        ])->assertSessionHas('success');

        // Keeping the project's own PO is fine whatever its status;
        // switching to another completed PO is not.
        $project = Project::where('purchase_order_id', $partiallyUsed->id)->first();
        $partiallyUsed->update(['status' => PurchaseOrder::STATUS_COMPLETED]);

        $this->actingAs($this->user)->put(route('projects.update', $project), [
            'client_id' => $this->client->id,
            'purchase_order_id' => $partiallyUsed->id,
            'name' => $project->name,
        ])->assertSessionHas('success');

        $this->actingAs($this->user)->put(route('projects.update', $project), [
            'client_id' => $this->client->id,
            'purchase_order_id' => $completed->id,
            'name' => $project->name,
        ])->assertSessionHasErrors('purchase_order_id');
    }

    public function test_two_projects_cannot_share_a_po_at_the_database_level(): void
    {
        $po = $this->makePo();

        Project::factory()->create([
            'client_id' => $this->client->id,
            'purchase_order_id' => $po->id,
        ]);

        $this->expectException(QueryException::class);

        Project::factory()->create([
            'client_id' => $this->client->id,
            'purchase_order_id' => $po->id,
        ]);
    }

    public function test_entries_follow_the_project_linkage(): void
    {
        $poA = $this->makePo();
        $project = Project::factory()->create([
            'client_id' => $this->client->id,
            'purchase_order_id' => $poA->id,
        ]);

        $entry = TimeEntry::create([
            'user_id' => $this->user->id,
            'project_id' => $project->id,
            'entry_date' => '2026-09-10',
            'hours' => 2,
            'status' => TimeEntry::STATUS_DRAFT,
        ]);
        $this->assertEquals($this->client->id, $entry->client_id);
        $this->assertEquals($poA->id, $entry->purchase_order_id);

        // Moving the project's PO moves its entries.
        $poB = $this->makePo();
        $project->update(['purchase_order_id' => $poB->id]);

        $entry = $entry->fresh();
        $this->assertEquals($poB->id, $entry->purchase_order_id);
        $this->assertEquals($this->client->id, $entry->client_id);

        // Reassigning the project's client and PO together — to
        // compatible records — carries both derived columns along on
        // the entries.
        $otherClient = Client::factory()->create();
        $otherPo = $this->makePo($otherClient);
        $project->update([
            'client_id' => $otherClient->id,
            'purchase_order_id' => $otherPo->id,
        ]);

        $entry = $entry->fresh();
        $this->assertEquals($otherClient->id, $entry->client_id);
        $this->assertEquals($otherPo->id, $entry->purchase_order_id);
    }

    public function test_project_profitability_index_lists_projects(): void
    {
        Role::firstOrCreate(['name' => 'accountant']);
        $this->user->assignRole('accountant');

        $project = Project::factory()->create([
            'client_id' => $this->client->id,
            'name' => 'Index Screen Project',
            'hourly_rate' => 50,
        ]);
        TimeEntry::create([
            'user_id' => $this->user->id,
            'project_id' => $project->id,
            'entry_date' => '2026-09-10',
            'hours' => 4,
            'rate' => 100,
            'billable' => true,
            'status' => TimeEntry::STATUS_APPROVED,
        ]);

        // Revenue charges at the entry's $100 rate; the staff cost
        // falls back to the project's $50 rate with no assignment.
        $this->actingAs($this->user)
            ->get(route('projects.profitability'))
            ->assertOk()
            ->assertSee('Index Screen Project')
            ->assertSee('$400.00')
            ->assertSee('$200.00');
    }
}

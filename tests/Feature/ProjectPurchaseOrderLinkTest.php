<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}

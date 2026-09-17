<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EstimateTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->client = Client::factory()->create([
            'name' => 'Test Client',
            'email' => 'client@test.com',
        ]);
    }

    public function test_estimate_list_page_requires_authentication(): void
    {
        $response = $this->get('/estimates');
        $response->assertRedirect('/login');
    }

    public function test_estimate_lines_can_reference_a_catalogue_service(): void
    {
        $service = Service::create([
            'name' => 'Cloud architecture review',
            'description' => 'Review of cloud setup',
            'hourly_rate' => 220.0000,
        ]);

        $this->actingAs($this->user)->post('/estimates', [
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'valid_until' => now()->addDays(30)->toDateString(),
            'items' => [
                [
                    'service_id' => $service->id,
                    'section' => 'Discovery',
                    'description' => 'Cloud architecture review — tailored scope',
                    'quantity' => 5,
                    'unit_price' => 200, // tailored off the standard 220 rate
                    'tax_rate' => 10,
                ],
            ],
        ])->assertSessionHas('success');

        $item = EstimateItem::query()->firstOrFail();
        $this->assertEquals($service->id, $item->service_id);
        $this->assertSame('Discovery', $item->section);
        $this->assertSame('Cloud architecture review', $item->service->name);

        // The link survives tailoring, and a deleted catalogue entry
        // unties the line without deleting it.
        $service->delete();
        $this->assertNull($item->fresh()->service_id);
    }

    public function test_optional_lines_are_excluded_from_the_committed_total(): void
    {
        $this->actingAs($this->user)->post('/estimates', [
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'valid_until' => now()->addDays(30)->toDateString(),
            'items' => [
                [
                    'description' => 'Build phase',
                    'quantity' => 1,
                    'unit_price' => 100,
                    'tax_rate' => 10,
                ],
                [
                    'description' => 'Extended warranty',
                    'quantity' => 1,
                    'unit_price' => 50,
                    'tax_rate' => 10,
                    'is_optional' => '1',
                ],
            ],
        ])->assertSessionHas('success');

        $estimate = Estimate::query()->firstOrFail();

        // Required line only: 100 + GST = 110.00; the optional extra
        // (55.00) is quoted alongside, not inside.
        $this->assertEqualsWithDelta(110.0, (float) $estimate->total, 0.001);
        $this->assertEqualsWithDelta(55.0, $estimate->optional_total, 0.001);
        $this->assertTrue($estimate->hasOptionalItems());

        $this->actingAs($this->user)
            ->get('/estimates/'.$estimate->id)
            ->assertOk()
            ->assertSee('optional')
            ->assertSee('Optional extras');
    }

    public function test_conversion_leaves_optional_lines_behind_unless_requested(): void
    {
        $estimateFor = function () {
            $this->actingAs($this->user)->post('/estimates', [
                'client_id' => $this->client->id,
                'issue_date' => now()->toDateString(),
                'valid_until' => now()->addDays(30)->toDateString(),
                'items' => [
                    ['description' => 'Base scope', 'quantity' => 1, 'unit_price' => 100, 'tax_rate' => 10],
                    ['description' => 'Extra training day', 'quantity' => 1, 'unit_price' => 50, 'tax_rate' => 10, 'is_optional' => '1'],
                ],
            ])->assertSessionHas('success');

            $estimate = Estimate::query()->latest('id')->first();
            $estimate->markAsSent();
            $estimate->accept();

            return $estimate;
        };

        // Default: the optional extra stays a quote.
        $plain = $estimateFor();
        $this->actingAs($this->user)
            ->post("/estimates/{$plain->id}/convert-to-invoice")
            ->assertRedirect();
        $this->assertSame(1, $plain->refresh()->convertedToInvoice->items()->count());

        // Ticked: the accepted extra rides along.
        $withExtra = $estimateFor();
        $this->actingAs($this->user)
            ->post("/estimates/{$withExtra->id}/convert-to-invoice", ['include_optional' => '1'])
            ->assertRedirect();
        $this->assertSame(2, $withExtra->refresh()->convertedToInvoice->items()->count());
    }

    public function test_sections_group_lines_on_the_show_screen(): void
    {
        $this->actingAs($this->user)->post('/estimates', [
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'valid_until' => now()->addDays(30)->toDateString(),
            'items' => [
                ['section' => 'Discovery', 'description' => 'Workshops', 'quantity' => 2, 'unit_price' => 100, 'tax_rate' => 10],
                ['section' => 'Discovery', 'description' => 'Report', 'quantity' => 1, 'unit_price' => 80, 'tax_rate' => 10],
                ['section' => 'Build', 'description' => 'Implementation', 'quantity' => 10, 'unit_price' => 120, 'tax_rate' => 10],
            ],
        ])->assertSessionHas('success');

        $estimate = Estimate::query()->firstOrFail();

        $this->actingAs($this->user)
            ->get('/estimates/'.$estimate->id)
            ->assertOk()
            ->assertSee('Discovery')
            ->assertSee('Build')
            ->assertSee('Workshops')
            ->assertSee('Implementation');
    }

    public function test_draft_estimates_can_be_edited_from_the_form(): void
    {
        $service = Service::create(['name' => 'Advisory', 'hourly_rate' => 300]);

        $this->actingAs($this->user)->post('/estimates', [
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'valid_until' => now()->addDays(30)->toDateString(),
            'items' => [
                ['description' => 'Initial scope', 'quantity' => 1, 'unit_price' => 100, 'tax_rate' => 10],
            ],
        ])->assertSessionHas('success');

        $estimate = Estimate::query()->firstOrFail();

        // The edit screen renders the existing lines (it was missing
        // entirely before — edit/duplicate crashed on the absent view).
        $this->actingAs($this->user)
            ->get('/estimates/'.$estimate->id.'/edit')
            ->assertOk()
            ->assertSee('Initial scope')
            ->assertSee('Update Estimate');

        $this->actingAs($this->user)->put('/estimates/'.$estimate->id, [
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'valid_until' => now()->addDays(30)->toDateString(),
            'items' => [
                [
                    'service_id' => $service->id,
                    'section' => 'Revised',
                    'description' => 'Revised scope',
                    'quantity' => 3,
                    'unit_price' => 150,
                    'tax_rate' => 10,
                    'is_optional' => '1',
                ],
            ],
        ])->assertSessionHas('success');

        $estimate->refresh();
        $this->assertSame(1, $estimate->items()->count());
        $item = $estimate->items()->first();
        $this->assertEquals($service->id, $item->service_id);
        $this->assertSame('Revised', $item->section);
        $this->assertTrue((bool) $item->is_optional);
        // Optional-only: the committed total drops to zero.
        $this->assertEqualsWithDelta(0.0, (float) $estimate->total, 0.001);
    }

    public function test_can_create_estimate(): void
    {
        $response = $this->actingAs($this->user)->post('/estimates', [
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'valid_until' => now()->addDays(30)->toDateString(),
            'items' => [
                [
                    'description' => 'Test Service',
                    'quantity' => 1,
                    'unit_price' => 100,
                    'tax_rate' => 10,
                ],
            ],
        ]);

        $response->assertSessionHas('success');
        $this->assertDatabaseHas('estimates', [
            'client_id' => $this->client->id,
            'status' => 'draft',
        ]);
    }

    public function test_estimate_generates_correct_estimate_number(): void
    {
        $estimate = Estimate::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'valid_until' => now()->addDays(30)->toDateString(),
        ]);

        $this->assertMatchesRegularExpression('/^EST-'.date('Y').'-\d{4}$/', $estimate->estimate_number);
    }

    public function test_estimate_status_constants_are_defined(): void
    {
        $this->assertEquals('draft', Estimate::STATUS_DRAFT);
        $this->assertEquals('sent', Estimate::STATUS_SENT);
        $this->assertEquals('accepted', Estimate::STATUS_ACCEPTED);
        $this->assertEquals('rejected', Estimate::STATUS_REJECTED);
        $this->assertEquals('expired', Estimate::STATUS_EXPIRED);
        $this->assertEquals('converted', Estimate::STATUS_CONVERTED);
    }

    public function test_estimate_status_transitions(): void
    {
        $estimate = Estimate::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'valid_until' => now()->addDays(30)->toDateString(),
        ]);

        $this->assertEquals(Estimate::STATUS_DRAFT, $estimate->status);

        // Draft -> Sent
        $estimate->markAsSent();
        $this->assertEquals(Estimate::STATUS_SENT, $estimate->status);

        // Sent -> Accepted
        $estimate->accept();
        $this->assertEquals(Estimate::STATUS_ACCEPTED, $estimate->status);
    }

    public function test_estimate_can_be_rejected(): void
    {
        $estimate = Estimate::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'valid_until' => now()->addDays(30)->toDateString(),
            'status' => Estimate::STATUS_SENT,
        ]);

        $estimate->reject();
        $this->assertEquals(Estimate::STATUS_REJECTED, $estimate->status);
    }

    public function test_accepted_estimate_can_be_converted_to_invoice(): void
    {
        $estimate = Estimate::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'valid_until' => now()->addDays(30)->toDateString(),
            'status' => Estimate::STATUS_ACCEPTED,
        ]);

        $estimate->items()->create([
            'description' => 'Test Service',
            'quantity' => 1,
            'unit_price' => 100,
            'tax_rate' => 10,
        ]);

        $invoice = $estimate->convertToInvoice();

        $this->assertNotNull($invoice);
        $this->assertInstanceOf(Invoice::class, $invoice);
        $this->assertEquals($this->client->id, $invoice->client_id);

        $estimate->refresh();
        $this->assertEquals(Estimate::STATUS_CONVERTED, $estimate->status);
        $this->assertNotNull($estimate->converted_at);
        $this->assertEquals($invoice->id, $estimate->converted_to_invoice_id);
    }

    public function test_converting_estimate_copies_items_to_invoice(): void
    {
        $estimate = Estimate::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'valid_until' => now()->addDays(30)->toDateString(),
            'status' => Estimate::STATUS_ACCEPTED,
        ]);

        $estimate->items()->create([
            'description' => 'Service A',
            'quantity' => 2,
            'unit_price' => 100,
            'tax_rate' => 10,
        ]);

        $estimate->items()->create([
            'description' => 'Service B',
            'quantity' => 1,
            'unit_price' => 50,
            'tax_rate' => 10,
        ]);

        $invoice = $estimate->convertToInvoice();

        $invoice->refresh();
        $this->assertCount(2, $invoice->items);
        $this->assertEquals('Service A', $invoice->items->first()->description);
        $this->assertEquals(2, $invoice->items->first()->quantity);
    }

    public function test_estimate_only_accepts_valid_transitions(): void
    {
        $estimate = Estimate::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'valid_until' => now()->addDays(30)->toDateString(),
            'status' => Estimate::STATUS_DRAFT,
        ]);

        // Draft can go to sent
        $this->assertTrue($estimate->canTransitionTo(Estimate::STATUS_SENT));

        // Draft cannot go to accepted directly
        $this->assertFalse($estimate->canTransitionTo(Estimate::STATUS_ACCEPTED));

        // Draft cannot be rejected
        $this->assertFalse($estimate->canTransitionTo(Estimate::STATUS_REJECTED));
    }

    public function test_estimate_expiry_detection(): void
    {
        $expiredEstimate = Estimate::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->subDays(60)->toDateString(),
            'valid_until' => now()->subDays(30)->toDateString(),
            'status' => Estimate::STATUS_SENT,
        ]);

        $this->assertTrue($expiredEstimate->is_expired);

        $validEstimate = Estimate::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'valid_until' => now()->addDays(30)->toDateString(),
            'status' => Estimate::STATUS_SENT,
        ]);

        $this->assertFalse($validEstimate->is_expired);
    }

    public function test_expired_estimates_scope(): void
    {
        Estimate::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->subDays(60)->toDateString(),
            'valid_until' => now()->subDays(30)->toDateString(),
            'status' => Estimate::STATUS_SENT,
        ]);

        Estimate::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'valid_until' => now()->addDays(30)->toDateString(),
            'status' => Estimate::STATUS_SENT,
        ]);

        $expiredCount = Estimate::expired()->count();
        $this->assertEquals(1, $expiredCount);
    }

    public function test_estimate_scope_active(): void
    {
        Estimate::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'valid_until' => now()->addDays(30)->toDateString(),
            'status' => Estimate::STATUS_DRAFT,
        ]);

        Estimate::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'valid_until' => now()->addDays(30)->toDateString(),
            'status' => Estimate::STATUS_REJECTED,
        ]);

        Estimate::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'valid_until' => now()->addDays(30)->toDateString(),
            'status' => Estimate::STATUS_CONVERTED,
        ]);

        $activeCount = Estimate::active()->count();
        $this->assertEquals(1, $activeCount);
    }

    public function test_estimate_calculates_totals_correctly(): void
    {
        $estimate = Estimate::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'valid_until' => now()->addDays(30)->toDateString(),
        ]);

        $estimate->items()->create([
            'description' => 'Service 1',
            'quantity' => 2,
            'unit_price' => 100,
            'tax_rate' => 10,
        ]);

        $estimate->items()->create([
            'description' => 'Service 2',
            'quantity' => 1,
            'unit_price' => 50,
            'tax_rate' => 10,
        ]);

        $estimate->recalculateTotals();
        $estimate->refresh();

        // 2*100 + 1*50 = 250 subtotal
        // Tax = 250 * 0.10 = 25
        // Total = 250 + 25 = 275
        $this->assertEquals(275, $estimate->total);
    }

    public function test_estimate_has_client_relationship(): void
    {
        $estimate = Estimate::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'valid_until' => now()->addDays(30)->toDateString(),
        ]);

        $this->assertEquals($this->client->id, $estimate->client->id);
    }

    public function test_estimate_has_items_relationship(): void
    {
        $estimate = Estimate::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'valid_until' => now()->addDays(30)->toDateString(),
        ]);

        $estimate->items()->create([
            'description' => 'Test Service',
            'quantity' => 1,
            'unit_price' => 100,
            'tax_rate' => 10,
        ]);

        $estimate->refresh();
        $this->assertCount(1, $estimate->items);
    }

    public function test_estimate_valid_until_defaults_to_30_days(): void
    {
        $estimate = Estimate::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
        ]);

        $expectedDate = now()->addDays(30)->toDateString();
        $this->assertEquals($expectedDate, $estimate->valid_until->toDateString());
    }

    public function test_estimate_converted_to_invoice_relationship(): void
    {
        $estimate = Estimate::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'valid_until' => now()->addDays(30)->toDateString(),
            'status' => Estimate::STATUS_ACCEPTED,
        ]);

        $estimate->items()->create([
            'description' => 'Test Service',
            'quantity' => 1,
            'unit_price' => 100,
            'tax_rate' => 10,
        ]);

        $invoice = $estimate->convertToInvoice();

        $this->assertEquals($invoice->id, $estimate->convertedToInvoice->id);
    }

    public function test_estimate_cannot_convert_draft_estimate(): void
    {
        $estimate = Estimate::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'valid_until' => now()->addDays(30)->toDateString(),
            'status' => Estimate::STATUS_DRAFT,
        ]);

        $invoice = $estimate->convertToInvoice();

        $this->assertNull($invoice);
        $this->assertEquals(Estimate::STATUS_DRAFT, $estimate->status);
    }

    public function test_estimate_cannot_convert_rejected_estimate(): void
    {
        $estimate = Estimate::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'valid_until' => now()->addDays(30)->toDateString(),
            'status' => Estimate::STATUS_REJECTED,
        ]);

        $invoice = $estimate->convertToInvoice();

        $this->assertNull($invoice);
    }

    public function test_estimate_cannot_convert_already_converted_estimate(): void
    {
        $estimate = Estimate::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'valid_until' => now()->addDays(30)->toDateString(),
            'status' => Estimate::STATUS_CONVERTED,
        ]);

        $invoice = $estimate->convertToInvoice();

        $this->assertNull($invoice);
    }

    public function test_estimate_valid_transitions_from_sent(): void
    {
        $estimate = Estimate::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'valid_until' => now()->addDays(30)->toDateString(),
            'status' => Estimate::STATUS_SENT,
        ]);

        $validTransitions = $estimate->getValidTransitions();

        $this->assertContains('accepted', $validTransitions);
        $this->assertContains('rejected', $validTransitions);
        $this->assertContains('expired', $validTransitions);
    }

    public function test_estimate_route_accept_requires_accepted_status(): void
    {
        $estimate = Estimate::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'valid_until' => now()->addDays(30)->toDateString(),
            'status' => Estimate::STATUS_DRAFT,
        ]);

        $response = $this->actingAs($this->user)->post(route('estimates.accept', $estimate));
        $response->assertSessionHas('error');
    }

    public function test_estimate_route_convert_requires_accepted_status(): void
    {
        $estimate = Estimate::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'valid_until' => now()->addDays(30)->toDateString(),
            'status' => Estimate::STATUS_DRAFT,
        ]);

        $response = $this->actingAs($this->user)->post(route('estimates.convertToInvoice', $estimate));
        $response->assertSessionHas('error');
    }

    public function test_estimate_formatted_total(): void
    {
        $estimate = Estimate::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'valid_until' => now()->addDays(30)->toDateString(),
        ]);

        // Add items to get the total calculated
        $estimate->items()->create([
            'description' => 'Service',
            'quantity' => 2,
            'unit_price' => 100,
            'tax_rate' => 10,
        ]);
        $estimate->items()->create([
            'description' => 'Service 2',
            'quantity' => 1,
            'unit_price' => 50,
            'tax_rate' => 10,
        ]);
        $estimate->recalculateTotals();
        $estimate->refresh();

        $this->assertEquals('A$275.00', $estimate->formatted_total);
    }

    public function test_estimate_status_transition_array_is_complete(): void
    {
        $estimate = Estimate::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'valid_until' => now()->addDays(30)->toDateString(),
            'status' => Estimate::STATUS_DRAFT,
        ]);

        // Test draft transitions
        $this->assertTrue($estimate->canTransitionTo('sent'));
        $this->assertTrue($estimate->canTransitionTo('cancelled'));

        $estimate->update(['status' => Estimate::STATUS_SENT]);
        $this->assertTrue($estimate->canTransitionTo('accepted'));
        $this->assertTrue($estimate->canTransitionTo('rejected'));
        $this->assertTrue($estimate->canTransitionTo('expired'));
    }
}

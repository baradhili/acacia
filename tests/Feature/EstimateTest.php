<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\Invoice;
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

        $this->assertMatchesRegularExpression('/^EST-' . date('Y') . '-\d{4}$/', $estimate->estimate_number);
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

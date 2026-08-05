<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\Invoice;
use App\Models\Project;
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
        $estimate = Estimate::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'valid_until' => now()->addDays(30)->toDateString(),
            'notes' => 'Test Estimate',
            'status' => Estimate::STATUS_DRAFT,
        ]);

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

    public function test_estimate_status_transitions(): void
    {
        $estimate = Estimate::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'valid_until' => now()->addDays(30)->toDateString(),
            'status' => Estimate::STATUS_DRAFT,
        ]);

        // Draft -> Sent
        $this->assertTrue($estimate->canTransitionTo(Estimate::STATUS_SENT));
        $estimate->markAsSent();
        $this->assertEquals(Estimate::STATUS_SENT, $estimate->status);

        // Sent -> Accepted
        $this->assertTrue($estimate->canTransitionTo(Estimate::STATUS_ACCEPTED));
        $estimate->accept();
        $this->assertEquals(Estimate::STATUS_ACCEPTED, $estimate->status);
    }

    public function test_estimate_rejection(): void
    {
        $estimate = Estimate::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'valid_until' => now()->addDays(30)->toDateString(),
            'status' => Estimate::STATUS_SENT,
        ]);

        $this->assertTrue($estimate->canTransitionTo(Estimate::STATUS_REJECTED));
        $estimate->reject();
        $this->assertEquals(Estimate::STATUS_REJECTED, $estimate->status);

        // Rejected estimates cannot transition further
        $this->assertFalse($estimate->canTransitionTo(Estimate::STATUS_ACCEPTED));
        $this->assertFalse($estimate->canTransitionTo(Estimate::STATUS_CONVERTED));
    }

    public function test_estimate_expiry_detection(): void
    {
        $expiredEstimate = Estimate::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->subDays(60)->toDateString(),
            'valid_until' => now()->subDays(1)->toDateString(),
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

    public function test_converting_accepted_estimate_to_invoice(): void
    {
        $estimate = Estimate::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'valid_until' => now()->addDays(30)->toDateString(),
            'status' => Estimate::STATUS_ACCEPTED,
            'notes' => 'Test notes',
            'terms' => 'Test terms',
        ]);

        $estimate->items()->create([
            'description' => 'Service',
            'quantity' => 2,
            'unit_price' => 100,
            'tax_rate' => 10,
        ]);

        $invoice = $estimate->convertToInvoice();

        $this->assertNotNull($invoice);
        $this->assertInstanceOf(Invoice::class, $invoice);

        // Verify invoice was created with correct data
        $this->assertEquals($this->client->id, $invoice->client_id);
        $this->assertEquals(Invoice::STATUS_DRAFT, $invoice->status);
        $this->assertEquals('Test notes', $invoice->notes);
        $this->assertEquals('Test terms', $invoice->terms);

        // Verify estimate is marked as converted
        $estimate->refresh();
        $this->assertEquals(Estimate::STATUS_CONVERTED, $estimate->status);
        $this->assertNotNull($estimate->converted_at);
        $this->assertEquals($invoice->id, $estimate->converted_to_invoice_id);
    }

    public function test_cannot_convert_non_accepted_estimate_to_invoice(): void
    {
        $draftEstimate = Estimate::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'valid_until' => now()->addDays(30)->toDateString(),
            'status' => Estimate::STATUS_DRAFT,
        ]);

        // Draft cannot be converted
        $this->assertFalse($draftEstimate->canTransitionTo(Estimate::STATUS_CONVERTED));
        $this->assertNull($draftEstimate->convertToInvoice());

        $sentEstimate = Estimate::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'valid_until' => now()->addDays(30)->toDateString(),
            'status' => Estimate::STATUS_SENT,
        ]);

        // Sent (but not accepted) cannot be converted
        $this->assertFalse($sentEstimate->canTransitionTo(Estimate::STATUS_CONVERTED));
        $this->assertNull($sentEstimate->convertToInvoice());
    }

    public function test_estimate_scope_active_estimates(): void
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
            'status' => Estimate::STATUS_SENT,
        ]);

        Estimate::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'valid_until' => now()->addDays(30)->toDateString(),
            'status' => Estimate::STATUS_REJECTED,
        ]);

        $activeCount = Estimate::active()->count();
        $this->assertEquals(2, $activeCount);
    }

    public function test_estimate_scope_expired_estimates(): void
    {
        Estimate::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->subDays(60)->toDateString(),
            'valid_until' => now()->subDays(1)->toDateString(),
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

    public function test_estimate_valid_until_defaults_to_30_days(): void
    {
        $estimate = Estimate::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
        ]);

        // valid_until should default to 30 days from issue_date
        $expectedDate = now()->addDays(30)->toDateString();
        $this->assertEquals($expectedDate, $estimate->valid_until->toDateString());
    }
}

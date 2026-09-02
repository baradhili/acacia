<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\DashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateInvoiceFromTimeEntriesTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Client $client;

    protected Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->client = Client::factory()->create();
        $this->project = Project::factory()->create([
            'client_id' => $this->client->id,
        ]);
    }

    private function makeEntry(array $overrides = []): TimeEntry
    {
        return TimeEntry::create(array_merge([
            'user_id' => $this->user->id,
            'project_id' => $this->project->id,
            'entry_date' => '2026-08-15',
            'hours' => 8,
            'rate' => 100,
            'billable' => true,
            'status' => TimeEntry::STATUS_APPROVED,
            'description' => 'Consulting work',
        ], $overrides));
    }

    public function test_dashboard_unbilled_time_widget_returns_real_dates(): void
    {
        $entry = $this->makeEntry();

        $widget = (new DashboardService)->getUnbilledTimeWidget();

        $this->assertSame(1, $widget['count']);
        $this->assertSame('2026-08-15', $widget['entries']->first()['date']);
        $this->assertEquals(800, $widget['total_amount']);

        // Once invoiced, the entry drops off the widget.
        $invoice = Invoice::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ]);
        $invoice->items()->create([
            'description' => 'Billed',
            'quantity' => 8,
            'unit_price' => 100,
            'tax_rate' => 10,
            'time_entry_id' => $entry->id,
        ]);

        $widget = (new DashboardService)->getUnbilledTimeWidget();
        $this->assertSame(0, $widget['count']);
    }

    public function test_credit_note_create_from_invoice_view_renders(): void
    {
        $invoice = Invoice::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ]);
        $invoice->items()->create([
            'description' => 'Line to credit',
            'quantity' => 1,
            'unit_price' => 100,
            'tax_rate' => 10,
        ]);

        $response = $this->actingAs($this->user)
            ->get(route('credit-notes.create-from-invoice', $invoice));

        $response->assertStatus(200)
            ->assertSee('Line to credit');
    }
}

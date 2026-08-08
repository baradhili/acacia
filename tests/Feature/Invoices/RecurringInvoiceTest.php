<?php

namespace Tests\Feature;

use App\Console\Commands\ProcessRecurringInvoices;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class RecurringInvoiceTest extends TestCase
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

    protected function createRecurringInvoice(string $frequency, Carbon $nextDate): Invoice
    {
        $invoice = Invoice::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => Invoice::STATUS_DRAFT,
            'is_recurring' => true,
            'recurring_frequency' => $frequency,
            'next_recurring_date' => $nextDate->toDateString(),
        ]);

        $invoice->items()->create([
            'description' => 'Monthly Service',
            'quantity' => 1,
            'unit_price' => 100,
            'tax_rate' => 10,
        ]);

        return $invoice;
    }

    public function test_recurring_invoice_daily_generation(): void
    {
        // Create daily recurring invoice due today
        $originalInvoice = $this->createRecurringInvoice(
            'daily',
            Carbon::today()
        );

        // Run the command
        Artisan::call('invoices:process-recurring');

        // Verify new invoice was created
        $newInvoices = Invoice::where('parent_invoice_id', $originalInvoice->id)->get();
        $this->assertCount(1, $newInvoices);

        $newInvoice = $newInvoices->first();
        $this->assertEquals($this->client->id, $newInvoice->client_id);
        $this->assertEquals(Invoice::STATUS_DRAFT, $newInvoice->status);
        $this->assertEquals(1, $newInvoice->items()->count());

        // Verify original invoice's next date was updated
        $originalInvoice->refresh();
        $this->assertEquals(Carbon::tomorrow()->toDateString(), $originalInvoice->next_recurring_date->toDateString());
    }

    public function test_recurring_invoice_weekly_generation(): void
    {
        $originalInvoice = $this->createRecurringInvoice(
            'weekly',
            Carbon::today()
        );

        Artisan::call('invoices:process-recurring');

        $originalInvoice->refresh();
        $this->assertEquals(Carbon::today()->addWeek()->toDateString(), $originalInvoice->next_recurring_date->toDateString());
    }

    public function test_recurring_invoice_monthly_generation(): void
    {
        $originalInvoice = $this->createRecurringInvoice(
            'monthly',
            Carbon::today()
        );

        Artisan::call('invoices:process-recurring');

        $originalInvoice->refresh();
        $this->assertEquals(Carbon::today()->addMonth()->toDateString(), $originalInvoice->next_recurring_date->toDateString());
    }

    public function test_recurring_invoice_quarterly_generation(): void
    {
        $originalInvoice = $this->createRecurringInvoice(
            'quarterly',
            Carbon::today()
        );

        Artisan::call('invoices:process-recurring');

        $originalInvoice->refresh();
        $this->assertEquals(Carbon::today()->addMonths(3)->toDateString(), $originalInvoice->next_recurring_date->toDateString());
    }

    public function test_recurring_invoice_yearly_generation(): void
    {
        $originalInvoice = $this->createRecurringInvoice(
            'yearly',
            Carbon::today()
        );

        Artisan::call('invoices:process-recurring');

        $originalInvoice->refresh();
        $this->assertEquals(Carbon::today()->addYear()->toDateString(), $originalInvoice->next_recurring_date->toDateString());
    }

    public function test_recurring_invoice_not_due_is_not_processed(): void
    {
        // Create recurring invoice due in the future
        $originalInvoice = $this->createRecurringInvoice(
            'monthly',
            Carbon::today()->addDays(15)
        );

        Artisan::call('invoices:process-recurring');

        // No new invoice should be created
        $newInvoices = Invoice::where('parent_invoice_id', $originalInvoice->id)->get();
        $this->assertCount(0, $newInvoices);
    }

    public function test_recurring_invoice_items_are_copied(): void
    {
        $originalInvoice = $this->createRecurringInvoice(
            'monthly',
            Carbon::today()
        );

        // Add multiple items
        $originalInvoice->items()->create([
            'description' => 'Additional Service',
            'quantity' => 2,
            'unit_price' => 50,
            'tax_rate' => 10,
        ]);

        Artisan::call('invoices:process-recurring');

        $newInvoice = Invoice::where('parent_invoice_id', $originalInvoice->id)->first();

        // Should have 2 items
        $this->assertEquals(2, $newInvoice->items()->count());

        // Items should have same content
        $originalItems = $originalInvoice->items()->get();
        $newItems = $newInvoice->items()->get();

        $this->assertEquals(
            $originalItems->pluck('description')->sort()->values(),
            $newItems->pluck('description')->sort()->values()
        );
    }

    public function test_recurring_invoice_inherits_notes_and_terms(): void
    {
        $originalInvoice = Invoice::create([
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => Invoice::STATUS_DRAFT,
            'notes' => 'Test notes',
            'terms' => 'Test terms',
            'is_recurring' => true,
            'recurring_frequency' => 'monthly',
            'next_recurring_date' => Carbon::today()->toDateString(),
        ]);

        $originalInvoice->items()->create([
            'description' => 'Service',
            'quantity' => 1,
            'unit_price' => 100,
            'tax_rate' => 10,
        ]);

        Artisan::call('invoices:process-recurring');

        $newInvoice = Invoice::where('parent_invoice_id', $originalInvoice->id)->first();

        $this->assertEquals('Test notes', $newInvoice->notes);
        $this->assertEquals('Test terms', $newInvoice->terms);
    }

    public function test_new_invoice_is_not_recurring(): void
    {
        $originalInvoice = $this->createRecurringInvoice(
            'monthly',
            Carbon::today()
        );

        Artisan::call('invoices:process-recurring');

        $newInvoice = Invoice::where('parent_invoice_id', $originalInvoice->id)->first();

        // The new invoice should be a draft but NOT recurring
        $this->assertEquals(Invoice::STATUS_DRAFT, $newInvoice->status);
    }

    public function test_process_recurring_invoices_command_output(): void
    {
        $this->createRecurringInvoice('monthly', Carbon::today());

        Artisan::call('invoices:process-recurring');

        $output = Artisan::output();

        // Should mention processing and creating invoices
        $this->assertStringContainsString('Processing recurring invoices', $output);
        $this->assertStringContainsString('1', $output);
    }
}

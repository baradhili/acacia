<?php

namespace Tests\Feature;

use App\Models\Bill;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Adjustment lines on bills and invoices: negative unit prices adjust
 * the ex-GST subtotal, and an explicit gst_override adjusts the GST
 * separately from the subtotal (a zero-priced line with an override
 * moves only the GST). Totals flow through to the parent document.
 */
class NegativeAdjustmentTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Supplier $supplier;

    protected Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->supplier = Supplier::create(['name' => 'Adj Supplier']);
        $this->client = Client::factory()->create();
    }

    public function test_bill_adjustment_lines_change_subtotal_and_gst_separately(): void
    {
        $bill = Bill::create(['supplier_id' => $this->supplier->id]);
        $bill->items()->create([
            'description' => 'Taxable line',
            'quantity' => 1,
            'unit_price' => 110, // incl GST → $100 + $10
            'tax_rate' => 10,
        ]);
        $bill->items()->create([
            'description' => 'Subtotal adjustment',
            'quantity' => 1,
            'unit_price' => -10, // negative price: ex-GST adjustment
            'tax_rate' => 0,
        ]);
        $bill->items()->create([
            'description' => 'GST adjustment',
            'quantity' => 1,
            'unit_price' => 0,
            'tax_rate' => 0,
            'gst_override' => -5, // moves only the GST
        ]);
        $bill->recalculateTotals();

        $this->assertEquals(90, (float) $bill->subtotal);   // 100 − 10 + 0
        $this->assertEquals(5, (float) $bill->tax_amount);  // 10 − 5
        $this->assertEquals(95, (float) $bill->total);
    }

    public function test_invoice_adjustment_lines_change_subtotal_and_gst_separately(): void
    {
        $invoice = Invoice::create([
            'client_id' => $this->client->id,
            'status' => Invoice::STATUS_DRAFT,
            'issue_date' => now()->toDateString(),
        ]);
        $invoice->items()->create([
            'description' => 'Consulting',
            'quantity' => 1,
            'unit_price' => 100,
            'tax_rate' => 10,
        ]);
        $invoice->items()->create([
            'description' => 'Goodwill discount',
            'quantity' => 1,
            'unit_price' => -10,
            'tax_rate' => 0,
        ]);
        $invoice->items()->create([
            'description' => 'GST correction',
            'quantity' => 1,
            'unit_price' => 0,
            'tax_rate' => 0,
            'gst_override' => -5,
        ]);
        $invoice->recalculateTotals();

        $this->assertEquals(90, (float) $invoice->subtotal);
        $this->assertEquals(5, (float) $invoice->tax_amount);
        $this->assertEquals(95, (float) $invoice->total);
    }

    public function test_adjustment_lines_survive_the_bill_form(): void
    {
        $response = $this->actingAs($this->user)->post('/bills', [
            'supplier_id' => $this->supplier->id,
            'bill_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'items' => [
                ['description' => 'Taxable', 'quantity' => 1, 'unit_price' => 110, 'gst' => '1'],
                ['description' => 'Subtotal adj', 'quantity' => 1, 'unit_price' => -10],
                ['description' => 'GST adj', 'quantity' => 1, 'unit_price' => 0, 'gst_override' => -5],
            ],
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertSessionHas('success');

        $bill = Bill::where('supplier_id', $this->supplier->id)->latest('id')->firstOrFail();
        $this->assertEquals(90, (float) $bill->subtotal);
        $this->assertEquals(5, (float) $bill->tax_amount);
        $this->assertEquals(95, (float) $bill->total);
    }

    public function test_adjustment_lines_survive_the_invoice_form(): void
    {
        $response = $this->actingAs($this->user)->post('/invoices', [
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'items' => [
                ['description' => 'Consulting', 'quantity' => 1, 'unit_price' => 100, 'tax_rate' => 10],
                ['description' => 'Discount', 'quantity' => 1, 'unit_price' => -10, 'tax_rate' => 0],
                ['description' => 'GST correction', 'quantity' => 1, 'unit_price' => 0, 'tax_rate' => 0, 'gst_override' => -5],
            ],
        ]);

        $response->assertSessionHasNoErrors();

        $invoice = Invoice::where('client_id', $this->client->id)->latest('id')->firstOrFail();
        $this->assertEquals(90, (float) $invoice->subtotal);
        $this->assertEquals(5, (float) $invoice->tax_amount);
        $this->assertEquals(95, (float) $invoice->total);
    }
}

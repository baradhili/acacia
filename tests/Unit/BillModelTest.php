<?php

namespace Tests\Unit;

use App\Models\Bill;
use App\Models\BillItem;
use App\Models\Supplier;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BillModelTest extends TestCase
{
    use RefreshDatabase;

    protected Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->supplier = Supplier::create(['name' => 'Test Supplier']);
    }

    public function test_bill_has_expected_columns(): void
    {
        $columns = \Schema::getColumns('bills');
        $names = array_column($columns, 'name');

        foreach ([
            'bill_number', 'supplier_id', 'project_id', 'created_by', 'status',
            'bill_date', 'due_date', 'paid_at', 'subtotal', 'tax_amount',
            'discount_amount', 'total', 'notes', 'reference',
        ] as $column) {
            $this->assertContains($column, $names);
        }
        // The old receipt_path column is gone — documents handle attachments.
        $this->assertNotContains('receipt_path', $names);
    }

    public function test_bill_item_has_expense_account_column(): void
    {
        $names = array_column(\Schema::getColumns('bill_items'), 'name');
        $this->assertContains('expense_account_id', $names);
        $this->assertContains('tax_rate', $names);
    }

    public function test_status_constants(): void
    {
        $this->assertEquals('draft', Bill::STATUS_DRAFT);
        $this->assertEquals('open', Bill::STATUS_OPEN);
        $this->assertEquals('partially_paid', Bill::STATUS_PARTIALLY_PAID);
        $this->assertEquals('paid', Bill::STATUS_PAID);
        $this->assertEquals('overdue', Bill::STATUS_OVERDUE);
        $this->assertEquals('cancelled', Bill::STATUS_CANCELLED);
    }

    public function test_supplier_relationship(): void
    {
        $bill = Bill::create(['supplier_id' => $this->supplier->id]);
        $this->assertInstanceOf(Supplier::class, $bill->supplier);
        $this->assertEquals($this->supplier->id, $bill->supplier->id);
    }

    public function test_creating_hook_defaults(): void
    {
        $bill = Bill::create(['supplier_id' => $this->supplier->id]);

        $this->assertEquals(Bill::STATUS_DRAFT, $bill->status);
        $this->assertNotEmpty($bill->bill_number);
        $this->assertEquals(now()->toDateString(), $bill->bill_date->toDateString());
        // No invoice_due_days-style config exists for bills — the form
        // supplies the due date (mirrors Invoice behaviour).
    }

    public function test_item_saving_hook_calculates_totals(): void
    {
        $bill = Bill::create(['supplier_id' => $this->supplier->id]);
        $item = $bill->items()->create([
            'description' => 'Item',
            'quantity' => 3,
            'unit_price' => 110, // GST-inclusive
            'tax_rate' => 10,
            'discount_percent' => 10,
        ]);

        // gross 330, discount 33, total paid 297 (incl $27 GST, ex-GST 270)
        $this->assertEquals(33, (float) $item->discount_amount);
        $this->assertEquals(27, (float) $item->tax_amount);
        $this->assertEquals(297, (float) $item->total);
        $this->assertEquals(270, (float) $item->subtotal);
    }

    public function test_item_saved_hook_rolls_up_to_bill(): void
    {
        $bill = Bill::create(['supplier_id' => $this->supplier->id]);
        $bill->items()->create([
            'description' => 'A',
            'quantity' => 1,
            'unit_price' => 110, // GST-inclusive
            'tax_rate' => 10,
        ]);

        $bill = $bill->fresh();
        $this->assertEquals(100, (float) $bill->subtotal);
        $this->assertEquals(10, (float) $bill->tax_amount);
        $this->assertEquals(110, (float) $bill->total);
    }

    public function test_amount_paid_and_amount_due_accessors(): void
    {
        $bill = Bill::create(['supplier_id' => $this->supplier->id]);
        $bill->items()->create([
            'description' => 'A',
            'quantity' => 1,
            'unit_price' => 110, // GST-inclusive
            'tax_rate' => 10,
        ]);
        $bill->recalculateTotals();

        $this->assertEquals(0, (float) $bill->amount_paid);
        $this->assertEquals(110, (float) $bill->amount_due);
    }

    public function test_is_overdue_accessor(): void
    {
        $overdue = Bill::create([
            'supplier_id' => $this->supplier->id,
            'due_date' => now()->subDay()->toDateString(),
        ]);
        $this->assertTrue($overdue->is_overdue);

        $paid = Bill::create([
            'supplier_id' => $this->supplier->id,
            'due_date' => now()->subDay()->toDateString(),
        ]);
        $paid->update(['status' => Bill::STATUS_PAID]);
        $this->assertFalse($paid->is_overdue);
    }

    public function test_scope_overdue_requires_outstanding_balance(): void
    {
        $pastDue = Bill::create([
            'supplier_id' => $this->supplier->id,
            'bill_date' => now()->subDays(60)->toDateString(),
            'due_date' => now()->subDays(30)->toDateString(),
        ]);
        $pastDue->items()->create([
            'description' => 'A',
            'quantity' => 1,
            'unit_price' => 100,
            'tax_rate' => 0,
        ]);
        $pastDue->recalculateTotals();
        $pastDue->markAsOpen();

        // Effectively paid (allocation covers total) but status not yet flipped.
        $paid = Bill::create([
            'supplier_id' => $this->supplier->id,
            'bill_date' => now()->subDays(60)->toDateString(),
            'due_date' => now()->subDays(30)->toDateString(),
        ]);
        $paid->items()->create([
            'description' => 'A',
            'quantity' => 1,
            'unit_price' => 100,
            'tax_rate' => 0,
        ]);
        $paid->recalculateTotals();
        $paid->markAsOpen();
        $paid->allocations()->create([
            'bill_payment_id' => \App\Models\BillPayment::create([
                'supplier_id' => $this->supplier->id,
                'amount' => 100,
                'payment_date' => now()->toDateString(),
                'payment_method' => 'bank_transfer',
            ])->id,
            'amount' => 100,
        ]);

        $ids = Bill::overdue()->pluck('id');
        $this->assertContains($pastDue->id, $ids);
        $this->assertNotContains($paid->id, $ids);
    }

    public function test_update_status_from_payments_never_clobbers_draft(): void
    {
        $bill = Bill::create(['supplier_id' => $this->supplier->id]);
        $bill->items()->create([
            'description' => 'A',
            'quantity' => 1,
            'unit_price' => 100,
            'tax_rate' => 10,
        ]);
        $bill->recalculateTotals();

        $bill->updateStatusFromPayments();

        $this->assertEquals(Bill::STATUS_DRAFT, $bill->fresh()->status);
    }
}

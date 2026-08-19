<?php

namespace Tests\Feature;

use App\Models\Bill;
use App\Models\BillItem;
use App\Models\BillPayment;
use App\Models\Supplier;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BillTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->supplier = Supplier::create([
            'name' => 'Test Supplier',
            'email' => 'supplier@test.com',
        ]);
    }

    protected function itemDefaults(): array
    {
        return [
            'description' => 'Test Item',
            'quantity' => 1,
            // GST-inclusive: $100 ex GST + $10 GST — enter what you pay
            'unit_price' => 110,
            'gst' => '1',
        ];
    }

    public function test_bill_list_page_requires_authentication(): void
    {
        $response = $this->get('/bills');
        $response->assertRedirect('/login');
    }

    public function test_can_create_bill(): void
    {
        $response = $this->actingAs($this->user)->post('/bills', [
            'supplier_id' => $this->supplier->id,
            'bill_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'items' => [$this->itemDefaults()],
        ]);

        $response->assertSessionHas('success');
        $this->assertDatabaseHas('bills', [
            'supplier_id' => $this->supplier->id,
            'status' => 'draft',
        ]);
    }

    public function test_bill_generates_correct_bill_number(): void
    {
        $bill = Bill::create(['supplier_id' => $this->supplier->id]);

        $this->assertMatchesRegularExpression('/^BILL-' . date('Y') . '-\d{4}$/', $bill->bill_number);
    }

    public function test_bill_calculates_mixed_gst_totals_correctly(): void
    {
        // Line A: $110 GST-inclusive = $100 + $10 GST (taxable)
        // Line B: $100 GST-free   = $100
        // => subtotal 200, tax 10, total 210
        $bill = Bill::create(['supplier_id' => $this->supplier->id]);
        $bill->items()->create([
            'description' => 'Taxable line',
            'quantity' => 1,
            'unit_price' => 110,
            'tax_rate' => 10,
        ]);
        $bill->items()->create([
            'description' => 'GST-free line (bank fee)',
            'quantity' => 1,
            'unit_price' => 100,
            'tax_rate' => 0,
        ]);
        $bill->recalculateTotals();

        $this->assertEquals(200, (float) $bill->subtotal);
        $this->assertEquals(10, (float) $bill->tax_amount);
        $this->assertEquals(210, (float) $bill->total);

        $this->assertFalse($bill->items[0]->is_gst_free);
        $this->assertTrue($bill->items[1]->is_gst_free);
    }

    public function test_gst_tick_backs_out_the_portion_instead_of_adding(): void
    {
        // Regression: the tick used to ADD 10% on top of the entered amount
        // ($110 → $121). It must mean the amount INCLUDES GST instead:
        // $110 stays $110, with $10 of it GST.
        $response = $this->actingAs($this->user)->post('/bills', [
            'supplier_id' => $this->supplier->id,
            'bill_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'items' => [
                [
                    'description' => 'Parking (GST inclusive)',
                    'quantity' => 1,
                    'unit_price' => 110,
                    'gst' => '1',
                ],
            ],
        ]);

        $response->assertSessionHas('success');
        $bill = Bill::first();
        $this->assertEquals(110, (float) $bill->total);
        $this->assertEquals(10, (float) $bill->tax_amount);
        $this->assertEquals(100, (float) $bill->subtotal);

        // Same amount unticked: nothing is GST.
        $this->actingAs($this->user)->post('/bills', [
            'supplier_id' => $this->supplier->id,
            'bill_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'items' => [
                [
                    'description' => 'Bank fee (GST-free)',
                    'quantity' => 1,
                    'unit_price' => 110,
                ],
            ],
        ]);

        $free = Bill::where('id', '!=', $bill->id)->first();
        $this->assertEquals(110, (float) $free->total);
        $this->assertEquals(0, (float) $free->tax_amount);
        $this->assertEquals(110, (float) $free->subtotal);
    }

    public function test_unchecked_gst_posts_tax_rate_zero(): void
    {
        $response = $this->actingAs($this->user)->post('/bills', [
            'supplier_id' => $this->supplier->id,
            'bill_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'items' => [
                [
                    'description' => 'GST-free purchase',
                    'quantity' => 1,
                    'unit_price' => 50,
                    // no 'gst' key — unchecked checkbox
                ],
            ],
        ]);

        $response->assertSessionHas('success');
        $bill = Bill::first();
        $this->assertEquals(0, (float) $bill->items->first()->tax_rate);
        $this->assertEquals(0, (float) $bill->tax_amount);
        $this->assertEquals(50, (float) $bill->total);
    }

    public function test_paid_at_entry_creates_payment_and_marks_paid(): void
    {
        $response = $this->actingAs($this->user)->post('/bills', [
            'supplier_id' => $this->supplier->id,
            'bill_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'items' => [$this->itemDefaults()],
            'paid_now' => '1',
            'payment_date' => now()->toDateString(),
            'payment_method' => 'credit_card',
            'payment_reference' => 'CARD-123',
        ]);

        $response->assertSessionHas('success');

        $bill = Bill::first();
        $this->assertNotNull($bill);
        $this->assertEquals(Bill::STATUS_PAID, $bill->status);
        $this->assertNotNull($bill->paid_at);
        $this->assertEquals(110, (float) $bill->amount_paid); // $110 incl GST, as paid

        $payment = BillPayment::first();
        $this->assertNotNull($payment);
        $this->assertMatchesRegularExpression('/^SPAY-' . date('Y') . '-\d{4}$/', $payment->payment_number);
        $this->assertEquals('credit_card', $payment->payment_method);
        $this->assertEquals('CARD-123', $payment->reference);
        // No IFRS accounts are seeded here, so posting no-ops (logged, non-fatal).
        $this->assertNull($payment->ifrs_payment_id);
    }

    public function test_paid_at_entry_requires_payment_details(): void
    {
        $response = $this->actingAs($this->user)->post('/bills', [
            'supplier_id' => $this->supplier->id,
            'bill_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'items' => [$this->itemDefaults()],
            'paid_now' => '1',
            // payment_date / payment_method missing
        ]);

        $response->assertSessionHasErrors(['payment_date', 'payment_method']);
    }

    public function test_bill_status_transitions(): void
    {
        $bill = Bill::create(['supplier_id' => $this->supplier->id]);

        // draft -> open
        $this->assertTrue($bill->markAsOpen());
        $this->assertEquals(Bill::STATUS_OPEN, $bill->status);

        // open -> cancelled is valid
        $openBill = Bill::create(['supplier_id' => $this->supplier->id]);
        $openBill->markAsOpen();
        $this->assertTrue($openBill->cancel());

        // paid bills cannot transition anywhere
        $paidBill = Bill::create(['supplier_id' => $this->supplier->id]);
        $paidBill->markAsOpen();
        $paidBill->update(['status' => Bill::STATUS_PAID, 'paid_at' => now()]);
        $this->assertFalse($paidBill->canTransitionTo(Bill::STATUS_CANCELLED));

        // cancelled is final
        $this->assertFalse($openBill->markAsOpen());
    }

    public function test_only_draft_bills_can_be_edited_or_deleted(): void
    {
        $bill = Bill::create(['supplier_id' => $this->supplier->id]);
        $this->assertTrue($bill->canBeEdited());

        $bill->markAsOpen();
        $this->assertFalse($bill->canBeEdited());

        $response = $this->actingAs($this->user)
            ->get(route('bills.edit', $bill));
        $response->assertRedirect(route('bills.show', $bill));

        $response = $this->actingAs($this->user)
            ->delete(route('bills.destroy', $bill));
        $response->assertRedirect(route('bills.index'));
        $this->assertDatabaseHas('bills', ['id' => $bill->id]);
    }

    public function test_edit_upserts_items_preserving_ids(): void
    {
        $bill = Bill::create(['supplier_id' => $this->supplier->id]);
        $itemA = $bill->items()->create([
            'description' => 'Keep me',
            'quantity' => 1,
            'unit_price' => 100,
            'tax_rate' => 10,
        ]);
        $bill->items()->create([
            'description' => 'Remove me',
            'quantity' => 1,
            'unit_price' => 50,
            'tax_rate' => 0,
        ]);
        $bill->recalculateTotals();

        $response = $this->actingAs($this->user)->put(route('bills.update', $bill), [
            'supplier_id' => $this->supplier->id,
            'bill_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'items' => [
                [
                    'id' => $itemA->id,
                    'description' => 'Keep me (edited)',
                    'quantity' => 2,
                    'unit_price' => 110,
                    'gst' => '1',
                ],
                [
                    'description' => 'New line',
                    'quantity' => 1,
                    'unit_price' => 25,
                    // GST-free
                ],
            ],
        ]);

        $response->assertSessionHas('success');
        $bill->refresh();

        // Same item id retained, removed line gone, new line added.
        $this->assertTrue($bill->items->contains('id', $itemA->id));
        $this->assertEquals(2, $bill->items->count());
        $this->assertEquals('Keep me (edited)', $itemA->fresh()->description);

        // 2 × $110 (incl $20 GST) = 220, plus 25 GST-free = 245
        $this->assertEquals(225, (float) $bill->subtotal);
        $this->assertEquals(20, (float) $bill->tax_amount);
        $this->assertEquals(245, (float) $bill->total);
    }

    public function test_payment_rejected_for_draft_and_paid_bills(): void
    {
        $draft = Bill::create(['supplier_id' => $this->supplier->id]);
        $response = $this->actingAs($this->user)
            ->post(route('bills.recordPayment', $draft), [
                'amount' => 10,
                'payment_date' => now()->toDateString(),
                'payment_method' => 'bank_transfer',
            ]);
        $response->assertSessionHas('error');

        $paid = Bill::create(['supplier_id' => $this->supplier->id]);
        $paid->markAsOpen();
        $paid->update(['status' => Bill::STATUS_PAID]);
        $response = $this->actingAs($this->user)
            ->post(route('bills.recordPayment', $paid), [
                'amount' => 10,
                'payment_date' => now()->toDateString(),
                'payment_method' => 'bank_transfer',
            ]);
        $response->assertSessionHas('error');
        $this->assertEquals(0, BillPayment::count());
    }

    public function test_record_full_payment_marks_bill_paid(): void
    {
        $bill = Bill::create(['supplier_id' => $this->supplier->id]);
        $bill->items()->create([
            'description' => 'Item',
            'quantity' => 2,
            'unit_price' => 137.5, // 2 × $137.50 = $275 incl ($250 + $25 GST)
            'tax_rate' => 10,
        ]);
        $bill->recalculateTotals();
        $bill->markAsOpen();

        $response = $this->actingAs($this->user)
            ->post(route('bills.recordPayment', $bill), [
                'amount' => 275,
                'payment_date' => now()->toDateString(),
                'payment_method' => 'bank_transfer',
            ]);

        $response->assertSessionHas('success');
        $bill->refresh();
        $this->assertEquals(Bill::STATUS_PAID, $bill->status);
        $this->assertEquals(0, (float) $bill->amount_due);
    }

    public function test_partial_payment_sets_partially_paid(): void
    {
        $bill = Bill::create(['supplier_id' => $this->supplier->id]);
        $bill->items()->create([
            'description' => 'Item',
            'quantity' => 1,
            'unit_price' => 110, // $110 incl GST
            'tax_rate' => 10,
        ]);
        $bill->recalculateTotals();
        $bill->markAsOpen();

        $this->actingAs($this->user)
            ->post(route('bills.recordPayment', $bill), [
                'amount' => 50,
                'payment_date' => now()->toDateString(),
                'payment_method' => 'bank_transfer',
            ]);

        $bill->refresh();
        $this->assertEquals(Bill::STATUS_PARTIALLY_PAID, $bill->status);
        $this->assertEquals(60, (float) $bill->amount_due);

        // Overpayment is rejected by validation (max = amount due)
        $response = $this->actingAs($this->user)
            ->post(route('bills.recordPayment', $bill), [
                'amount' => 100,
                'payment_date' => now()->toDateString(),
                'payment_method' => 'bank_transfer',
            ]);
        $response->assertSessionHasErrors('amount');
    }

    public function test_overdue_scope_excludes_paid_bills(): void
    {
        $overdueBill = Bill::create([
            'supplier_id' => $this->supplier->id,
            'bill_date' => now()->subDays(60)->toDateString(),
            'due_date' => now()->subDays(30)->toDateString(),
        ]);
        $overdueBill->items()->create([
            'description' => 'Item',
            'quantity' => 1,
            'unit_price' => 100,
            'tax_rate' => 0,
        ]);
        $overdueBill->recalculateTotals();
        $overdueBill->markAsOpen();

        $paidBill = Bill::create([
            'supplier_id' => $this->supplier->id,
            'bill_date' => now()->subDays(60)->toDateString(),
            'due_date' => now()->subDays(30)->toDateString(),
        ]);
        $paidBill->items()->create([
            'description' => 'Item',
            'quantity' => 1,
            'unit_price' => 100,
            'tax_rate' => 0,
        ]);
        $paidBill->recalculateTotals();
        $paidBill->markAsOpen();
        $paidBill->update(['status' => Bill::STATUS_PAID, 'paid_at' => now()]);

        $overdueIds = Bill::overdue()->pluck('id');

        $this->assertContains($overdueBill->id, $overdueIds);
        $this->assertNotContains($paidBill->id, $overdueIds);
    }

    public function test_index_filters_by_status_and_supplier(): void
    {
        $otherSupplier = Supplier::create(['name' => 'Other Supplier']);

        $open = Bill::create(['supplier_id' => $this->supplier->id]);
        $open->markAsOpen();
        $draft = Bill::create(['supplier_id' => $otherSupplier->id]);

        $response = $this->actingAs($this->user)
            ->get(route('bills.index', ['status' => 'open']));
        $response->assertStatus(200)
            ->assertSee($open->bill_number)
            ->assertDontSee($draft->bill_number);

        $response = $this->actingAs($this->user)
            ->get(route('bills.index', ['supplier_id' => $otherSupplier->id]));
        $response->assertStatus(200)
            ->assertSee($draft->bill_number)
            ->assertDontSee($open->bill_number);
    }

    public function test_update_status_from_payments_rederives_overdue(): void
    {
        $bill = Bill::create([
            'supplier_id' => $this->supplier->id,
            'due_date' => now()->subDays(10)->toDateString(),
        ]);
        $bill->items()->create([
            'description' => 'Item',
            'quantity' => 1,
            'unit_price' => 100,
            'tax_rate' => 0,
        ]);
        $bill->recalculateTotals();
        $bill->markAsOpen();
        $bill->update(['status' => Bill::STATUS_PARTIALLY_PAID]);

        // Payments removed: past-due bill regains overdue, not open.
        $bill->updateStatusFromPayments();
        $this->assertEquals(Bill::STATUS_OVERDUE, $bill->status);
    }
}

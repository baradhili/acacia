<?php

namespace Tests\Feature;

use App\Models\Bill;
use App\Models\BillPayment;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BillPaymentTest extends TestCase
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

    protected function createOpenBill(float $unitPrice = 100, int $count = 1): Bill
    {
        $bill = Bill::create(['supplier_id' => $this->supplier->id]);
        for ($i = 0; $i < $count; $i++) {
            $bill->items()->create([
                'description' => 'Item ' . ($i + 1),
                'quantity' => 1,
                'unit_price' => $unitPrice,
                'tax_rate' => 10,
            ]);
        }
        $bill->recalculateTotals();
        $bill->markAsOpen();

        return $bill;
    }

    public function test_payment_pages_require_authentication(): void
    {
        $this->get('/bill-payments')->assertRedirect('/login');
    }

    public function test_can_record_supplier_payment_with_manual_allocation(): void
    {
        $bill = $this->createOpenBill(); // total 110

        $response = $this->actingAs($this->user)->post('/bill-payments', [
            'supplier_id' => $this->supplier->id,
            'amount' => 110,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'bank_transfer',
            'allocate_type' => 'manual',
            'bill_allocations' => [
                ['bill_id' => $bill->id, 'amount' => 110],
            ],
        ]);

        $response->assertSessionHas('success');
        $bill->refresh();
        $this->assertEquals(Bill::STATUS_PAID, $bill->status);

        $payment = BillPayment::first();
        $this->assertEquals(110, (float) $payment->allocated_amount);
        $this->assertEquals(0, (float) $payment->unallocated_amount);
    }

    public function test_unallocated_payment_leaves_bill_open(): void
    {
        $bill = $this->createOpenBill();

        $this->actingAs($this->user)->post('/bill-payments', [
            'supplier_id' => $this->supplier->id,
            'amount' => 110,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'bank_transfer',
            'allocate_type' => 'no',
        ]);

        $bill->refresh();
        $this->assertEquals(Bill::STATUS_OPEN, $bill->status);

        $payment = BillPayment::first();
        $this->assertEquals(110, (float) $payment->unallocated_amount);
    }

    public function test_allocate_rejects_other_suppliers_bill(): void
    {
        $payment = BillPayment::create([
            'supplier_id' => $this->supplier->id,
            'amount' => 100,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'bank_transfer',
        ]);

        $otherSupplier = Supplier::create(['name' => 'Other Supplier']);
        $otherBill = Bill::create(['supplier_id' => $otherSupplier->id]);
        $otherBill->items()->create([
            'description' => 'Item',
            'quantity' => 1,
            'unit_price' => 100,
            'tax_rate' => 0,
        ]);
        $otherBill->recalculateTotals();
        $otherBill->markAsOpen();

        $response = $this->actingAs($this->user)
            ->post(route('bill-payments.allocate', $payment), [
                'bill_id' => $otherBill->id,
                'amount' => 50,
            ]);

        $response->assertSessionHas('error');
        $this->assertEquals(0, (float) $payment->allocated_amount);
    }

    public function test_allocate_rejects_amount_over_unallocated_balance(): void
    {
        $payment = BillPayment::create([
            'supplier_id' => $this->supplier->id,
            'amount' => 50,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'bank_transfer',
        ]);
        $bill = $this->createOpenBill();

        $response = $this->actingAs($this->user)
            ->post(route('bill-payments.allocate', $payment), [
                'bill_id' => $bill->id,
                'amount' => 100,
            ]);

        $response->assertSessionHas('error');
    }

    public function test_over_allocation_throws_from_model(): void
    {
        $payment = BillPayment::create([
            'supplier_id' => $this->supplier->id,
            'amount' => 50,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'bank_transfer',
        ]);
        $bill = $this->createOpenBill();

        $this->expectException(\InvalidArgumentException::class);
        $payment->allocateToBill($bill, 100);
    }

    public function test_remove_allocation_recomputes_bill_status(): void
    {
        $bill = $this->createOpenBill();
        $payment = BillPayment::createWithUniqueNumber([
            'supplier_id' => $this->supplier->id,
            'amount' => 110,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'bank_transfer',
        ]);
        $payment->allocateToBill($bill, 110);
        $this->assertEquals(Bill::STATUS_PAID, $bill->fresh()->status);

        $this->actingAs($this->user)
            ->post(route('bill-payments.removeAllocation', [$payment, $bill]));

        $bill = $bill->fresh();
        $this->assertEquals(Bill::STATUS_OPEN, $bill->status);
        $this->assertEquals(0, (float) $bill->amount_paid);
    }

    public function test_cannot_shrink_amount_below_allocated(): void
    {
        $bill = $this->createOpenBill();
        $payment = BillPayment::createWithUniqueNumber([
            'supplier_id' => $this->supplier->id,
            'amount' => 110,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'bank_transfer',
        ]);
        $payment->allocateToBill($bill, 110);

        $response = $this->actingAs($this->user)
            ->put(route('bill-payments.update', $payment), [
                'supplier_id' => $this->supplier->id,
                'amount' => 50,
                'payment_date' => now()->toDateString(),
                'payment_method' => 'bank_transfer',
            ]);

        $response->assertSessionHas('error');
    }

    public function test_cannot_change_supplier_with_allocations(): void
    {
        $bill = $this->createOpenBill();
        $payment = BillPayment::createWithUniqueNumber([
            'supplier_id' => $this->supplier->id,
            'amount' => 110,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'bank_transfer',
        ]);
        $payment->allocateToBill($bill, 110);

        $otherSupplier = Supplier::create(['name' => 'Other Supplier']);

        $response = $this->actingAs($this->user)
            ->put(route('bill-payments.update', $payment), [
                'supplier_id' => $otherSupplier->id,
                'amount' => 110,
                'payment_date' => now()->toDateString(),
                'payment_method' => 'bank_transfer',
            ]);

        $response->assertSessionHas('error');
    }

    public function test_destroy_deletes_allocations_and_recomputes_bills(): void
    {
        $bill = $this->createOpenBill();
        $payment = BillPayment::createWithUniqueNumber([
            'supplier_id' => $this->supplier->id,
            'amount' => 110,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'bank_transfer',
        ]);
        $payment->allocateToBill($bill, 110);
        $this->assertEquals(Bill::STATUS_PAID, $bill->fresh()->status);

        $this->actingAs($this->user)
            ->delete(route('bill-payments.destroy', $payment));

        $this->assertDatabaseMissing('bill_payments', ['id' => $payment->id]);
        $bill = $bill->fresh();
        $this->assertEquals(Bill::STATUS_OPEN, $bill->status);
        $this->assertEquals(0, (float) $bill->amount_paid);
    }

    public function test_get_supplier_bills_returns_only_outstanding(): void
    {
        $outstanding = $this->createOpenBill();

        $paid = $this->createOpenBill();
        $payment = BillPayment::createWithUniqueNumber([
            'supplier_id' => $this->supplier->id,
            'amount' => 110,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'bank_transfer',
        ]);
        $payment->allocateToBill($paid, 110);

        $draft = Bill::create(['supplier_id' => $this->supplier->id]);

        $response = $this->actingAs($this->user)
            ->get(route('bill-payments.supplier-bills', $this->supplier));

        $response->assertStatus(200);
        $bills = $response->json();
        $this->assertCount(1, $bills);
        $this->assertEquals($outstanding->id, $bills[0]['id']);
        $this->assertEquals($outstanding->bill_number, $bills[0]['bill_number']);
    }

    public function test_void_deletes_allocations_and_restores_status(): void
    {
        $bill = $this->createOpenBill();
        $payment = BillPayment::createWithUniqueNumber([
            'supplier_id' => $this->supplier->id,
            'amount' => 110,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'bank_transfer',
        ]);
        $payment->allocateToBill($bill, 110);

        $this->assertTrue($payment->void());
        $this->assertEquals(BillPayment::STATUS_VOID, $payment->fresh()->status);
        $this->assertEquals(Bill::STATUS_OPEN, $bill->fresh()->status);
        $this->assertEquals(0, $payment->allocations()->count());
    }
}

<?php

namespace Tests\Feature;

use App\Models\Bill;
use App\Models\BillPayment;
use App\Models\ReimbursementPayment;
use App\Models\Supplier;
use App\Models\User;
use IFRS\Models\Account;
use IFRS\Models\Currency;
use IFRS\Models\Entity;
use IFRS\Models\LineItem;
use IFRS\Models\ReportingPeriod;
use IFRS\Models\Transaction;
use IFRS\Models\Vat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Payroll\Models\Employee;
use Spatie\Permission\Models\Role;
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

    protected function createOpenBill(float $unitPrice = 110, int $count = 1): Bill
    {
        $bill = Bill::create(['supplier_id' => $this->supplier->id]);
        for ($i = 0; $i < $count; $i++) {
            $bill->items()->create([
                'description' => 'Item '.($i + 1),
                'quantity' => 1,
                'unit_price' => $unitPrice,
                'tax_rate' => 10, // unit_price is GST-inclusive
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

        // Partially paid: total 220, 110 allocated → 110 still due.
        $partial = $this->createOpenBill(220); // 220 incl GST = 200 + 20
        $partialPayment = BillPayment::createWithUniqueNumber([
            'supplier_id' => $this->supplier->id,
            'amount' => 110,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'bank_transfer',
        ]);
        $partialPayment->allocateToBill($partial, 110);

        $draft = Bill::create(['supplier_id' => $this->supplier->id]);

        $response = $this->actingAs($this->user)
            ->get(route('bill-payments.supplier-bills', $this->supplier));

        $response->assertStatus(200);
        $bills = collect($response->json())->keyBy('id');

        $this->assertCount(2, $bills);
        $this->assertArrayNotHasKey($draft->id, $bills);
        $this->assertArrayNotHasKey($paid->id, $bills);

        // amount_due drives the 100%-allocation default in the UI.
        $this->assertEquals(110.0, $bills[$outstanding->id]['amount_due']);
        $this->assertEquals(220.0, $bills[$partial->id]['total']);
        $this->assertEquals(110.0, $bills[$partial->id]['amount_due']);
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

    /**
     * Minimum IFRS chart for posting supplier payments: entity + period,
     * bank (320), revenue (4100), GST Payable (2200, CONTROL for the
     * GST 10% Vat) and the expense accounts bills post to. 2280 is
     * created lazily by BillPayment::ensureReimbursementAccount().
     */
    protected function seedIfrs(): void
    {
        $entity = Entity::create([
            'name' => 'Test Entity',
            'locale' => 'en_AU',
            'multi_currency' => false,
            'year_start' => 1,
        ]);

        $currency = Currency::create([
            'name' => 'Australian Dollar',
            'currency_code' => 'AUD',
            'entity_id' => $entity->id,
        ]);
        $entity->update(['currency_id' => $currency->id]);

        ReportingPeriod::create([
            'period_count' => 1,
            'calendar_year' => (int) now()->format('Y'),
            'status' => ReportingPeriod::OPEN,
            'entity_id' => $entity->id,
        ]);

        foreach ([
            ['Operating Account', Account::BANK, 320],
            ['Consulting Revenue', Account::OPERATING_REVENUE, 4100],
            ['GST Payable', Account::CONTROL, 2200],
            ['Travel & Accommodation', Account::OPERATING_EXPENSE, 5300],
            ['Other Expenses', Account::OTHER_EXPENSE, 8900],
        ] as [$name, $type, $code]) {
            Account::create([
                'name' => $name,
                'account_type' => $type,
                'code' => $code,
                'currency_id' => $currency->id,
                'entity_id' => $entity->id,
            ]);
        }

        Vat::create([
            'name' => 'GST 10%',
            'code' => 'G',
            'rate' => 10,
            'account_id' => Account::where('code', 2200)->value('id'),
            'entity_id' => $entity->id,
        ]);
    }

    protected function admin(): User
    {
        Role::firstOrCreate(['name' => 'admin']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        return $admin;
    }

    protected function employee(): Employee
    {
        return Employee::create([
            'entity_id' => Entity::first()->id,
            'name' => 'Jane Smith',
            'employment_type' => 'employee',
            'payment_basis' => 'salary',
            'status' => Employee::STATUS_ACTIVE,
        ]);
    }

    /**
     * A completed, allocated and posted bank-transfer payment for a
     * $110 (GST-inclusive) bill.
     */
    protected function postedBankPayment(): BillPayment
    {
        $bill = $this->createOpenBill();
        $payment = BillPayment::createWithUniqueNumber([
            'supplier_id' => $this->supplier->id,
            'amount' => 110,
            'payment_date' => now()->toDateString(),
            'payment_method' => BillPayment::METHOD_BANK_TRANSFER,
        ]);
        $payment->allocateToBill($bill, 110);
        $this->assertNotNull($payment->postToIFRS());

        return $payment;
    }

    public function test_admin_can_relabel_method_on_a_posted_payment_without_touching_the_ledger(): void
    {
        $this->seedIfrs();
        $payment = $this->postedBankPayment();
        $txnId = $payment->ifrs_payment_id;
        $txnCount = Transaction::count();

        $this->actingAs($this->admin())
            ->put("/bill-payments/{$payment->id}", [
                'supplier_id' => $this->supplier->id,
                'amount' => 110,
                'payment_date' => now()->toDateString(),
                'payment_method' => BillPayment::METHOD_CASH,
                'reference' => null,
                'notes' => null,
            ])
            ->assertRedirect(route('bill-payments.show', $payment))
            ->assertSessionHas('success');

        $fresh = $payment->fresh();
        $this->assertSame(BillPayment::METHOD_CASH, $fresh->payment_method);
        // Bank-side relabel: same posting, no new ledger entries.
        $this->assertEquals($txnId, $fresh->ifrs_payment_id);
        $this->assertSame($txnCount, Transaction::count());
    }

    public function test_non_admin_cannot_change_method_on_a_posted_payment(): void
    {
        $this->seedIfrs();
        $payment = $this->postedBankPayment();
        $txnId = $payment->ifrs_payment_id;

        $this->actingAs($this->user)
            ->put("/bill-payments/{$payment->id}", [
                'supplier_id' => $this->supplier->id,
                'amount' => 110,
                'payment_date' => now()->toDateString(),
                'payment_method' => BillPayment::METHOD_CASH,
                'reference' => null,
                'notes' => null,
            ])
            ->assertSessionHas('error');

        // The rejection must be visible on the edit screen (the flash
        // banner), not silent.
        $this->get("/bill-payments/{$payment->id}/edit")
            ->assertOk()
            ->assertSee('Payment method and employee cannot change');

        $fresh = $payment->fresh();
        $this->assertSame(BillPayment::METHOD_BANK_TRANSFER, $fresh->payment_method);
        $this->assertEquals($txnId, $fresh->ifrs_payment_id);
    }

    public function test_input_gst_applies_once_per_line(): void
    {
        // $8.08 GST-inclusive has a 4+-decimal tax component (0.73454…):
        // the exact shape that duplicated the applied vat — and its
        // ledger legs — when the line item was saved twice.
        $this->seedIfrs();
        $bill = $this->createOpenBill(8.08);
        $payment = BillPayment::createWithUniqueNumber([
            'supplier_id' => $this->supplier->id,
            'amount' => 8.08,
            'payment_date' => now()->toDateString(),
            'payment_method' => BillPayment::METHOD_BANK_TRANSFER,
        ]);
        $payment->allocateToBill($bill, 8.08);
        $this->assertNotNull($payment->postToIFRS());

        $line = LineItem::where('transaction_id', $payment->ifrs_payment_id)->first();
        $this->assertSame(1, DB::table('ifrs_applied_vats')
            ->where('line_item_id', $line->id)
            ->count());

        $vatAccountId = Vat::where('code', 'G')->value('account_id');
        $this->assertSame(1, DB::table('ifrs_ledgers')
            ->where('transaction_id', $payment->ifrs_payment_id)
            ->where('post_account', $vatAccountId)
            ->count());
    }

    public function test_admin_switching_a_posted_payment_to_employee_method_reverses_and_reposts(): void
    {
        $this->seedIfrs();
        $employee = $this->employee();
        $payment = $this->postedBankPayment();
        $oldTxnId = $payment->ifrs_payment_id;

        $this->actingAs($this->admin())
            ->put("/bill-payments/{$payment->id}", [
                'supplier_id' => $this->supplier->id,
                'amount' => 110,
                'payment_date' => now()->toDateString(),
                'payment_method' => BillPayment::METHOD_EMPLOYEE_REIMBURSEMENT,
                'employee_id' => $employee->id,
                'reference' => null,
                'notes' => null,
            ])
            ->assertRedirect(route('bill-payments.show', $payment))
            ->assertSessionHas('success');

        $fresh = $payment->fresh();
        $this->assertSame(BillPayment::METHOD_EMPLOYEE_REIMBURSEMENT, $fresh->payment_method);
        $this->assertEquals($employee->id, $fresh->employee_id);
        $this->assertNotNull($fresh->ifrs_payment_id);
        $this->assertNotEquals($oldTxnId, $fresh->ifrs_payment_id);

        // The original posting, its mirrored reversal and the corrected
        // posting all carry the payment number as reference.
        $txnIds = Transaction::where('reference', $payment->payment_number)->pluck('id');
        $this->assertCount(3, $txnIds);

        // The bank legs net to zero: the original Cr Bank is mirrored
        // back by the reversal, and the corrected posting never touches
        // the bank — it credits Employee Reimbursements Payable instead.
        $bankId = Account::where('code', 320)->value('id');
        $bankNet = DB::table('ifrs_ledgers')
            ->whereIn('transaction_id', $txnIds)
            ->where('post_account', $bankId)
            ->selectRaw('SUM(CASE WHEN entry_type = "D" THEN amount ELSE -amount END) as net')
            ->value('net');
        $this->assertEquals(0.0, round((float) $bankNet, 2));

        $payableId = Account::where('code', 2280)->value('id');
        $this->assertNotNull($payableId);
        $this->assertDatabaseHas('ifrs_ledgers', [
            'transaction_id' => $fresh->ifrs_payment_id,
            'post_account' => $payableId,
            'entry_type' => 'C',
        ]);
    }

    public function test_admin_cannot_switch_employee_method_when_already_reimbursed(): void
    {
        $this->seedIfrs();
        $employee = $this->employee();
        $bill = $this->createOpenBill();

        $capture = BillPayment::createWithUniqueNumber([
            'supplier_id' => $this->supplier->id,
            'employee_id' => $employee->id,
            'amount' => 110,
            'payment_date' => now()->toDateString(),
            'payment_method' => BillPayment::METHOD_EMPLOYEE_REIMBURSEMENT,
        ]);
        $capture->allocateToBill($bill, 110);
        $this->assertNotNull($capture->postToIFRS());

        $reimbursement = ReimbursementPayment::create([
            'employee_id' => $employee->id,
            'amount' => 110,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'bank_transfer',
            'status' => ReimbursementPayment::STATUS_COMPLETED,
        ]);
        $this->assertNotNull($reimbursement->postToIFRS());

        $this->actingAs($this->admin())
            ->put("/bill-payments/{$capture->id}", [
                'supplier_id' => $this->supplier->id,
                'amount' => 110,
                'payment_date' => now()->toDateString(),
                'payment_method' => BillPayment::METHOD_BANK_TRANSFER,
                'reference' => null,
                'notes' => null,
            ])
            ->assertSessionHas('error');

        $fresh = $capture->fresh();
        $this->assertSame(BillPayment::METHOD_EMPLOYEE_REIMBURSEMENT, $fresh->payment_method);
        $this->assertNotNull($fresh->ifrs_payment_id);
    }

    public function test_document_can_be_uploaded_to_payment_from_edit_page(): void
    {
        $payment = BillPayment::createWithUniqueNumber([
            'supplier_id' => $this->supplier->id,
            'amount' => 110,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'bank_transfer',
        ]);

        // The show page links to the edit page for uploads, so the
        // upload widget must render there.
        $this->actingAs($this->user)
            ->get(route('bill-payments.edit', $payment))
            ->assertOk()
            ->assertSee('documentUploadArea')
            ->assertSee('name="documentable_type" value="BillPayment"', false);

        Storage::fake('public');
        $this->actingAs($this->user)
            ->post(route('documents.store'), [
                'documentable_type' => 'BillPayment',
                'documentable_id' => $payment->id,
                'file' => UploadedFile::fake()->create('receipt.pdf', 100, 'application/pdf'),
            ])
            ->assertStatus(201);

        $this->assertEquals(1, $payment->documents()->count());
        Storage::disk('public')->assertExists($payment->documents()->first()->file_path);
    }
}

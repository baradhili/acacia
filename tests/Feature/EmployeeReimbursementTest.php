<?php

namespace Tests\Feature;

use App\Models\Bill;
use App\Models\BillPayment;
use App\Models\ReimbursementPayment;
use App\Models\Supplier;
use App\Models\User;
use IFRS\Models\Account;
use IFRS\Models\Balance;
use IFRS\Models\Currency;
use IFRS\Models\Entity;
use IFRS\Models\Ledger;
use IFRS\Models\ReportingPeriod;
use IFRS\Models\Vat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Payroll\Models\Employee;
use Modules\Reconciliation\Models\BankTransaction;
use Modules\Reconciliation\Services\ReconciliationService;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Employee-paid expenses end to end: a supplier payment with method
 * employee_reimbursement sits pending (nothing posts, its bill stays
 * unpaid), approval posts Dr Expense / Dr GST / Cr Employee
 * Reimbursements Payable (2280 — never the bank), a ReimbursementPayment
 * clears the liability (Dr 2280 / Cr Bank) and reconciles against the
 * bank feed, and already-reimbursed captures cannot be voided.
 */
class EmployeeReimbursementTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Entity $entity;

    protected Employee $employee;

    protected Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedIfrs();

        Role::firstOrCreate(['name' => 'admin']);

        $this->user = User::factory()->create(['entity_id' => $this->entity->id]);
        $this->user->assignRole('admin');

        $this->supplier = Supplier::create(['name' => 'Officeworks', 'email' => 'accounts@officeworks.test']);
        $this->employee = Employee::create([
            'entity_id' => $this->entity->id,
            'name' => 'Jane Smith',
            'employment_type' => 'employee',
            'payment_basis' => 'salary',
            'status' => Employee::STATUS_ACTIVE,
        ]);
    }

    /**
     * Minimum IFRS chart for capture/approval/reimbursement postings:
     * entity + period, bank (320), GST (2200 CONTROL with the GST 10%
     * Vat), and one expense account (5300). 2280 is created lazily by
     * BillPayment::ensureReimbursementAccount() exactly as it would be
     * on an existing install.
     */
    protected function seedIfrs(): Entity
    {
        $this->entity = Entity::create([
            'name' => 'Test Entity',
            'locale' => 'en_AU',
            'multi_currency' => false,
            'year_start' => 1,
        ]);

        $currency = Currency::create([
            'name' => 'Australian Dollar',
            'currency_code' => 'AUD',
            'entity_id' => $this->entity->id,
        ]);
        $this->entity->update(['currency_id' => $currency->id]);
        $this->entity->refresh();

        ReportingPeriod::create([
            'period_count' => 1,
            'calendar_year' => (int) date('Y'),
            'status' => ReportingPeriod::OPEN,
            'entity_id' => $this->entity->id,
        ]);

        foreach ([
            ['Operating Account', Account::BANK, 320],
            ['GST Payable', Account::CONTROL, 2200],
            ['Travel & Accommodation', Account::OPERATING_EXPENSE, 5300],
            ['Other Expenses', Account::OTHER_EXPENSE, 8900],
        ] as [$name, $type, $code]) {
            Account::create([
                'name' => $name,
                'account_type' => $type,
                'code' => $code,
                'currency_id' => $currency->id,
                'entity_id' => $this->entity->id,
            ]);
        }

        Vat::create([
            'name' => 'GST 10%',
            'code' => 'G',
            'rate' => 10,
            'account_id' => Account::where('code', 2200)->first()->id,
            'entity_id' => $this->entity->id,
        ]);

        return $this->entity;
    }

    /** Open bill totalling $110 (GST-inclusive $100 + $10 GST). */
    protected function createOpenBill(): Bill
    {
        $bill = Bill::create(['supplier_id' => $this->supplier->id]);
        $bill->items()->create([
            'description' => 'Stationery',
            'quantity' => 1,
            'unit_price' => 110,
            'tax_rate' => 10, // unit_price is GST-inclusive
            'expense_account_id' => Account::where('code', 5300)->first()->id,
        ]);
        $bill->recalculateTotals();
        $bill->markAsOpen();

        return $bill;
    }

    /** Capture an employee-paid payment of $110 against the given bill. */
    protected function captureEmployeePayment(Bill $bill): BillPayment
    {
        $this->actingAs($this->user)->post('/bill-payments', [
            'supplier_id' => $this->supplier->id,
            'amount' => 110,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'employee_reimbursement',
            'employee_id' => $this->employee->id,
            'allocate_type' => 'manual',
            'bill_allocations' => [
                ['bill_id' => $bill->id, 'amount' => 110],
            ],
        ])->assertSessionHas('success');

        return BillPayment::first();
    }

    protected function debitSum(int $code): float
    {
        return (float) Ledger::where('post_account', Account::where('code', $code)->value('id'))
            ->where('entry_type', Balance::DEBIT)
            ->sum('amount');
    }

    protected function creditSum(int $code): float
    {
        return (float) Ledger::where('post_account', Account::where('code', $code)->value('id'))
            ->where('entry_type', Balance::CREDIT)
            ->sum('amount');
    }

    protected function netSum(int $code): float
    {
        return round($this->debitSum($code) - $this->creditSum($code), 2);
    }

    public function test_capture_is_pending_and_posts_nothing(): void
    {
        $bill = $this->createOpenBill();

        $payment = $this->captureEmployeePayment($bill);

        $this->assertEquals(BillPayment::STATUS_PENDING, $payment->status);
        $this->assertNull($payment->ifrs_payment_id);
        $this->assertEquals($this->employee->id, $payment->employee_id);

        // The allocation is on record but does not pay the bill until
        // approved, and nothing reached the ledger.
        $bill->refresh();
        $this->assertEquals(Bill::STATUS_OPEN, $bill->status);
        $this->assertEquals(110, (float) $bill->amount_due);
        $this->assertEquals(0, $this->debitSum(2280) + $this->creditSum(2280));
        $this->assertEquals(0, $this->debitSum(320) + $this->creditSum(320));
    }

    public function test_backfill_skips_pending_capture(): void
    {
        $bill = $this->createOpenBill();
        $payment = $this->captureEmployeePayment($bill);

        $this->artisan('ifrs:post-payments')->assertSuccessful();

        $this->assertNull($payment->refresh()->ifrs_payment_id);
    }

    public function test_approval_posts_expense_gst_and_payable_not_bank(): void
    {
        $bill = $this->createOpenBill();
        $payment = $this->captureEmployeePayment($bill);

        $this->actingAs($this->user)
            ->post(route('bill-payments.approve', $payment))
            ->assertSessionHas('success');

        $payment->refresh();
        $bill->refresh();
        $this->assertEquals(BillPayment::STATUS_COMPLETED, $payment->status);
        $this->assertNotNull($payment->ifrs_payment_id);
        $this->assertEquals(Bill::STATUS_PAID, $bill->status);

        // The employee financed it: Cr 2280 (not the bank), Dr the bill's
        // expense account net of GST (the package writes 110 Dr then a 10
        // contra Cr to back the GST out), Dr GST for the input credit.
        $this->assertEquals(110, $this->creditSum(2280));
        $this->assertEquals(0, $this->debitSum(2280));
        $this->assertEquals(100, $this->netSum(5300));
        $this->assertEquals(10, $this->netSum(2200));
        $this->assertEquals(0, $this->netSum(320));
        $this->assertEquals(110, ReimbursementPayment::outstandingFor($this->employee->id));
    }

    public function test_approval_requires_pending_employee_payment(): void
    {
        $bill = $this->createOpenBill();
        $payment = BillPayment::create([
            'supplier_id' => $this->supplier->id,
            'paid_by' => $this->user->id,
            'amount' => 110,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'bank_transfer',
        ]);
        $payment->allocateToBill($bill, 110);

        $this->actingAs($this->user)
            ->post(route('bill-payments.approve', $payment))
            ->assertSessionHas('error');

        $this->assertEquals(BillPayment::STATUS_COMPLETED, $payment->refresh()->status);
    }

    public function test_method_and_employee_locked_after_posting(): void
    {
        $bill = $this->createOpenBill();
        $payment = $this->captureEmployeePayment($bill);
        $this->actingAs($this->user)->post(route('bill-payments.approve', $payment));
        $postedTxnId = $payment->refresh()->ifrs_payment_id;

        // Regular users keep the lock: method and employee cannot change
        // on a posted payment.
        $plainUser = User::factory()->create(['entity_id' => $this->entity->id]);

        $this->actingAs($plainUser)
            ->put(route('bill-payments.update', $payment), [
                'supplier_id' => $this->supplier->id,
                'amount' => 110,
                'payment_date' => now()->toDateString(),
                'payment_method' => 'bank_transfer',
                'reference' => null,
                'notes' => null,
            ])
            ->assertSessionHas('error');

        $this->assertEquals('employee_reimbursement', $payment->refresh()->payment_method);

        // Admins may correct the method: the posted entry is unwound
        // (mirrored reversal) and re-posted on the bank leg — the
        // employee stops being owed the money.
        $this->actingAs($this->user)
            ->put(route('bill-payments.update', $payment), [
                'supplier_id' => $this->supplier->id,
                'amount' => 110,
                'payment_date' => now()->toDateString(),
                'payment_method' => 'bank_transfer',
                'reference' => null,
                'notes' => null,
            ])
            ->assertSessionHas('success');

        $fresh = $payment->refresh();
        $this->assertEquals('bank_transfer', $fresh->payment_method);
        $this->assertNull($fresh->employee_id);
        $this->assertNotEquals($postedTxnId, $fresh->ifrs_payment_id);
        $this->assertEquals(0, ReimbursementPayment::outstandingFor($this->employee->id));
    }

    public function test_pending_capture_cannot_switch_method(): void
    {
        $bill = $this->createOpenBill();
        $payment = $this->captureEmployeePayment($bill);

        $this->actingAs($this->user)
            ->put(route('bill-payments.update', $payment), [
                'supplier_id' => $this->supplier->id,
                'amount' => 110,
                'payment_date' => now()->toDateString(),
                'payment_method' => 'bank_transfer',
                'reference' => null,
                'notes' => null,
            ])
            ->assertSessionHas('error');

        $this->assertEquals('employee_reimbursement', $payment->refresh()->payment_method);
    }

    public function test_reimbursement_payment_clears_the_payable(): void
    {
        $bill = $this->createOpenBill();
        $payment = $this->captureEmployeePayment($bill);
        $this->actingAs($this->user)->post(route('bill-payments.approve', $payment));

        $this->actingAs($this->user)->post('/reimbursement-payments', [
            'employee_id' => $this->employee->id,
            'amount' => 110,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'bank_transfer',
            'reference' => 'TRF-001',
            'notes' => null,
        ])->assertSessionHas('success');

        $reimbursement = ReimbursementPayment::first();
        $this->assertEquals(ReimbursementPayment::STATUS_COMPLETED, $reimbursement->status);
        $this->assertNotNull($reimbursement->ifrs_transaction_id);

        // Dr 2280 / Cr Bank; the liability nets to zero for this flow.
        $this->assertEquals(110, $this->debitSum(2280));
        $this->assertEquals(110, $this->creditSum(320));
        $this->assertEquals(0, ReimbursementPayment::outstandingFor($this->employee->id));
    }

    public function test_reimbursement_cannot_exceed_what_is_owed(): void
    {
        $bill = $this->createOpenBill();
        $payment = $this->captureEmployeePayment($bill);
        $this->actingAs($this->user)->post(route('bill-payments.approve', $payment));

        $this->actingAs($this->user)->post('/reimbursement-payments', [
            'employee_id' => $this->employee->id,
            'amount' => 200,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'bank_transfer',
        ])->assertSessionHasErrors('amount');

        $this->assertEquals(0, ReimbursementPayment::count());
    }

    public function test_reimbursed_capture_cannot_be_voided(): void
    {
        $bill = $this->createOpenBill();
        $payment = $this->captureEmployeePayment($bill);
        $this->actingAs($this->user)->post(route('bill-payments.approve', $payment));

        $this->actingAs($this->user)->post('/reimbursement-payments', [
            'employee_id' => $this->employee->id,
            'amount' => 110,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'bank_transfer',
        ])->assertSessionHas('success');

        $this->actingAs($this->user)
            ->post(route('bill-payments.void', $payment))
            ->assertSessionHas('error');

        $this->assertEquals(BillPayment::STATUS_COMPLETED, $payment->refresh()->status);
    }

    public function test_unreimbursed_capture_voids_cleanly(): void
    {
        $bill = $this->createOpenBill();
        $payment = $this->captureEmployeePayment($bill);

        $this->actingAs($this->user)
            ->post(route('bill-payments.void', $payment))
            ->assertSessionHas('success');

        $payment->refresh();
        $bill->refresh();
        $this->assertEquals(BillPayment::STATUS_VOID, $payment->status);
        $this->assertEquals(Bill::STATUS_OPEN, $bill->status);
        $this->assertEquals(0, ReimbursementPayment::outstandingFor($this->employee->id));
    }

    public function test_pending_capture_requires_an_employee(): void
    {
        $bill = $this->createOpenBill();

        $this->actingAs($this->user)->post('/bill-payments', [
            'supplier_id' => $this->supplier->id,
            'amount' => 110,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'employee_reimbursement',
            'allocate_type' => 'manual',
            'bill_allocations' => [
                ['bill_id' => $bill->id, 'amount' => 110],
            ],
        ])->assertSessionHasErrors('employee_id');
    }

    public function test_pending_capture_reserves_the_bill_balance(): void
    {
        $bill = $this->createOpenBill();
        $this->captureEmployeePayment($bill); // 110 pending

        // Not editable: approval would post against edited items.
        $this->assertFalse($bill->refresh()->canBeEdited());

        // No room for a second payment — even though amount_due (which
        // only counts completed payments) still shows 110.
        $this->assertEquals(110, (float) $bill->amount_due);
        $this->assertEquals(110, (float) $bill->committed_amount);

        $this->actingAs($this->user)
            ->post(route('bills.recordPayment', $bill), [
                'amount' => 110,
                'payment_date' => now()->toDateString(),
                'payment_method' => 'bank_transfer',
            ])
            ->assertSessionHas('error');

        $this->assertEquals(1, BillPayment::count());
    }

    public function test_inactive_employee_cannot_be_picked(): void
    {
        $bill = $this->createOpenBill();
        $this->employee->update(['status' => Employee::STATUS_INACTIVE]);

        $this->actingAs($this->user)->post('/bill-payments', [
            'supplier_id' => $this->supplier->id,
            'amount' => 110,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'employee_reimbursement',
            'employee_id' => $this->employee->id,
            'allocate_type' => 'manual',
            'bill_allocations' => [
                ['bill_id' => $bill->id, 'amount' => 110],
            ],
        ])->assertSessionHasErrors('employee_id');
    }

    public function test_pending_capture_does_not_mask_overdue(): void
    {
        $bill = Bill::create([
            'supplier_id' => $this->supplier->id,
            'bill_date' => now()->subMonths(2)->toDateString(),
            'due_date' => now()->subMonth()->toDateString(),
        ]);
        $bill->items()->create([
            'description' => 'Stationery',
            'quantity' => 1,
            'unit_price' => 110,
            'tax_rate' => 10,
            'expense_account_id' => Account::where('code', 5300)->first()->id,
        ]);
        $bill->recalculateTotals();
        $bill->markAsOpen();

        $this->captureEmployeePayment($bill); // 110 pending — nothing paid yet

        // Past due and unpaid: still overdue, nudging the approval along.
        $this->assertTrue(Bill::overdue()->where('bills.id', $bill->id)->exists());
    }

    public function test_reconciliation_offers_and_matches_reimbursements(): void
    {
        $bill = $this->createOpenBill();
        $payment = $this->captureEmployeePayment($bill);
        $this->actingAs($this->user)->post(route('bill-payments.approve', $payment));

        $this->actingAs($this->user)->post('/reimbursement-payments', [
            'employee_id' => $this->employee->id,
            'amount' => 110,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'bank_transfer',
        ])->assertSessionHas('success');

        $service = app(ReconciliationService::class);
        $bankLine = BankTransaction::create([
            'source' => BankTransaction::SOURCE_WISE,
            'source_id' => 'WISE-R1',
            'reference' => 'REF-R1',
            'description' => 'Transfer to Jane Smith',
            'amount' => -110,
            'currency' => 'AUD',
            'type' => BankTransaction::TYPE_DEBIT,
            'payee_name' => 'Jane Smith',
            'transaction_date' => now(),
            'status' => BankTransaction::STATUS_PENDING,
        ]);

        // Debit lines surface both supplier payments and reimbursements.
        $candidates = $service->getAvailableTransactionsForLinking($bankLine);
        $this->assertTrue($candidates->contains(
            fn ($candidate) => $candidate['type'] === 'reimbursement_payment'
                && $candidate['employee'] === 'Jane Smith'
        ));

        // Manual match links and learns the employee counterparty.
        $this->assertTrue($service->manualOverrideLink(
            $bankLine,
            'reimbursement_payment',
            ReimbursementPayment::first()->id
        ));
        $this->assertEquals(BankTransaction::STATUS_MATCHED, $bankLine->refresh()->status);

        // A later identical transfer to the same employee auto-matches
        // its fresh reimbursement via the learned rule.
        $this->travel(1)->days();
        $bill2 = $this->createOpenBill();
        $payment2 = BillPayment::createWithUniqueNumber([
            'supplier_id' => $this->supplier->id,
            'paid_by' => $this->user->id,
            'employee_id' => $this->employee->id,
            'amount' => 110,
            'payment_date' => now()->toDateString(),
            'payment_method' => BillPayment::METHOD_EMPLOYEE_REIMBURSEMENT,
            'status' => BillPayment::STATUS_COMPLETED,
        ]);
        $payment2->allocateToBill($bill2, 110);
        $payment2->postToIFRS();
        $this->actingAs($this->user)->post('/reimbursement-payments', [
            'employee_id' => $this->employee->id,
            'amount' => 110,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'bank_transfer',
        ])->assertSessionHas('success');

        $bankLine2 = BankTransaction::create([
            'source' => BankTransaction::SOURCE_WISE,
            'source_id' => 'WISE-R2',
            'reference' => 'REF-R2',
            'description' => 'Transfer to Jane Smith',
            'amount' => -110,
            'currency' => 'AUD',
            'type' => BankTransaction::TYPE_DEBIT,
            'payee_name' => 'Jane Smith',
            'transaction_date' => now(),
            'status' => BankTransaction::STATUS_PENDING,
        ]);

        $service->autoMatchAll();

        $this->assertEquals(BankTransaction::STATUS_MATCHED, $bankLine2->refresh()->status);
        $this->assertEquals(
            ReimbursementPayment::orderByDesc('id')->first()->id,
            $bankLine2->matched_transaction_id
        );
    }
}

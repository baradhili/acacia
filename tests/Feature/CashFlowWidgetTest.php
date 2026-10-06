<?php

namespace Tests\Feature;

use App\Models\Bill;
use App\Models\BillPayment;
use App\Models\ReimbursementPayment;
use App\Models\Supplier;
use App\Models\User;
use App\Services\IfrsPosting;
use App\Widgets\CashFlowWidget;
use Carbon\Carbon;
use Database\Seeders\IFRSSeeder;
use IFRS\Models\Account;
use IFRS\Models\Entity;
use IFRS\Models\LineItem;
use IFRS\Transactions\JournalEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Payroll\Models\Employee;
use Modules\Payroll\Services\PayrollService;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The cash flow widget reads the bank accounts' own ledger legs —
 * every posted movement that actually hit the bank, whatever posted
 * it. The subledger numbers survive unchanged (supplier payments,
 * reimbursements, payroll net — never an employee capture, whose
 * company cash leaves at reimbursement), and the journal-settled
 * movements the subledgers could never see now count too: BAS and
 * payroll settlements, funds introduced or withdrawn. Internal
 * transfers between the books' own bank accounts move no total cash
 * and stay out.
 */
class CashFlowWidgetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Pin the window: today 2026-10-01 09:00 → current period
        // covers 2026-09-01 09:00 onward, the prior period is empty.
        $this->travelTo(Carbon::parse('2026-10-01 09:00'));

        foreach (['admin', 'accountant', 'staff'] as $role) {
            Role::firstOrCreate(['name' => $role]);
        }

        $this->seed(IFRSSeeder::class);
    }

    protected function tearDown(): void
    {
        $this->travelBack();

        parent::tearDown();
    }

    /** @return array<string, mixed> the widget view's data payload */
    protected function widgetData(): array
    {
        return app(CashFlowWidget::class)->run()->getData();
    }

    public function test_outflows_count_reimbursements_and_payroll_net_but_not_captures(): void
    {
        $supplier = Supplier::factory()->create();
        $user = User::factory()->create();
        $payee = Employee::create([
            'entity_id' => IfrsPosting::resolveEntity()->id,
            'name' => 'Jane Worker',
            'tfn' => '123456782',
            'employment_type' => Employee::TYPE_EMPLOYEE,
            'payment_basis' => Employee::BASIS_HOURLY,
            'hourly_rate' => 50,
            'tax_free_threshold' => true,
        ]);

        // A paid bill the payments allocate against (posting requires
        // allocations — the completing flow always has them).
        $bill = Bill::createWithUniqueNumber(['supplier_id' => $supplier->id]);
        $bill->items()->create(['description' => 'Hosting', 'quantity' => 1, 'unit_price' => 600, 'tax_rate' => 0]);
        $bill->recalculateTotals();
        $bill->markAsOpen();

        // Supplier payment on a bank method: counts. The completing
        // flow posts payments to the ledger in production — the widget
        // reads the bank legs, so the fixtures post too.
        $supplierPayment = BillPayment::createWithUniqueNumber([
            'supplier_id' => $supplier->id,
            'paid_by' => $user->id,
            'amount' => 500.00,
            'payment_date' => '2026-09-15',
            'payment_method' => BillPayment::METHOD_BANK_TRANSFER,
            'status' => BillPayment::STATUS_COMPLETED,
        ]);
        $supplierPayment->allocateToBill($bill, 500);
        $supplierPayment->postToIFRS();

        // A payment on the window's lower boundary day: the boundary
        // is a whole calendar day and belongs to the current period
        // (a time-carrying boundary would drop it — payment dates
        // have no time and lose the 09:00 comparison).
        $boundaryPayment = BillPayment::createWithUniqueNumber([
            'supplier_id' => $supplier->id,
            'paid_by' => $user->id,
            'amount' => 75.00,
            'payment_date' => '2026-09-01',
            'payment_method' => BillPayment::METHOD_BANK_TRANSFER,
            'status' => BillPayment::STATUS_COMPLETED,
        ]);
        $boundaryPayment->allocateToBill($bill, 75);
        $boundaryPayment->postToIFRS();

        // Employee capture: credits the payable, never the bank —
        // excluded, or the same expense would count again at its
        // reimbursement.
        $captureBill = Bill::createWithUniqueNumber(['supplier_id' => $supplier->id]);
        $captureBill->items()->create(['description' => 'Software', 'quantity' => 1, 'unit_price' => 100, 'tax_rate' => 0]);
        $captureBill->recalculateTotals();
        $captureBill->markAsOpen();
        $capture = BillPayment::createWithUniqueNumber([
            'supplier_id' => $supplier->id,
            'paid_by' => $user->id,
            'employee_id' => $payee->id,
            'amount' => 100.00,
            'payment_date' => '2026-09-16',
            'payment_method' => BillPayment::METHOD_EMPLOYEE_REIMBURSEMENT,
            'status' => BillPayment::STATUS_COMPLETED,
        ]);
        $capture->allocateToBill($captureBill, 100);
        $capture->postToIFRS();

        // The reimbursement that pays it back: counts.
        tap(ReimbursementPayment::createWithUniqueNumber([
            'employee_id' => $payee->id,
            'paid_by' => $user->id,
            'amount' => 94.43,
            'payment_date' => '2026-09-17',
            'payment_method' => BillPayment::METHOD_BANK_TRANSFER,
            'status' => ReimbursementPayment::STATUS_COMPLETED,
        ]))->postToIFRS();

        // A processed pay run inside the window: its NET counts
        // (76h × $50 = $3,800 gross, PAYG $854 → net $2,946)…
        $payroll = app(PayrollService::class);
        $run = $payroll->createRun([
            'frequency' => 'fortnightly',
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-14',
            'payment_date' => '2026-09-15',
        ]);
        $payroll->addPayslip($run, $payee, 76);
        $payroll->process($run);

        // …a processed run outside the window does not…
        $old = $payroll->createRun([
            'frequency' => 'fortnightly',
            'period_start' => '2026-06-15',
            'period_end' => '2026-06-28',
            'payment_date' => '2026-07-15',
        ]);
        $payroll->addPayslip($old, $payee, 76);
        $payroll->process($old);

        // …and a pending reimbursement does not either.
        ReimbursementPayment::createWithUniqueNumber([
            'employee_id' => $payee->id,
            'paid_by' => $user->id,
            'amount' => 50.00,
            'payment_date' => '2026-09-18',
            'payment_method' => BillPayment::METHOD_BANK_TRANSFER,
            'status' => ReimbursementPayment::STATUS_PENDING,
        ]);

        $data = $this->widgetData();

        $this->assertEquals(0.0, (float) $data['inflows']);
        $this->assertEquals(3615.43, (float) $data['outflows']);
        $this->assertEquals(-3615.43, (float) $data['net_flow']);
        $this->assertSame(0.0, $data['change_percent']); // empty prior period
    }

    public function test_journal_settled_movements_and_transfers_flow_correctly(): void
    {
        $entity = IfrsPosting::resolveEntity();
        $bank = Account::where('entity_id', $entity->id)->where('account_type', Account::BANK)->where('code', 320)->firstOrFail();
        $savings = Account::create([
            'name' => 'Savings Account',
            'account_type' => Account::BANK,
            'code' => 321,
            'currency_id' => $entity->currency_id,
            'entity_id' => $entity->id,
        ]);
        $fundsIntroduced = Account::create([
            'name' => 'Funds Introduced',
            'account_type' => Account::EQUITY,
            'code' => 3500,
            'currency_id' => $entity->currency_id,
            'entity_id' => $entity->id,
        ]);
        $gstPayable = Account::where('entity_id', $entity->id)->where('code', 2200)->firstOrFail();
        $superPayable = Account::where('entity_id', $entity->id)->where('code', 2220)->firstOrFail();

        // A client receipt hitting the bank: cash in.
        $this->postJournal($entity, $bank, false, [[$this->account($entity, 4100), 1500]], 'TEST-RECEIPT-1', '2026-09-20');

        // The BAS GST settlement — real money to the ATO the subledgers
        // never saw.
        $this->postJournal($entity, $gstPayable, false, [[$bank, 3606]], 'BAS-SETT-GST-20260930', '2026-09-25');

        // The super settlement from the match screen.
        $this->postJournal($entity, $superPayable, false, [[$bank, 456]], 'PAYSET-77', '2026-09-25');

        // An abandoned settlement round reversed back: nets to nothing.
        $this->postJournal($entity, $gstPayable, false, [[$bank, 100]], 'BAS-SETT-GST-20261005-OLD', '2026-09-26');
        $this->postJournal($entity, $bank, false, [[$gstPayable, 100]], 'BAS-SETT-GST-20261005-OLD-REV', '2026-09-26');

        // An internal transfer between the books' own banks: no total
        // cash movement, excluded from both sides.
        $this->postJournal($entity, $bank, false, [[$savings, 2000]], 'XFER-500', '2026-09-27');

        // Funds introduced from an external account: real cash in.
        $this->postJournal($entity, $bank, false, [[$fundsIntroduced, 1000]], 'XFER-501', '2026-09-27');

        $data = $this->widgetData();

        // In: receipt 1,500 + funds introduced 1,000. Out: BAS 3,606 +
        // super 456. The abandoned round and the internal transfer
        // contribute nothing.
        $this->assertEquals(2500.0, (float) $data['inflows']);
        $this->assertEquals(4062.0, (float) $data['outflows']);
        $this->assertEquals(-1562.0, (float) $data['net_flow']);
    }

    protected function account(Entity $entity, int $code): Account
    {
        return Account::where('entity_id', $entity->id)->where('code', $code)->firstOrFail();
    }

    /** The standard posting shape: main account one side, legs the other. */
    protected function postJournal(Entity $entity, Account $main, bool $credited, array $legs, string $reference, string $date): void
    {
        IfrsPosting::ensureReportingPeriod(Carbon::parse($date), $entity);

        $journal = new JournalEntry([
            'transaction_date' => IfrsPosting::transactionDate(Carbon::parse($date), $entity),
            'account_id' => $main->id,
            'credited' => $credited,
            'entity_id' => $entity->id,
            'currency_id' => $entity->currency_id,
            'narration' => 'Cash flow fixture '.$reference,
            'reference' => $reference,
        ]);

        foreach ($legs as [$account, $amount]) {
            $line = LineItem::create([
                'account_id' => $account->id,
                'amount' => $amount,
                'quantity' => 1,
                'entity_id' => $entity->id,
            ]);
            $journal->addLineItem($line);
        }

        $journal->post();
    }
}

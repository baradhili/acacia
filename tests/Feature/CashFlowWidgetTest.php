<?php

namespace Tests\Feature;

use App\Models\BillPayment;
use App\Models\ReimbursementPayment;
use App\Models\Supplier;
use App\Models\User;
use App\Services\IfrsPosting;
use App\Widgets\CashFlowWidget;
use Carbon\Carbon;
use Database\Seeders\IFRSSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Payroll\Models\Employee;
use Modules\Payroll\Services\PayrollService;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The cash flow widget's outflows must be every event that moves the
 * bank: supplier payments on bank methods, completed employee
 * reimbursements, and processed pay runs' net — and never an
 * employee capture, whose company cash leaves at reimbursement
 * (counting both legs would double the expense). Withheld PAYG pays
 * the ATO, not staff, so payroll counts net rather than gross.
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

        // Supplier payment on a bank method: counts.
        BillPayment::createWithUniqueNumber([
            'supplier_id' => $supplier->id,
            'paid_by' => $user->id,
            'amount' => 500.00,
            'payment_date' => '2026-09-15',
            'payment_method' => BillPayment::METHOD_BANK_TRANSFER,
            'status' => BillPayment::STATUS_COMPLETED,
        ]);

        // A payment on the window's lower boundary day: the boundary
        // is a whole calendar day and belongs to the current period
        // (a time-carrying boundary would drop it — payment dates
        // have no time and lose the 09:00 comparison).
        BillPayment::createWithUniqueNumber([
            'supplier_id' => $supplier->id,
            'paid_by' => $user->id,
            'amount' => 75.00,
            'payment_date' => '2026-09-01',
            'payment_method' => BillPayment::METHOD_BANK_TRANSFER,
            'status' => BillPayment::STATUS_COMPLETED,
        ]);

        // Employee capture: credits the payable, never the bank —
        // excluded, or the same expense would count again at its
        // reimbursement.
        BillPayment::createWithUniqueNumber([
            'supplier_id' => $supplier->id,
            'paid_by' => $user->id,
            'employee_id' => $payee->id,
            'amount' => 100.00,
            'payment_date' => '2026-09-16',
            'payment_method' => BillPayment::METHOD_EMPLOYEE_REIMBURSEMENT,
            'status' => BillPayment::STATUS_COMPLETED,
        ]);

        // The reimbursement that pays it back: counts.
        ReimbursementPayment::createWithUniqueNumber([
            'employee_id' => $payee->id,
            'paid_by' => $user->id,
            'amount' => 94.43,
            'payment_date' => '2026-09-17',
            'payment_method' => BillPayment::METHOD_BANK_TRANSFER,
            'status' => ReimbursementPayment::STATUS_COMPLETED,
        ]);

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
}

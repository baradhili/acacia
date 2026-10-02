<?php

namespace App\Widgets;

use App\Models\BillPayment;
use App\Models\Payment;
use App\Models\ReimbursementPayment;
use Arrilot\Widgets\AbstractWidget;
use Carbon\Carbon;
use Modules\Payroll\Models\PayRun;

/**
 * Cash in and out over the trailing 30 days, read from completed
 * payment events (client payments in; supplier payments, employee
 * reimbursements and payroll net out) — the cash basis the app
 * reports on, not invoice accruals — with the net flow's percentage
 * change against the prior 30 days. A zero-net prior period reports
 * a 0% change rather than a divide-by-zero.
 *
 * Outflows are the events that actually move the bank. An
 * employee-reimbursement CAPTURE posts to the payable, not the bank
 * — the employee paid personally, and the company's cash leaves when
 * the reimbursement completes — so captures are excluded here to
 * keep the same expense from counting twice. Payroll counts each
 * processed run's net (gross less PAYG withheld: the withheld tax
 * pays the ATO, not staff), guarded on the Payroll module being
 * present per the cross-module convention.
 */
class CashFlowWidget extends AbstractWidget
{
    protected $config = [];

    public function run()
    {
        $today = Carbon::now();
        $thirtyDaysAgo = $today->copy()->subDays(30);
        $sixtyDaysAgo = $today->copy()->subDays(60);

        $inflows = Payment::where('status', Payment::STATUS_COMPLETED)
            ->where('payment_date', '>=', $thirtyDaysAgo)
            ->where('payment_date', '<=', $today)
            ->sum('amount');

        $previousInflows = Payment::where('status', Payment::STATUS_COMPLETED)
            ->where('payment_date', '>=', $sixtyDaysAgo)
            ->where('payment_date', '<', $thirtyDaysAgo)
            ->sum('amount');

        $outflows = $this->outflows($thirtyDaysAgo, $today, '<=');
        $previousOutflows = $this->outflows($sixtyDaysAgo, $thirtyDaysAgo, '<');

        $netCashFlow = $inflows - $outflows;
        $previousNet = $previousInflows - $previousOutflows;
        $change = $previousNet != 0 ? (($netCashFlow - $previousNet) / abs($previousNet)) * 100 : 0;

        return view('widgets.cash_flow', [
            'inflows' => $inflows,
            'outflows' => $outflows,
            'net_flow' => $netCashFlow,
            'change_percent' => round($change, 1),
            'inflows_formatted' => number_format($inflows, 2),
            'outflows_formatted' => number_format($outflows, 2),
            'net_flow_formatted' => number_format($netCashFlow, 2),
        ]);
    }

    /**
     * Bank outflows over a window: supplier payments (bank methods
     * only — employee captures are excluded, see the class docblock),
     * completed employee reimbursements, and processed pay runs' net.
     * $endOperator keeps the original window semantics — the current
     * period includes its end date, the prior period excludes the
     * shared boundary day.
     */
    protected function outflows(Carbon $start, Carbon $end, string $endOperator): float
    {
        $supplier = (float) BillPayment::where('status', BillPayment::STATUS_COMPLETED)
            ->where('payment_method', '!=', BillPayment::METHOD_EMPLOYEE_REIMBURSEMENT)
            ->where('payment_date', '>=', $start)
            ->where('payment_date', $endOperator, $end)
            ->sum('amount');

        $reimbursements = (float) ReimbursementPayment::where('status', ReimbursementPayment::STATUS_COMPLETED)
            ->where('payment_date', '>=', $start)
            ->where('payment_date', $endOperator, $end)
            ->sum('amount');

        $payroll = 0.0;
        if (class_exists(PayRun::class)) {
            $payroll = (float) PayRun::query()
                ->where('status', PayRun::STATUS_PROCESSED)
                ->where('payment_date', '>=', $start)
                ->where('payment_date', $endOperator, $end)
                ->withSum('payslips', 'net_pay')
                ->get()
                ->sum(fn (PayRun $run) => (float) ($run->payslips_sum_net_pay ?? 0));
        }

        return round($supplier + $reimbursements + $payroll, 2);
    }
}

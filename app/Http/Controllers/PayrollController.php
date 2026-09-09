<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\PayRun;
use App\Models\Payslip;
use App\Services\PayrollService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Pay runs: create a run over a pay period, build its payslips (gross
 * from hours × rate, salary apportionment, or an override; PAYG
 * withheld per the employee's ATO scale; super on ordinary earnings),
 * then process — which posts the accrual and payment journals — or
 * reverse a processed run. Admin/accountant only (route middleware).
 */
class PayrollController extends Controller
{
    public function __construct(protected PayrollService $payroll) {}

    public function index()
    {
        $runs = PayRun::with('payslips')
            ->orderByDesc('period_end')
            ->orderByDesc('id')
            ->get();

        return view('payroll.index', ['runs' => $runs]);
    }

    public function create()
    {
        return view('payroll.create', [
            'employees' => Employee::where('status', Employee::STATUS_ACTIVE)->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'frequency' => ['required', Rule::in(PayRun::FREQUENCIES)],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            'payment_date' => ['required', 'date'],
            'notes' => ['nullable', 'string'],
        ]);

        $run = $this->payroll->createRun($validated);

        return redirect()->route('payroll.runs.show', $run)
            ->with('success', 'Pay run created — review the payslips before processing.');
    }

    public function show(PayRun $run)
    {
        $run->load('payslips.employee');

        $employees = Employee::where('status', Employee::STATUS_ACTIVE)
            ->whereNotIn('id', $run->payslips->pluck('employee_id'))
            ->orderBy('name')
            ->get();

        return view('payroll.show', [
            'run' => $run,
            'totals' => [
                'gross' => (float) $run->payslips->sum('gross'),
                'payg' => (float) $run->payslips->sum('payg_withheld'),
                'super' => (float) $run->payslips->sum('super'),
                'net' => round((float) $run->payslips->sum('gross') - (float) $run->payslips->sum('payg_withheld'), 2),
            ],
            'employees' => $employees,
        ]);
    }

    public function addPayslip(Request $request, PayRun $run)
    {
        $validated = $request->validate([
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'hours' => ['nullable', 'numeric', 'min:0'],
            'gross' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
        ]);

        $employee = Employee::findOrFail($validated['employee_id']);

        try {
            $this->payroll->addPayslip(
                $run,
                $employee,
                $validated['hours'] !== null ? (float) $validated['hours'] : null,
                $validated['gross'] !== null ? (float) $validated['gross'] : null,
                $validated['notes'] ?? null,
            );
        } catch (\Throwable $e) {
            report($e);

            return redirect()->route('payroll.runs.show', $run)->with('error', $e->getMessage());
        }

        return redirect()->route('payroll.runs.show', $run)->with('success', 'Payslip added for '.$employee->name.'.');
    }

    public function removePayslip(Request $request, PayRun $run, Payslip $payslip)
    {
        if ($run->isProcessed()) {
            return redirect()->route('payroll.runs.show', $run)
                ->with('error', 'This pay run is already processed — reverse it to edit.');
        }

        $payslip->delete();

        return redirect()->route('payroll.runs.show', $run)->with('success', 'Payslip removed.');
    }

    public function process(Request $request, PayRun $run)
    {
        try {
            $this->payroll->process($run);
        } catch (\Throwable $e) {
            report($e);

            return redirect()->route('payroll.runs.show', $run)->with('error', $e->getMessage());
        }

        return redirect()->route('payroll.runs.show', $run)
            ->with('success', 'Pay run processed — wages, PAYG withholding, super and the net payment are on the ledger.');
    }

    public function reverse(Request $request, PayRun $run)
    {
        try {
            $this->payroll->reverse($run);
        } catch (\Throwable $e) {
            report($e);

            return redirect()->route('payroll.runs.show', $run)->with('error', $e->getMessage());
        }

        return redirect()->route('payroll.runs.show', $run)
            ->with('success', 'Pay run reversed — the run is back to draft and the ledger restored.');
    }

    public function destroy(Request $request, PayRun $run)
    {
        if ($run->isProcessed()) {
            return redirect()->route('payroll.runs.show', $run)
                ->with('error', 'Reverse the run before deleting it.');
        }

        $run->delete();

        return redirect()->route('payroll.index')->with('success', 'Draft pay run deleted.');
    }
}

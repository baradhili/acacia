<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Services\IfrsPosting;
use Illuminate\Http\Request;

/**
 * Payroll employee master data: pay basis and rates, tax treatment
 * (TFN, tax-free threshold → the NAT 1004 scale), super, and the
 * closely-linked / personal-services markers. Admin/accountant only
 * (route middleware).
 */
class PayrollEmployeeController extends Controller
{
    public function index()
    {
        return view('payroll.employees.index', [
            'employees' => Employee::orderBy('name')->get(),
        ]);
    }

    public function create()
    {
        return view('payroll.employees.form', ['employee' => new Employee]);
    }

    public function store(Request $request)
    {
        Employee::create($this->validated($request) + [
            'entity_id' => IfrsPosting::resolveEntity()->id,
        ]);

        return redirect()->route('payroll.employees.index')->with('success', 'Employee added.');
    }

    public function edit(Employee $employee)
    {
        return view('payroll.employees.form', ['employee' => $employee]);
    }

    public function update(Request $request, Employee $employee)
    {
        $employee->update($this->validated($request));

        return redirect()->route('payroll.employees.index')->with('success', 'Employee updated.');
    }

    public function destroy(Employee $employee)
    {
        if ($employee->payslips()->exists()) {
            return redirect()->route('payroll.employees.index')
                ->with('error', 'This payee has payslips — set them inactive instead of deleting.');
        }

        $employee->delete();

        return redirect()->route('payroll.employees.index')->with('success', 'Employee deleted.');
    }

    protected function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'tfn' => ['nullable', 'string', 'max:20'],
            'employment_type' => ['required', 'in:employee,director,contractor'],
            'labour_only' => ['boolean'],
            'payment_basis' => ['required', 'in:hourly,salary'],
            'hourly_rate' => ['nullable', 'numeric', 'min:0'],
            'annual_salary' => ['nullable', 'numeric', 'min:0'],
            'tax_free_threshold' => ['boolean'],
            'super_rate' => ['nullable', 'numeric', 'min:0', 'max:0.5'],
            'super_fund' => ['nullable', 'string', 'max:255'],
            'super_member_id' => ['nullable', 'string', 'max:50'],
            'is_closely_linked' => ['boolean'],
            'is_personal_services' => ['boolean'],
            'notes' => ['nullable', 'string'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
            'status' => ['required', 'in:active,inactive'],
        ]);
    }
}

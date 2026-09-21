<?php

namespace App\Rules;

use App\Services\EmployeeDirectory;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The employee behind an employee-paid expense: an active payroll
 * employee — exactly the set EmployeeDirectory::active() offers in the
 * pickers, so what can be selected always validates and vice versa.
 */
class ActiveEmployee implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! EmployeeDirectory::active()->has($value)) {
            $fail('The selected employee is not an active employee.');
        }
    }
}

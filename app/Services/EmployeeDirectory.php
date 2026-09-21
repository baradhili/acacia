<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Modules\Payroll\Models\Employee;

/**
 * id => name map of active payroll employees, for the employee pickers
 * on the employee-expense screens. Empty when the Payroll module (or its
 * table) is absent — the feature degrades to "no employees to pick".
 */
class EmployeeDirectory
{
    public static function active(): Collection
    {
        if (! class_exists(Employee::class) || ! Schema::hasTable('employees')) {
            return collect();
        }

        return Employee::query()
            ->where('status', Employee::STATUS_ACTIVE)
            ->orderBy('name')
            ->pluck('name', 'id');
    }
}

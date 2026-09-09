<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One employee's pay within a run: gross (hours × rate, salary
 * apportioned, or an override), PAYG withheld per their ATO scale,
 * super on ordinary earnings, and the net paid. The closely-linked
 * and personal-services markers are snapshotted from the employee so
 * a processed run keeps showing what was flagged when it was paid.
 */
class Payslip extends Model
{
    protected $fillable = [
        'pay_run_id',
        'employee_id',
        'hours',
        'gross',
        'payg_withheld',
        'super',
        'net_pay',
        'is_closely_linked',
        'is_personal_services',
        'notes',
    ];

    protected $casts = [
        'hours' => 'decimal:2',
        'gross' => 'decimal:2',
        'payg_withheld' => 'decimal:2',
        'super' => 'decimal:2',
        'net_pay' => 'decimal:2',
        'is_closely_linked' => 'boolean',
        'is_personal_services' => 'boolean',
    ];

    public function payRun(): BelongsTo
    {
        return $this->belongsTo(PayRun::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}

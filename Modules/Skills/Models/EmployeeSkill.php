<?php

namespace Modules\Skills\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Payroll\Models\Employee;

/**
 * The payee side of a skill link: a payroll payee holds a skill at a
 * proficiency level. Modelled explicitly (the ProjectStaff pattern)
 * so the employee matrix can sync one payee's skills without
 * touching the Payroll module's Employee model; Skill::employees()
 * reads the same table from the other side.
 */
class EmployeeSkill extends Model
{
    protected $table = 'employee_skill';

    protected $fillable = ['employee_id', 'skill_id', 'proficiency'];

    /**
     * Relate this skill link to the payroll payee who holds it.
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * Relate this payee link to its library skill.
     */
    public function skill(): BelongsTo
    {
        return $this->belongsTo(Skill::class);
    }
}

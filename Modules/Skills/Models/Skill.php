<?php

namespace Modules\Skills\Models;

use App\Models\Service;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Modules\Payroll\Models\Employee;

/**
 * A skill from the practice's library: a named capability with an
 * optional category, linked on one side to the payees who hold it —
 * each at a proficiency level — and on the other to the services
 * that require it. The link tables are owned by this module, so the
 * Payroll and core Service models stay untouched.
 */
class Skill extends Model
{
    public const PROFICIENCY_BEGINNER = 'beginner';

    public const PROFICIENCY_INTERMEDIATE = 'intermediate';

    public const PROFICIENCY_ADVANCED = 'advanced';

    public const PROFICIENCY_EXPERT = 'expert';

    protected $fillable = ['name', 'description', 'category'];

    /**
     * Proficiency levels carried on the employee link, weakest to
     * strongest (resource_mgr's scale, kept verbatim).
     */
    public static function proficiencies(): array
    {
        return [
            self::PROFICIENCY_BEGINNER => 'Beginner',
            self::PROFICIENCY_INTERMEDIATE => 'Intermediate',
            self::PROFICIENCY_ADVANCED => 'Advanced',
            self::PROFICIENCY_EXPERT => 'Expert',
        ];
    }

    public function employees(): BelongsToMany
    {
        return $this->belongsToMany(Employee::class, 'employee_skill', 'skill_id', 'employee_id')
            ->withPivot('proficiency')
            ->withTimestamps();
    }

    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class, 'service_skill', 'skill_id', 'service_id')
            ->withTimestamps();
    }
}

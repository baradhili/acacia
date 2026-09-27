<?php

namespace Modules\Skills\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Modules\Payroll\Models\Employee;
use Modules\Skills\Models\EmployeeSkill;
use Modules\Skills\Models\Skill;

/**
 * The payee side of the skill matrix: which skills each payroll
 * payee holds, at what proficiency. Viewing the matrix is open to
 * every signed-in user; posting changes is limited to admins and
 * accountants by the routes.
 */
class EmployeeSkillController extends Controller
{
    public function index(Request $request): View
    {
        /** @var Collection $counts */
        $counts = EmployeeSkill::select('employee_id', DB::raw('count(*) as total'))
            ->groupBy('employee_id')
            ->pluck('total', 'employee_id');

        return view('skills.employees.index', [
            'employees' => Employee::orderBy('name')->get(),
            'skillCounts' => $counts,
            'canManage' => $request->user()->hasAnyRole(['admin', 'accountant']),
        ]);
    }

    public function show(Request $request, Employee $employee): View
    {
        return view('skills.employees.show', [
            'employee' => $employee,
            'skills' => Skill::orderBy('name')->get(),
            // skill_id => proficiency for the skills this payee holds
            'current' => EmployeeSkill::where('employee_id', $employee->id)
                ->pluck('proficiency', 'skill_id'),
            'proficiencies' => Skill::proficiencies(),
            'canManage' => $request->user()->hasAnyRole(['admin', 'accountant']),
        ]);
    }

    public function update(Request $request, Employee $employee): RedirectResponse
    {
        $validated = $request->validate([
            // checkbox grid: skills[<id>] => '1' when checked,
            // proficiency[<id>] => level for the checked rows
            'skills' => ['nullable', 'array'],
            'skills.*' => ['accepted'],
            'proficiency' => ['nullable', 'array'],
            'proficiency.*' => [Rule::in(array_keys(Skill::proficiencies()))],
        ]);

        $this->sync($employee, $validated);

        return redirect()
            ->route('skills.employees.show', $employee)
            ->with('success', 'Skills updated for '.$employee->name.'.');
    }

    /**
     * Replace the payee's skill set with the checked rows, keeping
     * each link's proficiency. Done through the pivot model rather
     * than a relation on Employee — the Payroll model stays
     * untouched. Upserted on the composite key: updateOrCreate would
     * silently no-op here, since Eloquent assumes a surrogate id PK
     * this table doesn't have. Unknown skill ids are dropped rather
     * than rejected; they cannot come from the rendered form. The
     * delete and the upsert share a transaction — if the write fails
     * (a checked skill deleted concurrently, say) the payee's set is
     * left untouched rather than half-replaced.
     */
    protected function sync(Employee $employee, array $validated): void
    {
        $skillIds = Skill::whereIn('id', array_keys($validated['skills'] ?? []))->pluck('id');

        DB::transaction(function () use ($employee, $skillIds, $validated): void {
            EmployeeSkill::where('employee_id', $employee->id)
                ->whereNotIn('skill_id', $skillIds)
                ->delete();

            EmployeeSkill::upsert(
                $skillIds
                    ->map(fn (int $skillId) => [
                        'employee_id' => $employee->id,
                        'skill_id' => $skillId,
                        'proficiency' => $validated['proficiency'][$skillId] ?? Skill::PROFICIENCY_BEGINNER,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ])
                    ->all(),
                ['employee_id', 'skill_id'],
                ['proficiency', 'updated_at'],
            );
        });
    }
}

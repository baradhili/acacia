<?php

namespace Modules\Skills\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Service;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Modules\Skills\Models\ServiceSkill;
use Modules\Skills\Models\Skill;

/**
 * The service side of the skill matrix: which skills each catalogue
 * service requires. Viewing is open to every signed-in user; posting
 * changes is limited to admins and accountants by the routes.
 */
class ServiceSkillController extends Controller
{
    /**
     * Display all services by name with their required-skill counts and editing availability.
     */
    public function index(Request $request): View
    {
        /** @var Collection $counts */
        $counts = ServiceSkill::select('service_id', DB::raw('count(*) as total'))
            ->groupBy('service_id')
            ->pluck('total', 'service_id');

        return view('skills.services.index', [
            'services' => Service::orderBy('name')->get(),
            'skillCounts' => $counts,
            'canManage' => $request->user()->hasAnyRole(['admin', 'accountant']),
        ]);
    }

    /**
     * Display the skill library with this service's current requirements and editing availability.
     */
    public function show(Request $request, Service $service): View
    {
        return view('skills.services.show', [
            'service' => $service,
            'skills' => Skill::orderBy('name')->get(),
            // skill_id => skill_id for the skills this service
            // requires (the value is irrelevant; isset is the check)
            'current' => ServiceSkill::where('service_id', $service->id)
                ->pluck('skill_id', 'skill_id'),
            'canManage' => $request->user()->hasAnyRole(['admin', 'accountant']),
        ]);
    }

    /**
     * Replace required skills and redirect to the service's matrix with a success message.
     *
     * The skills request array maps skill IDs to accepted checkbox values.
     * Unknown IDs are ignored; missing, null, or empty skills clears the set.
     * Failed link writes roll back the transaction; database errors propagate.
     *
     * @throws ValidationException If skills is not an array or null, or a checkbox value is invalid.
     */
    public function update(Request $request, Service $service): RedirectResponse
    {
        $request->validate([
            // checkbox grid: skills[<id>] => '1' when checked
            'skills' => ['nullable', 'array'],
            'skills.*' => ['accepted'],
        ]);

        $skillIds = Skill::whereIn('id', array_keys($request->array('skills')))->pluck('id');

        // Through the pivot model rather than a relation on Service —
        // the core model stays untouched. Unknown ids are dropped;
        // they cannot come from the rendered form. The delete and
        // the writes share a transaction so a mid-sync failure (a
        // checked skill deleted concurrently, say) leaves the
        // service's requirements untouched rather than half-replaced.
        DB::transaction(function () use ($service, $skillIds): void {
            ServiceSkill::where('service_id', $service->id)
                ->whereNotIn('skill_id', $skillIds)
                ->delete();

            foreach ($skillIds as $skillId) {
                ServiceSkill::firstOrCreate([
                    'service_id' => $service->id,
                    'skill_id' => $skillId,
                ]);
            }
        });

        return redirect()
            ->route('skills.services.show', $service)
            ->with('success', 'Required skills updated for '.$service->name.'.');
    }
}

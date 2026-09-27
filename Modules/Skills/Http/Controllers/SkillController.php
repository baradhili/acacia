<?php

namespace Modules\Skills\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Modules\Skills\Models\Skill;

/**
 * The skill library CRUD. Viewing is open to every signed-in user;
 * editing is limited to admins and accountants by the routes.
 */
class SkillController extends Controller
{
    public function index(Request $request): View
    {
        $query = Skill::query()
            ->withCount(['employees', 'services'])
            ->orderBy('name')
            ->when($request->filled('q'), function (Builder $query) use ($request) {
                // instr(), not LIKE: a search for "100%" or "tax_"
                // must match those characters literally. LIKE
                // wildcards cannot be escaped portably across the
                // SQLite test connection and the MySQL dev one
                // (MySQL's LIKE has no ESCAPE clause; SQLite treats a
                // backslash as an ordinary character), while instr()
                // is a plain literal substring test on both.
                $term = mb_strtolower((string) $request->input('q'));

                return $query->where(function (Builder $query) use ($term) {
                    $query->whereRaw('instr(lower(name), ?) > 0', [$term])
                        ->orWhereRaw('instr(lower(description), ?) > 0', [$term]);
                });
            })
            ->when($request->filled('category'), function (Builder $query) use ($request) {
                $query->where('category', $request->input('category'));
            });

        /** @var LengthAwarePaginator $skills */
        $skills = $query->paginate(25)->withQueryString();

        /** @var Collection $categories */
        $categories = Skill::whereNotNull('category')
            ->distinct()
            ->orderBy('category')
            ->pluck('category');

        return view('skills.index', [
            'skills' => $skills,
            'categories' => $categories,
            'canManage' => $this->canManage($request),
        ]);
    }

    public function create(): View
    {
        return view('skills.form', ['skill' => new Skill]);
    }

    public function store(Request $request): RedirectResponse
    {
        $skill = Skill::create($this->validated($request));

        return redirect()->route('skills.show', $skill)->with('success', 'Skill created.');
    }

    public function show(Request $request, Skill $skill): View
    {
        $skill->load([
            'employees' => fn ($query) => $query->orderBy('name'),
            'services' => fn ($query) => $query->orderBy('name'),
        ]);

        return view('skills.show', [
            'skill' => $skill,
            'canManage' => $this->canManage($request),
        ]);
    }

    public function edit(Skill $skill): View
    {
        return view('skills.form', ['skill' => $skill]);
    }

    public function update(Request $request, Skill $skill): RedirectResponse
    {
        $skill->update($this->validated($request, $skill));

        return redirect()->route('skills.show', $skill)->with('success', 'Skill updated.');
    }

    public function destroy(Skill $skill): RedirectResponse
    {
        $skill->delete();

        return redirect()->route('skills.index')->with('success', 'Skill deleted.');
    }

    protected function validated(Request $request, ?Skill $skill = null): array
    {
        $unique = Rule::unique('skills', 'name');
        if ($skill?->exists) {
            $unique->ignore($skill);
        }

        return $request->validate([
            'name' => ['required', 'string', 'max:255', $unique],
            'description' => ['nullable', 'string'],
            'category' => ['nullable', 'string', 'max:255'],
        ]);
    }

    protected function canManage(Request $request): bool
    {
        return $request->user()->hasAnyRole(['admin', 'accountant']);
    }
}

<?php

namespace Modules\Skills\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
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
        $skills = Skill::query()
            ->withCount(['employees', 'services'])
            ->when($request->filled('category'), function (Builder $query) use ($request) {
                $query->where('category', $request->input('category'));
            })
            ->orderBy('name')
            ->get();

        if ($request->filled('q')) {
            // The substring test happens in PHP with mb_stripos: a
            // literal match (no LIKE wildcards to escape) whose
            // case-folding reaches beyond ASCII — "école" finds
            // "École". SQL folding cannot do this portably: lower()
            // is ASCII-only on SQLite, and LIKE wildcards cannot be
            // escaped across the SQLite test and MySQL dev
            // connections at all. A skill library is small enough
            // to fold in memory.
            $term = (string) $request->input('q');
            $skills = $skills
                ->filter(fn (Skill $skill) => mb_stripos($skill->name."\n".(string) $skill->description, $term) !== false)
                ->values();
        }

        $categories = Skill::whereNotNull('category')
            ->distinct()
            ->orderBy('category')
            ->pluck('category');

        // Manual pagination: the filtered set lives in memory, not in
        // a query builder.
        $page = max(1, $request->integer('page', 1));
        $perPage = 25;
        $skills = new LengthAwarePaginator(
            $skills->forPage($page, $perPage)->values(),
            count($skills),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );

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

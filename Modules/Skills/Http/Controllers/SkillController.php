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
    /**
     * Display skills by name with employee and service counts, 25 per page.
     *
     * A filled category filters by equality; a filled q matches a literal,
     * case-insensitive Unicode substring in the name or description. Page
     * numbers below one use page one. Category choices cover the full library.
     */
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

    /**
     * Display the skill form with a new, unsaved skill.
     */
    public function create(): View
    {
        return view('skills.form', ['skill' => new Skill]);
    }

    /**
     * Create a validated skill and redirect to its details with a success message.
     * Database errors propagate.
     *
     * @throws \Illuminate\Validation\ValidationException If the skill fields fail validation.
     */
    public function store(Request $request): RedirectResponse
    {
        $skill = Skill::create($this->validated($request));

        return redirect()->route('skills.show', $skill)->with('success', 'Skill created.');
    }

    /**
     * Load linked payees and services by name and display the skill's details.
     */
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

    /**
     * Display the skill form populated with the existing skill.
     */
    public function edit(Skill $skill): View
    {
        return view('skills.form', ['skill' => $skill]);
    }

    /**
     * Update validated fields and redirect to the skill's details with a success message.
     * Omitted optional fields retain their values. Database errors propagate.
     *
     * @throws \Illuminate\Validation\ValidationException If the skill fields fail validation.
     */
    public function update(Request $request, Skill $skill): RedirectResponse
    {
        $skill->update($this->validated($request, $skill));

        return redirect()->route('skills.show', $skill)->with('success', 'Skill updated.');
    }

    /**
     * Delete the skill, cascading to its payee and service links, then redirect
     * to the library with a success message. Database errors propagate.
     */
    public function destroy(Skill $skill): RedirectResponse
    {
        $skill->delete();

        return redirect()->route('skills.index')->with('success', 'Skill deleted.');
    }

    /**
     * Return validated skill fields, excluding a persisted $skill from name uniqueness checks.
     *
     * Name is required and limited to 255 characters. Description and category
     * are optional nullable strings; category is also limited to 255 characters.
     * Omitted optional fields are absent from the result.
     *
     * @throws \Illuminate\Validation\ValidationException If any field fails validation.
     */
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

    /**
     * Whether the authenticated request user has the admin or accountant role.
     */
    protected function canManage(Request $request): bool
    {
        return $request->user()->hasAnyRole(['admin', 'accountant']);
    }
}

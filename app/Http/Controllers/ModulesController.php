<?php

namespace App\Http\Controllers;

use App\Services\ModuleManager;
use Illuminate\Http\Request;

/**
 * The admin Modules screen: the installed list with lifecycle
 * actions (enable/disable/uninstall/update for git-installed) and
 * install-from-GitHub — akaunting's GUI flow without the store.
 */
class ModulesController extends Controller
{
    public function __construct(protected ModuleManager $manager) {}

    public function index()
    {
        return view('modules.index', ['modules' => $this->manager->installed()]);
    }

    public function enable(string $module)
    {
        $this->manager->enable($module);

        return back()->with('success', "{$module} enabled.");
    }

    public function disable(string $module)
    {
        $this->manager->disable($module);

        return back()->with('success', "{$module} disabled — its routes, screens and nav entries are gone until re-enabled.");
    }

    public function update(string $module)
    {
        try {
            $this->manager->update($module);
        } catch (\RuntimeException|\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "{$module} updated to its latest commit.");
    }

    public function uninstall(Request $request, string $module)
    {
        $validated = $request->validate([
            'drop_data' => ['nullable', 'boolean'],
        ]);

        try {
            $this->manager->uninstall($module, (bool) ($validated['drop_data'] ?? true));
        } catch (\RuntimeException|\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('modules.index')
            ->with('success', "{$module} uninstalled.");
    }

    public function installFromGit(Request $request)
    {
        $validated = $request->validate([
            'repository' => ['required', 'string', 'max:500'],
        ]);

        try {
            $result = $this->manager->installFromGit(trim($validated['repository']));
        } catch (\RuntimeException|\InvalidArgumentException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('modules.index')
            ->with('success', "{$result['name']} installed from git and enabled.");
    }
}

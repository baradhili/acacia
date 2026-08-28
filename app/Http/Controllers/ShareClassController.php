<?php

namespace App\Http\Controllers;

use App\Models\CompanyProfile;
use App\Models\ShareClass;
use App\Services\IfrsPosting;
use Illuminate\Http\Request;

/**
 * Maintenance of the company's share classes (ORD, PREF, ...). Deleting a
 * class is refused once shareholdings or declarations reference it —
 * inactivate it instead.
 */
class ShareClassController extends Controller
{
    public function index()
    {
        return view('share-classes.index', [
            'profile' => $profile = $this->profile(),
            'shareClasses' => $profile ? $profile->shareClasses()->withCount('shareholdings')->get() : collect(),
        ]);
    }

    public function create()
    {
        return view('share-classes.form', ['shareClass' => new ShareClass, 'profile' => $this->profile()]);
    }

    public function store(Request $request)
    {
        $profile = $this->profile();
        abort_unless((bool) $profile, 404, 'Maintain the company profile first.');

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:10', 'regex:/^[A-Za-z0-9]+$/'],
            'description' => ['nullable', 'string', 'max:60'],
            'voting_rights' => ['nullable', 'boolean'],
            'dividend_rights' => ['nullable', 'boolean'],
            'ranking' => ['required', 'integer', 'min:1', 'max:99'],
            'franking_entitlement' => ['nullable', 'boolean'],
            'status' => ['required', 'in:A,I'],
        ], [
            'code.regex' => 'Use letters and digits only (e.g. ORD, PREF).',
        ]);

        $exists = ShareClass::where('company_profile_id', $profile->id)
            ->where('code', strtoupper($validated['code']))
            ->exists();
        if ($exists) {
            return redirect()->route('share-classes.create')
                ->withInput()
                ->with('error', "Share class {$validated['code']} already exists.");
        }

        $profile->shareClasses()->create([
            'code' => strtoupper($validated['code']),
            'description' => $validated['description'] ?? null,
            'voting_rights' => (bool) ($validated['voting_rights'] ?? false),
            'dividend_rights' => (bool) ($validated['dividend_rights'] ?? false),
            'ranking' => (int) $validated['ranking'],
            'franking_entitlement' => (bool) ($validated['franking_entitlement'] ?? false),
            'status' => $validated['status'],
        ]);

        return redirect()->route('share-classes.index')->with('success', 'Share class created.');
    }

    public function edit(ShareClass $shareClass)
    {
        return view('share-classes.form', ['shareClass' => $shareClass, 'profile' => $this->profile()]);
    }

    public function update(Request $request, ShareClass $shareClass)
    {
        $validated = $request->validate([
            'description' => ['nullable', 'string', 'max:60'],
            'voting_rights' => ['nullable', 'boolean'],
            'dividend_rights' => ['nullable', 'boolean'],
            'ranking' => ['required', 'integer', 'min:1', 'max:99'],
            'franking_entitlement' => ['nullable', 'boolean'],
            'status' => ['required', 'in:A,I'],
        ]);

        $shareClass->update([
            'description' => $validated['description'] ?? null,
            'voting_rights' => (bool) ($validated['voting_rights'] ?? false),
            'dividend_rights' => (bool) ($validated['dividend_rights'] ?? false),
            'ranking' => (int) $validated['ranking'],
            'franking_entitlement' => (bool) ($validated['franking_entitlement'] ?? false),
            'status' => $validated['status'],
        ]);

        return redirect()->route('share-classes.index')->with('success', 'Share class updated.');
    }

    public function destroy(ShareClass $shareClass)
    {
        if ($shareClass->shareholdings()->exists() || $shareClass->declarations()->exists()) {
            return redirect()->route('share-classes.index')
                ->with('error', 'Share class is in use — set it inactive instead of deleting.');
        }

        $shareClass->delete();

        return redirect()->route('share-classes.index')->with('success', 'Share class deleted.');
    }

    protected function profile(): ?CompanyProfile
    {
        $entity = IfrsPosting::resolveEntity();
        if (! $entity) {
            return null;
        }

        return CompanyProfile::where('entity_id', $entity->id)->first();
    }
}

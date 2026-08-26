<?php

namespace App\Http\Controllers;

use App\Models\CompanyDirector;
use App\Models\CompanyProfile;
use App\Models\CompanyShareholder;
use App\Services\IfrsPosting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Maintain the reporting entity's company identity — ABN/TFN/ACN,
 * registered address, contact details, directors and the shareholder
 * registry. Admin/accountant only. The ABN/TFN feed the Company Tax
 * Report identification section (with env-config fallback).
 */
class CompanyProfileController extends Controller
{
    public function index()
    {
        $entity = IfrsPosting::resolveEntity();
        abort_unless((bool) $entity, 404, 'No IFRS entity configured.');

        $profile = CompanyProfile::firstOrNew(
            ['entity_id' => $entity->id],
            ['country' => 'AU']
        )->load('directors', 'allShareholders');

        return view('company-profile.index', [
            'entity' => $entity,
            'profile' => $profile,
        ]);
    }

    public function update(Request $request)
    {
        $entity = IfrsPosting::resolveEntity();
        abort_unless((bool) $entity, 404, 'No IFRS entity configured.');

        $validated = $request->validate([
            'abn' => ['nullable', 'digits:11'],
            'tfn' => ['nullable', 'digits:9'],
            'acn' => ['nullable', 'digits:9'],
            'address_line1' => ['nullable', 'string', 'max:100'],
            'address_line2' => ['nullable', 'string', 'max:100'],
            'suburb' => ['nullable', 'string', 'max:60'],
            'state' => ['nullable', 'string', 'max:3'],
            'postcode' => ['nullable', 'string', 'max:4'],
            'country' => ['nullable', 'string', 'size:2'],
            'email' => ['nullable', 'email', 'max:100'],
            'phone' => ['nullable', 'string', 'max:20'],

            'directors' => ['nullable', 'array'],
            'directors.*.name' => ['nullable', 'string', 'max:100'],
            'directors.*.appointment_date' => ['nullable', 'date'],
            'directors.*.resignation_date' => ['nullable', 'date', 'after_or_equal:directors.*.appointment_date'],
            'directors.*.email' => ['nullable', 'email', 'max:100'],
            'directors.*.phone' => ['nullable', 'string', 'max:20'],

            'shareholders' => ['nullable', 'array'],
            'shareholders.*.name' => ['nullable', 'string', 'max:100'],
            'shareholders.*.abn' => ['nullable', 'digits:11'],
            'shareholders.*.tfn' => ['nullable', 'digits:9'],
            'shareholders.*.address_line1' => ['nullable', 'string', 'max:100'],
            'shareholders.*.suburb' => ['nullable', 'string', 'max:60'],
            'shareholders.*.state' => ['nullable', 'string', 'max:3'],
            'shareholders.*.postcode' => ['nullable', 'string', 'max:4'],
            'shareholders.*.country' => ['nullable', 'string', 'size:2'],
            'shareholders.*.email' => ['nullable', 'email', 'max:100'],
            'shareholders.*.phone' => ['nullable', 'string', 'max:20'],
            'shareholders.*.resident_for_tax' => ['nullable', 'boolean'],
            'shareholders.*.share_class' => ['nullable', 'string', 'max:10'],
            'shareholders.*.shares_held' => ['nullable', 'integer', 'min:0'],
            'shareholders.*.status' => ['nullable', Rule::in([CompanyShareholder::STATUS_ACTIVE, CompanyShareholder::STATUS_INACTIVE])],
        ]);

        DB::transaction(function () use ($validated, $entity) {
            $profile = CompanyProfile::updateOrCreate(
                ['entity_id' => $entity->id],
                collect($validated)->only((new CompanyProfile())->getFillable())
                    ->except('entity_id')
                    ->map(fn ($value) => $value === '' ? null : $value)
                    ->put('country', $validated['country'] ?? 'AU')
                    ->all()
            );

            // Registry rows are small lists — replace them wholesale from
            // the submission rather than diffing ids row by row.
            $profile->directors()->delete();
            foreach (array_filter($validated['directors'] ?? [], fn ($row) => !empty(trim($row['name'] ?? ''))) as $row) {
                $profile->directors()->create([
                    'name' => trim($row['name']),
                    'appointment_date' => $row['appointment_date'] ?? null,
                    'resignation_date' => $row['resignation_date'] ?? null,
                    'email' => $row['email'] ?? null,
                    'phone' => $row['phone'] ?? null,
                ]);
            }

            $profile->allShareholders()->delete();
            foreach (array_filter($validated['shareholders'] ?? [], fn ($row) => !empty(trim($row['name'] ?? ''))) as $row) {
                $profile->allShareholders()->create([
                    'name' => trim($row['name']),
                    'abn' => $row['abn'] ?? null,
                    'tfn' => $row['tfn'] ?? null,
                    'address_line1' => $row['address_line1'] ?? null,
                    'suburb' => $row['suburb'] ?? null,
                    'state' => $row['state'] ?? null,
                    'postcode' => $row['postcode'] ?? null,
                    'country' => $row['country'] ?? 'AU',
                    'email' => $row['email'] ?? null,
                    'phone' => $row['phone'] ?? null,
                    'resident_for_tax' => (bool) ($row['resident_for_tax'] ?? false),
                    'share_class' => $row['share_class'] ?: 'ORD',
                    'shares_held' => (int) ($row['shares_held'] ?? 0),
                    'status' => $row['status'] ?? CompanyShareholder::STATUS_ACTIVE,
                ]);
            }
        });

        return redirect()->route('company-profile.index')
            ->with('success', 'Company details saved.');
    }
}

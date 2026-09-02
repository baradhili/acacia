<?php

namespace App\Http\Controllers;

use App\Models\CompanyProfile;
use App\Models\CompanyShareholder;
use App\Models\ShareClass;
use App\Services\IfrsPosting;
use App\Services\ShareholdingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Maintain the reporting entity's company identity — legal name (on the
 * IFRS entity, authoritative for statutory outputs), optional trading
 * name, ABN/TFN/ACN, registered address, contact details, directors and
 * the shareholder registry. Admin/accountant only. The ABN/TFN feed the
 * Company Tax Report identification section (with env-config fallback).
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

        // Normalize directors to an array of arrays so Blade doesn't need to
        // guess whether it's dealing with an Eloquent model or old() input.
        $directors = old('directors', $profile->directors->map(fn ($d) => [
            'id' => $d->id,
            'name' => $d->name,
            'appointment_date' => $d->appointment_date?->format('Y-m-d'),
            'resignation_date' => $d->resignation_date?->format('Y-m-d'),
            'email' => $d->email,
            'phone' => $d->phone,
        ])->all());

        // Normalize shareholders similarly
        $shareholders = old('shareholders', $profile->allShareholders->map(fn ($s) => [
            'id' => $s->id,
            'name' => $s->name,
            'share_class' => $s->share_class,
            'shares_held' => $s->shares_held,
            'resident_for_tax' => $s->resident_for_tax,
            'status' => $s->status,
            'abn' => $s->abn,
            'tfn' => $s->tfn,
            'email' => $s->email,
            'address_line1' => $s->address_line1,
            'address_line2' => $s->address_line2,
            'suburb' => $s->suburb,
            'state' => $s->state,
            'postcode' => $s->postcode,
            'country' => $s->country,
            'contact_name' => $s->contact_name,
            'bank_bsb' => $s->bank_bsb,
            'bank_account_number' => $s->bank_account_number,
            'bank_account_name' => $s->bank_account_name,
        ])->all());

        return view('company-profile.index', [
            'entity' => $entity,
            'profile' => $profile,
            'directors' => $directors,
            'shareholders' => $shareholders,
        ]);
    }

    public function update(Request $request)
    {
        $entity = IfrsPosting::resolveEntity();
        abort_unless((bool) $entity, 404, 'No IFRS entity configured.');

        $validated = $request->validate([
            // The legal name lives on the IFRS entity — it feeds statutory
            // outputs (Company Tax Return identification, dividend
            // statements). Trading name is the optional business name.
            'name' => ['required', 'string', 'max:300'],
            'trading_name' => ['nullable', 'string', 'max:100'],
            'abn' => ['nullable', 'digits:11'],
            'tfn' => ['nullable', 'digits:9'],
            'acn' => ['nullable', 'digits:9'],
            'tax_rate_type' => ['nullable', Rule::in([CompanyProfile::TAX_RATE_SMALL, CompanyProfile::TAX_RATE_COMPANY])],
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
            'shareholders.*.id' => ['nullable', 'integer'], // present for existing rows (keyed update)
            'shareholders.*.name' => ['nullable', 'string', 'max:100'],
            'shareholders.*.abn' => ['nullable', 'digits:11'],
            'shareholders.*.tfn' => ['nullable', 'digits:9'],
            'shareholders.*.address_line1' => ['nullable', 'string', 'max:100'],
            'shareholders.*.address_line2' => ['nullable', 'string', 'max:100'],
            'shareholders.*.suburb' => ['nullable', 'string', 'max:60'],
            'shareholders.*.state' => ['nullable', 'string', 'max:3'],
            'shareholders.*.postcode' => ['nullable', 'string', 'max:4'],
            'shareholders.*.country' => ['nullable', 'string', 'size:2'],
            'shareholders.*.contact_name' => ['nullable', 'string', 'max:60'],
            'shareholders.*.email' => ['nullable', 'email', 'max:100'],
            'shareholders.*.phone' => ['nullable', 'string', 'max:20'],
            'shareholders.*.bank_bsb' => ['nullable', 'string', 'max:7'],
            'shareholders.*.bank_account_number' => ['nullable', 'string', 'max:9'],
            'shareholders.*.bank_account_name' => ['nullable', 'string', 'max:60'],
            'shareholders.*.resident_for_tax' => ['nullable', 'boolean'],
            'shareholders.*.share_class' => ['nullable', 'string', 'max:10'],
            'shareholders.*.shares_held' => ['nullable', 'integer', 'min:0'],
            'shareholders.*.status' => ['nullable', Rule::in([CompanyShareholder::STATUS_ACTIVE, CompanyShareholder::STATUS_INACTIVE])],
        ]);

        DB::transaction(function () use ($validated, $entity) {
            $entity->update(['name' => $validated['name']]);

            $profile = CompanyProfile::updateOrCreate(
                ['entity_id' => $entity->id],
                collect($validated)->only((new CompanyProfile)->getFillable())
                    ->except('entity_id')
                    ->map(fn ($value) => $value === '' ? null : $value)
                    ->put('country', $validated['country'] ?? 'AU')
                    ->all()
            );

            // Registry rows are small lists — replace them wholesale from
            // the submission rather than diffing ids row by row.
            $profile->directors()->delete();
            foreach (array_filter($validated['directors'] ?? [], fn ($row) => ! empty(trim($row['name'] ?? ''))) as $row) {
                $profile->directors()->create([
                    'name' => trim($row['name']),
                    'appointment_date' => $row['appointment_date'] ?? null,
                    'resignation_date' => $row['resignation_date'] ?? null,
                    'email' => $row['email'] ?? null,
                    'phone' => $row['phone'] ?? null,
                ]);
            }

            // Shareholders are updated by id (never wholesale-replaced): the
            // shareholding transaction ledger and dividend distributions
            // reference these rows and must survive a profile save. New
            // rows with a share count get an opening issue transaction.
            $submittedIds = [];
            foreach (array_filter($validated['shareholders'] ?? [], fn ($row) => ! empty(trim($row['name'] ?? ''))) as $row) {
                $attributes = [
                    'name' => trim($row['name']),
                    'abn' => $row['abn'] ?? null,
                    'tfn' => $row['tfn'] ?? null,
                    'address_line1' => $row['address_line1'] ?? null,
                    'address_line2' => $row['address_line2'] ?? null,
                    'suburb' => $row['suburb'] ?? null,
                    'state' => $row['state'] ?? null,
                    'postcode' => $row['postcode'] ?? null,
                    'country' => $row['country'] ?? 'AU',
                    'contact_name' => $row['contact_name'] ?? null,
                    'email' => $row['email'] ?? null,
                    'phone' => $row['phone'] ?? null,
                    'bank_bsb' => $row['bank_bsb'] ?? null,
                    'bank_account_number' => $row['bank_account_number'] ?? null,
                    'bank_account_name' => $row['bank_account_name'] ?? null,
                    'resident_for_tax' => (bool) ($row['resident_for_tax'] ?? false),
                    'status' => $row['status'] ?? CompanyShareholder::STATUS_ACTIVE,
                ];

                $existing = ! empty($row['id'])
                    ? $profile->allShareholders()->find($row['id'])
                    : null;

                if ($existing) {
                    // share_class/shares_held are derived caches maintained
                    // by ShareholdingService — never written from this form.
                    $existing->update($attributes);
                    $submittedIds[] = $existing->id;

                    continue;
                }

                $created = $profile->allShareholders()->create([
                    ...$attributes,
                    'share_class' => $row['share_class'] ?: 'ORD',
                    'shares_held' => 0,
                ]);
                $submittedIds[] = $created->id;

                if ((int) ($row['shares_held'] ?? 0) > 0) {
                    $class = ShareClass::firstOrCreate(
                        ['company_profile_id' => $profile->id, 'code' => strtoupper($row['share_class'] ?: 'ORD')],
                        ['description' => 'Shares', 'status' => ShareClass::STATUS_ACTIVE],
                    );
                    $created->update(['shares_held' => (int) $row['shares_held']]);
                    ShareholdingService::backfillOpenings($created, $class);
                }
            }

            // Rows removed from the form: delete when they carry no
            // history, otherwise keep and deactivate (the ledger and any
            // dividend statements must remain reproducible).
            $profile->allShareholders()
                ->whereNotIn('id', $submittedIds ?: [0])
                ->get()
                ->each(function (CompanyShareholder $removed) {
                    if ($removed->shareholdings()->exists() || $removed->dividendDistributions()->exists()) {
                        $removed->update(['status' => CompanyShareholder::STATUS_INACTIVE]);

                        return;
                    }
                    $removed->delete();
                });
        });

        return redirect()->route('company-profile.index')
            ->with('success', 'Company details saved.');
    }
}

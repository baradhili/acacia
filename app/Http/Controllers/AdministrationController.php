<?php

namespace App\Http\Controllers;

use App\Models\EntitySetting;
use App\Services\FiscalYearService;
use App\Services\IfrsPosting;
use IFRS\Models\Entity;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Administration settings. The currently-open-year pin anchors the
 * working financial year and unlocks backfilling: pinning a past year
 * creates its OPEN reporting period, making opening balances and
 * transactions for that year possible. Admin only.
 */
class AdministrationController extends Controller
{
    public function __construct(protected FiscalYearService $service) {}

    public function index()
    {
        $entity = $this->entity();
        abort_unless((bool) $entity, 404, 'No IFRS entity configured.');

        return view('administration.index', [
            'entity' => $entity,
            'options' => $this->service->openYearOptions($entity),
            'currentYear' => $this->service->currentYear($entity),
            'clockYear' => $this->service->clockYear($entity),
            'storedOpenYear' => EntitySetting::storedOpenYear($entity),
            'window' => $this->service->openYearWindow($entity),
        ]);
    }

    public function updateOpenYear(Request $request)
    {
        $entity = $this->entity();
        abort_unless((bool) $entity, 404, 'No IFRS entity configured.');

        [$min, $max] = $this->service->openYearWindow($entity);
        $allowed = array_merge(['auto'], array_map('strval', range($min, $max)));

        $validated = $request->validate([
            'open_year' => ['required', Rule::in($allowed)],
        ]);

        try {
            $this->service->setOpenYear(
                $entity,
                $validated['open_year'] === 'auto' ? null : (int) $validated['open_year']
            );
        } catch (\InvalidArgumentException $e) {
            return redirect()->route('administration.index')->with('error', $e->getMessage());
        }

        $year = $this->service->currentYear($entity);

        return redirect()->route('administration.index')
            ->with('success', $validated['open_year'] === 'auto'
                ? "Open year now follows the calendar (FY {$year})."
                : "FY {$year} is now the open year — opening balances and transactions can be entered for it.");
    }

    protected function entity(): ?Entity
    {
        return IfrsPosting::resolveEntity();
    }
}

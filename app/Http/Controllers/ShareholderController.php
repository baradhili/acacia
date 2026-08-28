<?php

namespace App\Http\Controllers;

use App\Models\CompanyProfile;
use App\Models\CompanyShareholder;
use App\Models\ShareClass;
use App\Models\Shareholding;
use App\Services\IfrsPosting;
use App\Services\ShareholdingService;
use Illuminate\Http\Request;

/**
 * Shareholder registry browser: holdings by class, the dated shareholding
 * transaction history and dividend history. Master data (names, contact,
 * bank details) is edited on the company profile screen; this controller
 * manages the shareholding ledger behind each shareholder.
 */
class ShareholderController extends Controller
{
    public function index(Request $request)
    {
        $profile = $this->profile();

        $shareClasses = $profile ? $profile->shareClasses()->active()->orderBy('code')->get() : collect();
        $issuedTotals = $shareClasses
            ->mapWithKeys(fn ($class) => [$class->id => ShareholdingService::issuedShares($class->id)])
            ->all();

        $shareholders = $profile
            ? $profile->allShareholders()
                ->with('shareholdings.shareClass')
                ->orderBy('name')
                ->get()
                ->each(fn ($shareholder) => $shareholder->holdings = ShareholdingService::holdingsByClass($shareholder))
            : collect();

        return view('shareholders.index', [
            'profile' => $profile,
            'shareholders' => $shareholders,
            'shareClasses' => $shareClasses,
            'issuedTotals' => $issuedTotals,
            'asAt' => $request->date('as_at'),
        ]);
    }

    public function show(CompanyShareholder $shareholder)
    {
        $shareholder->load('shareholdings.shareClass', 'shareholdings.creator', 'profile');

        return view('shareholders.show', [
            'shareholder' => $shareholder,
            'holdings' => ShareholdingService::holdingsByClass($shareholder),
            'shareClasses' => $shareholder->profile?->shareClasses()->active()->orderBy('code')->get() ?? collect(),
            'dividendHistory' => $shareholder->dividendDistributions()
                ->with('declaration.shareClass')
                ->orderByDesc('created_at')
                ->limit(50)
                ->get(),
        ]);
    }

    public function storeShareholding(Request $request, CompanyShareholder $shareholder)
    {
        $validated = $request->validate([
            'share_class_id' => ['required', 'integer', 'exists:share_classes,id'],
            'transaction_type' => ['required', 'in:' . implode(',', array_keys(Shareholding::types()))],
            'transaction_date' => ['required', 'date'],
            'quantity' => ['required', 'integer', 'not_in:0'],
            'unit_price' => ['nullable', 'numeric', 'min:0'],
            'amount_paid' => ['nullable', 'numeric', 'min:0'],
            'reference' => ['nullable', 'string', 'max:20'],
        ]);

        try {
            ShareholdingService::record($shareholder, $validated);
        } catch (\InvalidArgumentException $e) {
            return redirect()->route('shareholders.show', $shareholder)->with('error', $e->getMessage());
        }

        return redirect()->route('shareholders.show', $shareholder)
            ->with('success', 'Shareholding transaction recorded.');
    }

    public function cancelShareholding(Request $request, CompanyShareholder $shareholder, Shareholding $shareholding)
    {
        abort_unless($shareholding->company_shareholder_id === $shareholder->id, 404);

        try {
            ShareholdingService::cancel($shareholding);
        } catch (\InvalidArgumentException $e) {
            return redirect()->route('shareholders.show', $shareholder)->with('error', $e->getMessage());
        }

        return redirect()->route('shareholders.show', $shareholder)
            ->with('success', 'Shareholding transaction cancelled.');
    }

    protected function profile(): ?CompanyProfile
    {
        $entity = IfrsPosting::resolveEntity();
        if (!$entity) {
            return null;
        }

        return CompanyProfile::with('shareClasses')->where('entity_id', $entity->id)->first();
    }
}

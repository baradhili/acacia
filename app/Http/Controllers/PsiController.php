<?php

namespace App\Http\Controllers;

use App\Models\EntitySetting;
use App\Services\IfrsPosting;
use App\Services\PsiService;
use IFRS\Models\ReportingPeriod;
use Illuminate\Http\Request;

/**
 * The PSI screen: the 80% rule tracker over this financial year's
 * service-work (time-entry-backed) income, the PSB Results Test, PSI
 * mode, and the attribution of net PSI to the individual. Admin /
 * accountant only (route middleware); the assessment rules live in
 * PsiService, per the .zcode wages_and_psi spec (modules C/E).
 */
class PsiController extends Controller
{
    public function __construct(protected PsiService $psi) {}

    public function index(Request $request)
    {
        $entity = IfrsPosting::resolveEntity();
        abort_unless((bool) $entity, 404, 'No IFRS entity configured.');

        $fy = $request->get('fy') ? (int) $request->get('fy') : null;

        return view('psi.index', [
            'income' => $this->psi->incomeByClient($entity, $fy),
            'attribution' => $this->psi->attribution($entity, $fy),
            'setting' => EntitySetting::forEntity($entity),
        ]);
    }

    public function assess(Request $request)
    {
        $entity = IfrsPosting::resolveEntity();
        abort_unless((bool) $entity, 404, 'No IFRS entity configured.');

        $this->psi->recordResultsTest($entity, $request->input('answers', []));

        $fy = ReportingPeriod::year(now(), $entity);

        return redirect()->route('psi.index', ['fy' => $fy])
            ->with('success', 'Results Test recorded — PSI mode re-assessed.');
    }
}

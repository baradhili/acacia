<?php

namespace App\Http\Controllers;

use App\Services\FiscalYearService;
use App\Services\IfrsPosting;
use IFRS\Models\Entity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Year-end close workflow UI: FY status table, trial-close review and
 * the submit → approve → execute actions (plus reopen). Role gating is
 * route-level (admin|accountant); the four-eyes rule (requester ≠
 * approver) is enforced by FiscalYearService::approve().
 */
class FinancialYearController extends Controller
{
    public function __construct(protected FiscalYearService $service)
    {
    }

    public function index()
    {
        $entity = $this->entity();
        $current = $this->service->currentYear($entity);

        // The current FY plus the six before it — enough history to see
        // recent closes without paging.
        $years = [];
        for ($year = $current; $year >= $current - 6; $year--) {
            $bounds = $this->service->bounds($entity, $year);
            $years[] = (object) [
                'year' => $year,
                'start' => $bounds['start'],
                'end' => $bounds['end'],
                'ended' => $year < $current,
                'closed' => $this->service->isClosed($entity, $year),
                'record' => $this->service->closeRecord($entity, $year),
            ];
        }

        return view('financial-years.index', [
            'years' => $years,
            'unclosedPriorYear' => $this->service->unclosedPriorYear($entity),
        ]);
    }

    /**
     * Recompute and review a trial close: checklist + proposed closing
     * entries. Snapshots onto the workflow row (creating it in trial
     * status when absent); the ledger is untouched.
     */
    public function trial(int $year)
    {
        $entity = $this->entity();

        try {
            $trial = $this->service->storeTrial($entity, $year, Auth::id());
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            return redirect()->route('financial-years.index')->with('error', $e->getMessage());
        }

        return view('financial-years.trial', ['trial' => $trial]);
    }

    public function submit(int $year)
    {
        $entity = $this->entity();

        try {
            $this->service->submit($entity, $year, Auth::id());
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            return redirect()->route('financial-years.trial', $year)->with('error', $e->getMessage());
        }

        return redirect()->route('financial-years.trial', $year)
            ->with('success', "FY {$year} submitted for approval.");
    }

    public function approve(int $year)
    {
        $entity = $this->entity();

        try {
            $this->service->approve($entity, $year, Auth::user());
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            return redirect()->route('financial-years.trial', $year)->with('error', $e->getMessage());
        }

        return redirect()->route('financial-years.trial', $year)
            ->with('success', "FY {$year} close approved — ready to execute.");
    }

    public function close(int $year)
    {
        $entity = $this->entity();

        try {
            $this->service->close($entity, $year);
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            return redirect()->route('financial-years.trial', $year)->with('error', $e->getMessage());
        }

        return redirect()->route('financial-years.index')
            ->with('success', "FY {$year} closed: P&L transferred to Retained Earnings, period locked.");
    }

    public function reopen(int $year)
    {
        $entity = $this->entity();

        try {
            $this->service->reopen($entity, $year);
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            return redirect()->route('financial-years.index')->with('error', $e->getMessage());
        }

        return redirect()->route('financial-years.index')
            ->with('success', "FY {$year} reopened: closing entries reversed, period editable again.");
    }

    protected function entity(): ?Entity
    {
        return IfrsPosting::resolveEntity();
    }
}

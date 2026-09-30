<?php

namespace App\Http\Controllers;

use App\Services\FiscalYearService;
use App\Services\IfrsPosting;

/**
 * Renders the dashboard Blade screen for any authenticated, verified
 * user. The only data it computes is the year-end close nudge, shown
 * just to admins/accountants; the widget cards and their saved layout
 * flow through the widget system (Api\WidgetPreferenceController)
 * rather than this controller.
 */
class DashboardController extends Controller
{
    public function index()
    {
        // Year-end close nudge: the most recent ended FY that never got
        // closed (null when nothing needs attention). Only meaningful to
        // the roles allowed to run the close.
        $unclosedPriorYear = null;
        if (auth()->user()?->hasAnyRole('admin', 'accountant')) {
            $entity = IfrsPosting::resolveEntity();
            $unclosedPriorYear = $entity ? app(FiscalYearService::class)->unclosedPriorYear($entity) : null;
        }

        return view('dashboard', ['unclosedPriorYear' => $unclosedPriorYear]);
    }
}

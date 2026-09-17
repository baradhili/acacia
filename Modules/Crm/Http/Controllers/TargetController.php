<?php

namespace Modules\Crm\Http\Controllers;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Modules\Crm\Models\SalesTarget;

/**
 * Monthly sales targets and the funnel's progress against them.
 * Management territory — admin/accountant only (route middleware).
 */
class TargetController extends Controller
{
    public function index()
    {
        $targets = SalesTarget::orderByDesc('month')->paginate(12);

        return view('crm.targets.index', ['targets' => $targets]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'month' => ['required', 'date', function ($attribute, $value, $fail) {
                if (Carbon::parse($value)->day !== 1) {
                    $fail('The target month must be the first of a month.');
                }
            }],
            'amount' => ['required', 'numeric', 'min:0'],
        ]);

        SalesTarget::updateOrCreate(
            ['month' => Carbon::parse($validated['month'])->toDateString()],
            ['amount' => $validated['amount']],
        );

        return back()->with('success', 'Target saved for '.Carbon::parse($validated['month'])->format('M Y').'.');
    }
}

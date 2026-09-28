<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WidgetPreference;
use App\Support\WidgetLayout;
use App\Support\Widgets;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Persists a user's dashboard layout over the widget registry. The
 * full save (POST) writes the complete sequence edit mode captured —
 * grid widgets in drag order, then removed ones — as position_y
 * indices, plus width and visibility per widget, in one transaction,
 * and drops rows for widgets that left the registry (a disabled
 * module's). The single-widget patch (PUT) covers add/remove/resize
 * without a full save. Widget names are validated against the
 * registry so junk ids never become preference rows.
 */
class WidgetPreferenceController extends Controller
{
    public function __construct(protected Widgets $registry, protected WidgetLayout $layout) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'preferences' => WidgetPreference::getForUser($request->user()->id),
        ]);
    }

    /**
     * The removed widgets' cards, rendered for edit mode. The
     * dashboard page ships only the catalog rows (labels are cheap);
     * the cards themselves — with each widget's queries — are
     * fetched here on first entering edit mode, so hiding an
     * expensive widget actually removes its cost from dashboard
     * loads.
     */
    public function hidden(Request $request)
    {
        return view('dashboard.widget-store', [
            'hiddenWidgets' => $this->layout->hidden($request->user()),
        ]);
    }

    /**
     * Patch one widget (add/remove/resize) without a full save. Never
     * reorders: position_y is written only by saveAll, so a row this
     * creates stays NULL-ordered and the widget keeps its registry
     * slot — a re-added widget returns to its default place.
     */
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'widget_name' => ['required', 'string', Rule::in($this->registry->ids())],
            'visible' => ['nullable', 'boolean'],
            'width' => ['nullable', 'integer', 'min:0', 'max:4'],
            'collapsed' => ['nullable', 'boolean'],
        ]);

        WidgetPreference::updateForUser(
            $request->user()->id,
            $validated['widget_name'],
            collect($validated)->except('widget_name')->all(),
        );

        return response()->json([
            'success' => true,
            'message' => __('widgets.saved'),
        ]);
    }

    public function saveAll(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // list: keyed objects would smuggle string keys in as
            // positions; distinct: a duplicated name would write the
            // same row twice with different indices (last wins).
            'widgets' => ['required', 'array', 'list'],
            'widgets.*.widget_name' => ['required', 'string', 'distinct', Rule::in($this->registry->ids())],
            'widgets.*.visible' => ['required', 'boolean'],
            'widgets.*.width' => ['nullable', 'integer', 'min:0', 'max:4'],
        ]);

        // One transaction: a half-written sequence (some rows moved,
        // the stale-widget delete not yet run) is worse than the save
        // failing atomically and leaving the previous layout intact.
        DB::transaction(function () use ($validated, $request): void {
            $userId = $request->user()->id;

            foreach ($validated['widgets'] as $index => $widget) {
                WidgetPreference::updateOrCreate(
                    ['user_id' => $userId, 'widget_name' => $widget['widget_name']],
                    [
                        'position_x' => 0,
                        'position_y' => $index,
                        'width' => $widget['width'] ?? 0,
                        'visible' => $widget['visible'],
                    ],
                );
            }

            // Cleanup targets rows whose widget left the registry —
            // not rows the payload happened not to mention, so a
            // partial save can't revert the rest of the layout.
            WidgetPreference::where('user_id', $userId)
                ->whereNotIn('widget_name', $this->registry->ids())
                ->delete();
        });

        return response()->json([
            'success' => true,
            'message' => __('widgets.saved'),
        ]);
    }

    public function reset(Request $request): JsonResponse
    {
        WidgetPreference::where('user_id', $request->user()->id)->delete();

        return response()->json([
            'success' => true,
            'message' => __('widgets.reset_done'),
        ]);
    }
}

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
 * full save (POST) writes the sequence edit mode captured — grid
 * widgets in drag order, then removed ones — as position_y indices,
 * plus width and visibility per widget, in one transaction; it is
 * flagged complete=true by the browser client, whose payload is its
 * whole dashboard at save time (a widget registering between page
 * load and save simply rides at the tail rather than demoting the
 * save, and one that left and returned meanwhile is unplaced for the
 * same reason — a stale index from an earlier sequence would collide
 * with the new one). Without the flag the payload is a patch:
 * visibility/width only, ordering untouched. Either way, rows for
 * widgets that left the registry (a disabled module's) are dropped.
 * The single-widget patch (PUT) covers add/remove/resize without a
 * full save. Widget names are validated against the registry so junk
 * ids never become preference rows.
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
            'complete' => ['nullable', 'boolean'],
        ]);

        // One transaction: a half-written sequence (some rows moved,
        // the stale-widget delete not yet run) is worse than the save
        // failing atomically and leaving the previous layout intact.
        DB::transaction(function () use ($validated, $request): void {
            $userId = $request->user()->id;

            // complete=true declares the payload to be the client's
            // whole dashboard (grid plus removed widgets) and writes
            // the order over what it names — the browser client sends
            // it, so a widget registering between page load and save
            // (payload shorter than the registry) appends at the tail
            // instead of demoting the save to a patch that silently
            // drops the user's drag order. Without the flag the
            // payload is a patch: visibility/width only, positions
            // untouched — a patch's 0-based indices would collide
            // with positions of rows it never mentioned (ties fall
            // back to registry order, so its order would silently
            // not apply).
            $reorder = ($validated['complete'] ?? false) === true;
            $names = array_column($validated['widgets'], 'widget_name');

            foreach ($validated['widgets'] as $index => $widget) {
                $data = [
                    'position_x' => 0,
                    'width' => $widget['width'] ?? 0,
                    'visible' => $widget['visible'],
                ];
                if ($reorder) {
                    $data['position_y'] = $index;
                }
                // user_id is an unfillable FK — resolve the row manually
                // instead of updateOrCreate, which would drop it on create.
                $preference = WidgetPreference::where('user_id', $userId)
                    ->where('widget_name', $widget['widget_name'])
                    ->firstOrNew();
                $preference->fill($data);
                $preference->widget_name = $widget['widget_name'];
                $preference->user_id = $userId;
                $preference->save();
            }

            // A widget that left the registry while the page was open
            // and returned before this save (registered again, so the
            // cleanup below spares it) can still carry a position
            // from an earlier sequence — an index that now collides
            // with the one just written, making the dashboard render
            // an order the user never saved. Unplace it: it rides at
            // the tail in registry order, same as a widget the page
            // never placed. This keeps the invariant that every
            // non-NULL position after a complete save is unique.
            if ($reorder) {
                WidgetPreference::where('user_id', $userId)
                    ->whereIn('widget_name', $this->registry->ids())
                    ->whereNotIn('widget_name', $names)
                    ->update(['position_y' => null]);
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

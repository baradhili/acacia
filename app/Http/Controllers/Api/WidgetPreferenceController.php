<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WidgetPreference;
use App\Support\Widgets;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Persists a user's dashboard layout over the widget registry. The
 * full save (POST) writes the complete sequence edit mode captured —
 * grid widgets in drag order, then removed ones — as position_y
 * indices, plus width and visibility per widget, and drops rows for
 * widgets that left the registry (a disabled module's). The
 * single-widget patch (PUT) covers add/remove/resize without a full
 * save. Widget names are validated against the registry so junk ids
 * never become preference rows.
 */
class WidgetPreferenceController extends Controller
{
    public function __construct(protected Widgets $registry) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'preferences' => WidgetPreference::getForUser($request->user()->id),
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
            'widgets' => ['required', 'array'],
            'widgets.*.widget_name' => ['required', 'string', Rule::in($this->registry->ids())],
            'widgets.*.visible' => ['required', 'boolean'],
            'widgets.*.width' => ['nullable', 'integer', 'min:0', 'max:4'],
        ]);

        $userId = $request->user()->id;
        $names = [];

        foreach ($validated['widgets'] as $index => $widget) {
            $names[] = $widget['widget_name'];
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

        WidgetPreference::where('user_id', $userId)
            ->whereNotIn('widget_name', $names)
            ->delete();

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

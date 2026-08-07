<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WidgetPreference;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WidgetPreferenceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $preferences = WidgetPreference::getForUser($request->user()->id);
        
        return response()->json([
            'preferences' => $preferences,
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'widget_name' => 'required|string',
            'position_x' => 'nullable|integer|min:0',
            'position_y' => 'nullable|integer|min:0',
            'width' => 'nullable|integer|min:1|max:12',
            'height' => 'nullable|integer|min:1',
            'visible' => 'nullable|boolean',
            'collapsed' => 'nullable|boolean',
        ]);

        WidgetPreference::updateForUser(
            $request->user()->id,
            $validated['widget_name'],
            $validated
        );

        return response()->json([
            'success' => true,
            'message' => 'Widget preference saved',
        ]);
    }

    public function saveAll(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'widgets' => 'required|array',
            'widgets.*.widget_name' => 'required|string',
            'widgets.*.position_x' => 'required|integer|min:0',
            'widgets.*.position_y' => 'required|integer|min:0',
            'widgets.*.width' => 'required|integer|min:1|max:12',
            'widgets.*.height' => 'required|integer|min:1',
            'widgets.*.visible' => 'required|boolean',
            'widgets.*.collapsed' => 'nullable|boolean',
        ]);

        foreach ($validated['widgets'] as $widget) {
            WidgetPreference::updateForUser(
                $request->user()->id,
                $widget['widget_name'],
                $widget
            );
        }

        return response()->json([
            'success' => true,
            'message' => 'All widget preferences saved',
        ]);
    }

    public function reset(Request $request): JsonResponse
    {
        WidgetPreference::where('user_id', $request->user()->id)->delete();

        return response()->json([
            'success' => true,
            'message' => 'Widget preferences reset to defaults',
        ]);
    }
}

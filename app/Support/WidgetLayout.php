<?php

namespace App\Support;

use App\Models\User;
use App\Models\WidgetPreference;
use Illuminate\Support\Collection;

/**
 * Resolves a user's dashboard layout: the widget registry merged with
 * that user's widget_preferences rows. A row's position_y is its index
 * in the sequence the full save wrote (grid widgets first, then
 * removed ones) — NULL means no full save ever placed it (single-widget
 * patches leave it NULL), and the widget keeps its registry order.
 * Width is the column-span override (0 = the registry's shipped span);
 * visible=false is a widget removed through edit mode. Widgets without
 * a row at all also render in registry order, so a newly registered
 * widget shows up — never hides — until the user saves a layout.
 */
class WidgetLayout
{
    public function __construct(protected Widgets $registry) {}

    /** Visible widgets in render order, width overrides applied. */
    public function visible(User $user): array
    {
        return $this->merge($user)
            ->filter(fn (array $widget) => $widget['visible'])
            ->values()
            ->all();
    }

    /**
     * Removed widgets for the edit-mode catalog, in registry order —
     * their cards render into the hidden store so adding one back is
     * a DOM move, not a server round trip.
     */
    public function hidden(User $user): array
    {
        return $this->merge($user)
            ->reject(fn (array $widget) => $widget['visible'])
            ->values()
            ->all();
    }

    private function merge(User $user): Collection
    {
        $preferences = WidgetPreference::where('user_id', $user->id)
            ->get()
            ->keyBy('widget_name');

        return collect($this->registry->all())
            ->map(function (array $widget) use ($preferences) {
                $preference = $preferences->get($widget['id']);
                $width = $preference ? (int) $preference->width : 0;

                return [
                    ...$widget,
                    'visible' => $preference ? (bool) $preference->visible : true,
                    'width' => $width,
                    // Unplaced widgets sort behind every saved position,
                    // in registry order.
                    'sort' => $preference?->position_y !== null
                        ? (int) $preference->position_y
                        : 1000000 + $widget['position'],
                    'span' => $this->span($widget['span'], $width),
                    'default_span' => $widget['span'],
                ];
            })
            ->sortBy('sort')
            ->values();
    }

    /**
     * The card's grid classes for a width override. The grid is one
     * column on phones, two on md and four on lg; an override larger
     * than the breakpoint's column count clamps to full width there
     * (Tailwind's grid caps the span, the explicit class keeps the
     * intent readable in the markup).
     */
    private function span(string $default, int $width): string
    {
        return match ($width) {
            1 => 'md:col-span-1 lg:col-span-1',
            2 => 'md:col-span-2 lg:col-span-2',
            3 => 'md:col-span-2 lg:col-span-3',
            4 => 'md:col-span-2 lg:col-span-4',
            default => $default,
        };
    }
}

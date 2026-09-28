<?php

namespace App\Support;

/**
 * The dashboard widget registry — the core and modules alike add their
 * widget classes (with their grid span) from their registration points;
 * the dashboard renders the registry in order. Ids are the class
 * basename, which is what the drag-order preferences persist, so adding
 * widgets through the registry keeps user layouts stable.
 *
 * The default order (for viewers with no saved drag order) is by
 * position, ties in registration order — CoreNav's positions preserve
 * the shipped grid and leave slots for module widgets (Practice 30/110,
 * Taxation 40), which register earlier than the core does but land in
 * their shipped places.
 */
class Widgets
{
    protected array $widgets = [];

    public function add(string $class, string $span = '', int $position = 0): void
    {
        $this->widgets[$this->id($class)] = [
            'id' => $this->id($class),
            'class' => $class,
            'span' => $span,
            'position' => $position,
        ];
    }

    /** Registered widgets, position-ordered (registration order breaks ties). */
    public function all(): array
    {
        return collect($this->widgets)
            ->sortBy(fn (array $widget) => $widget['position'] ?? 0)
            ->values()
            ->all();
    }

    public function id(string $class): string
    {
        return basename(str_replace('\\', '/', $class));
    }
}

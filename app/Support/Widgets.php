<?php

namespace App\Support;

/**
 * The dashboard widget registry — the core and modules alike add their
 * widget classes (with their grid span) from their registration points;
 * WidgetLayout renders the registry (merged with the viewer's saved
 * preferences) and the edit-mode catalog lists every entry by label.
 * Ids are the class basename, which is what the layout preferences
 * persist, so adding widgets through the registry keeps user layouts
 * stable.
 *
 * The default order (for viewers with no saved layout) is by position,
 * ties in registration order — CoreNav's positions preserve the shipped
 * grid and leave slots for module widgets (Practice 30/110, Taxation
 * 40, Crm 130), which register earlier than the core does but land in
 * their shipped places.
 */
class Widgets
{
    protected array $widgets = [];

    /**
     * Register a widget. $label is a translation key (e.g.
     * 'widgets.labels.cash_flow') resolved at render time so the
     * registry itself stays locale-independent; it defaults to the id,
     * which renders untranslated — every shipped widget passes one.
     */
    public function add(string $class, string $span = '', int $position = 0, ?string $label = null): void
    {
        $this->widgets[$this->id($class)] = [
            'id' => $this->id($class),
            'class' => $class,
            'span' => $span,
            'position' => $position,
            'label' => $label ?? $this->id($class),
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

    /** Every registered widget id — the preference validation whitelist. */
    public function ids(): array
    {
        return array_keys($this->widgets);
    }

    public function id(string $class): string
    {
        return basename(str_replace('\\', '/', $class));
    }
}

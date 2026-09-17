<?php

namespace App\Support;

/**
 * The dashboard widget registry — modules add their widget classes
 * (with their grid span) from their service providers; the dashboard
 * renders the registry in order. Ids are the class basename, which is
 * what the drag-order preferences persist, so adding widgets through
 * the registry keeps user layouts stable.
 */
class Widgets
{
    protected array $widgets = [];

    public function add(string $class, string $span = ''): void
    {
        $this->widgets[$this->id($class)] = ['id' => $this->id($class), 'class' => $class, 'span' => $span];
    }

    /** Registered widgets in registration order. */
    public function all(): array
    {
        return array_values($this->widgets);
    }

    public function id(string $class): string
    {
        return basename(str_replace('\\', '/', $class));
    }
}

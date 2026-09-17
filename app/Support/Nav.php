<?php

namespace App\Support;

/**
 * The navigation registry — the contract modules use to put their
 * screens into the shell without touching core views. Core features
 * and module providers alike append items (App\Nav\CoreNav holds the
 * core's own entries); the sidebar and topbar views render whatever
 * the registry holds at render time, filtered to the viewer's roles.
 *
 * Item shapes (all keys beyond type optional except where noted):
 *  - Sidebar: {type: heading|link|divider, label, route, active (route
 *    patterns), icon (inner SVG markup), add (route), addTitle, roles,
 *    position}
 *  - Topbar: {type: dropdown|link, label, route, active, roles,
 *    position, children: [heading|link|divider items]}
 *
 * Roles are spatie role names; an item with no roles shows for every
 * authenticated user, matching the previous @hasanyrole guards.
 */
class Nav
{
    protected array $sidebar = [];

    protected array $topbar = [];

    public function addSidebar(array $items): void
    {
        $this->sidebar = array_merge($this->sidebar, $items);
    }

    public function addTopbar(array $items): void
    {
        $this->topbar = array_merge($this->topbar, $items);
    }

    /**
     * Slot a child into an existing topbar dropdown by label — how a
     * module adds one item to a core dropdown (PSI Assessment under
     * Setup) without owning the dropdown. The child's active-route
     * patterns join the dropdown's, keeping the button highlight.
     */
    public function addTopbarChild(string $dropdownLabel, array $child): void
    {
        foreach ($this->topbar as &$item) {
            if (($item['type'] ?? null) === 'dropdown' && ($item['label'] ?? null) === $dropdownLabel) {
                $item['children'][] = $child;
                $item['active'] = array_values(array_unique(array_merge(
                    (array) ($item['active'] ?? []),
                    (array) ($child['active'] ?? []),
                )));

                return;
            }
        }
        unset($item);
    }

    /** Role-filtered, position-ordered sidebar items. */
    public function sidebar(): array
    {
        return $this->visible($this->sorted($this->sidebar));
    }

    /** Role-filtered, position-ordered topbar items. */
    public function topbar(): array
    {
        return $this->visible($this->sorted($this->topbar));
    }

    protected function sorted(array $items): array
    {
        return collect($items)->sortBy(fn ($item) => $item['position'] ?? 0)->values()->all();
    }

    /**
     * Drop items the viewer lacks roles for, recursively for dropdown
     * children; a dropdown whose children all filter out goes too.
     */
    protected function visible(array $items): array
    {
        return collect($items)
            ->filter(fn ($item) => empty($item['roles']) || (auth()->user()?->hasAnyRole($item['roles']) ?? false))
            ->map(function ($item) {
                if (isset($item['children'])) {
                    $item['children'] = $this->visible($this->sorted($item['children']));
                }

                return $item;
            })
            ->filter(fn ($item) => $item['type'] !== 'dropdown' || ($item['children'] ?? []) !== [])
            ->values()
            ->all();
    }
}

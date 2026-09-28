import Sortable from 'sortablejs';

// The dashboard's edit mode. The server renders the viewer's saved
// layout (order, visibility, width) over the widget registry — visible
// cards into #widget-grid, removed cards into the hidden #widget-store
// with a catalog row each — so this file only manipulates DOM: drag
// order via Sortable, remove/add as node moves between grid and store,
// width as class swaps. Leaving edit mode (Done) persists the whole
// sequence to /api/widget-preferences, which reindexes it.
document.addEventListener('alpine:init', () => {
    Alpine.data('widgetManager', () => ({
        sortable: null,
        isEditing: false,
        saving: false,
        resetting: false,
        loading: false,
        saveError: false,
        loadError: false,
        storeLoaded: false,

        // Leaving edit mode persists first; when the save fails, edit
        // mode stays open so the toolbar error stays visible and the
        // staged layout survives for a retry (closing would silently
        // discard it on the next page load).
        async toggleEdit() {
            if (this.isEditing) {
                // Reset owns the wire while it runs — a save landing
                // after its DELETE would recreate the rows it just
                // cleared, and the reload would show the saved layout
                // instead of the defaults.
                if (this.resetting) {
                    return;
                }
                this.saving = true;
                const saved = await this.save();
                this.saving = false;

                if (!saved) {
                    this.saveError = true;
                    return;
                }

                this.isEditing = false;
                this.disableDragDrop();
                return;
            }

            // Entering edit mode is a load-then-open sequence; a
            // second Customize click while the store fetch is pending
            // must not start another one — its response would replace
            // the store after the user already removed a card
            // (discarding it while leaving its catalog row) and stack
            // a second Sortable instance only the last of which gets
            // cleaned up.
            if (this.loading) {
                return;
            }
            this.loading = true;
            this.saveError = false;
            this.loadError = false;
            try {
                if (!(await this.loadStore())) {
                    this.loadError = true;
                    return;
                }
                this.isEditing = true;
                this.enableDragDrop();
            } finally {
                this.loading = false;
            }
        },

        // Fetches the removed widgets' cards into #widget-store the
        // first time edit mode opens (the dashboard page ships only
        // the catalog rows, keeping hidden widgets' query cost off
        // regular loads). Once loaded, the session's own add/remove
        // moves keep the store current. Resolves whether the store is
        // ready to edit against.
        async loadStore() {
            if (this.storeLoaded) {
                return true;
            }
            try {
                const response = await fetch('/api/widget-preferences/hidden-widgets', {
                    headers: { Accept: 'text/html' },
                });
                if (!response.ok) {
                    throw new Error(`Store load failed: ${response.status}`);
                }
                document.getElementById('widget-store').innerHTML = await response.text();
                this.storeLoaded = true;
                return true;
            } catch (e) {
                console.error('Failed to load removed widgets:', e);
                return false;
            }
        },

        enableDragDrop() {
            const container = document.getElementById('widget-grid');
            if (!container) {
                return;
            }
            // A stale instance keeps its listeners around — only the
            // field reference would be replaced, so destroy first.
            this.disableDragDrop();
            this.sortable = Sortable.create(container, {
                animation: 150,
                handle: '.widget-handle',
                ghostClass: 'sortable-ghost',
            });
        },

        disableDragDrop() {
            if (this.sortable) {
                this.sortable.destroy();
                this.sortable = null;
            }
        },

        // Width cycle 0 (shipped span) → 2 → 4 → 0. The class mapping
        // mirrors WidgetLayout::span() server-side; 0 restores the
        // registry span captured in data-default-span.
        cycleWidth(card) {
            const width = Number(card.dataset.width || 0);
            card.dataset.width = String({ 0: 2, 2: 4, 4: 0 }[width] ?? 0);
            this.applySpan(card);
        },

        applySpan(card) {
            const width = Number(card.dataset.width || 0);
            const spans = {
                0: card.dataset.defaultSpan || '',
                1: 'md:col-span-1 lg:col-span-1',
                2: 'md:col-span-2 lg:col-span-2',
                3: 'md:col-span-2 lg:col-span-3',
                4: 'md:col-span-2 lg:col-span-4',
            };
            const stripped = card.className.replace(/(?:md|lg):col-span-\d+/g, '').replace(/\s+/g, ' ').trim();
            card.className = `${stripped} ${spans[width] ?? spans[0]}`.trim();
        },

        removeWidget(card) {
            document.getElementById('widget-store').appendChild(card);

            const row = document.getElementById('catalog-row-template').content.firstElementChild.cloneNode(true);
            row.dataset.widget = card.dataset.widget;
            row.querySelector('span').textContent = card.dataset.label;
            document.getElementById('widget-catalog-rows').appendChild(row);
            document.getElementById('catalog-empty').classList.add('hidden');
        },

        addWidget(row) {
            const card = document.querySelector(`#widget-store [data-widget="${row.dataset.widget}"]`);
            // No card yet (store still loading) — leave the row so
            // the user can retry once it lands.
            if (!card) {
                return;
            }
            document.getElementById('widget-grid').appendChild(card);
            row.remove();
            if (!document.querySelector('#widget-catalog-rows .catalog-entry')) {
                document.getElementById('catalog-empty').classList.remove('hidden');
            }
        },

        // Persists the current DOM sequence; resolves false (and
        // leaves reporting to the caller) when the request fails.
        async save() {
            const widgets = [
                ...document.querySelectorAll('#widget-grid .widget-card'),
                ...document.querySelectorAll('#widget-store .widget-card'),
            ].map((el) => ({
                widget_name: el.dataset.widget,
                visible: el.closest('#widget-grid') !== null,
                width: Number(el.dataset.width || 0),
            }));

            try {
                this.saving = true;
                const response = await fetch('/api/widget-preferences', {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                    },
                    // complete: this is the whole dashboard at save
                    // time (grid plus removed) — the server writes
                    // the order over what we send even when the
                    // registry grew a widget since page load.
                    body: JSON.stringify({ widgets, complete: true }),
                });
                if (!response.ok) {
                    throw new Error(`Save failed: ${response.status}`);
                }
                return true;
            } catch (e) {
                console.error('Failed to save dashboard layout:', e);
                return false;
            } finally {
                this.saving = false;
            }
        },

        // Reset waits out an in-flight save (and vice versa): the
        // save POST resolving after the reset DELETE would recreate
        // the rows the reset just cleared.
        async resetLayout(event) {
            if (this.saving || this.resetting) {
                return;
            }
            if (!confirm(event.currentTarget.dataset.confirm)) {
                return;
            }
            this.resetting = true;
            try {
                const response = await fetch('/api/widget-preferences/reset', {
                    method: 'DELETE',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
                        'Accept': 'application/json',
                    },
                });
                if (!response.ok) {
                    throw new Error(`Reset failed: ${response.status}`);
                }
                window.location.reload();
            } catch (e) {
                console.error('Failed to reset dashboard layout:', e);
                this.saveError = true;
            } finally {
                this.resetting = false;
            }
        },
    }));
});

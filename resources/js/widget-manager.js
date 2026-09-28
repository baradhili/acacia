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
        saveError: false,

        toggleEdit() {
            this.isEditing = !this.isEditing;

            if (this.isEditing) {
                this.saveError = false;
                this.enableDragDrop();
            } else {
                this.disableDragDrop();
                this.save();
            }
        },

        enableDragDrop() {
            const container = document.getElementById('widget-grid');
            if (!container) {
                return;
            }
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
            if (card) {
                document.getElementById('widget-grid').appendChild(card);
            }
            row.remove();
            if (!document.querySelector('#widget-catalog-rows .catalog-entry')) {
                document.getElementById('catalog-empty').classList.remove('hidden');
            }
        },

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
                this.saveError = false;
                const response = await fetch('/api/widget-preferences', {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({ widgets }),
                });
                if (!response.ok) {
                    throw new Error(`Save failed: ${response.status}`);
                }
            } catch (e) {
                console.error('Failed to save dashboard layout:', e);
                this.saveError = true;
            } finally {
                this.saving = false;
            }
        },

        async resetLayout(event) {
            if (!confirm(event.currentTarget.dataset.confirm)) {
                return;
            }
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
            }
        },
    }));
});

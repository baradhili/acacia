import Sortable from 'sortablejs';

document.addEventListener('alpine:init', () => {
    Alpine.data('widgetManager', () => ({
        sortable: null,
        isEditing: false,
        preferences: {},

        init() {
            this.loadPreferences();
            this.applySavedOrder();
        },

        toggleEdit() {
            this.isEditing = !this.isEditing;
            
            if (this.isEditing) {
                this.enableDragDrop();
                document.querySelectorAll('.widget-card').forEach(el => {
                    el.classList.add('ring-2', 'ring-blue-400', 'cursor-move');
                });
            } else {
                this.disableDragDrop();
                document.querySelectorAll('.widget-card').forEach(el => {
                    el.classList.remove('ring-2', 'ring-blue-400', 'cursor-move');
                });
                this.saveOrder();
            }
        },

        enableDragDrop() {
            const container = document.getElementById('widget-grid');
            this.sortable = Sortable.create(container, {
                animation: 150,
                handle: '.widget-handle',
                ghostClass: 'opacity-50',
                onEnd: (evt) => {
                    this.captureOrder();
                }
            });
        },

        disableDragDrop() {
            if (this.sortable) {
                this.sortable.destroy();
                this.sortable = null;
            }
        },

        captureOrder() {
            const widgets = document.querySelectorAll('.widget-card');
            const order = [];
            widgets.forEach((el, index) => {
                const name = el.dataset.widget;
                order.push(name);
                this.preferences[name] = { order: index, ...this.preferences[name] };
            });
        },

        applySavedOrder() {
            const savedOrder = localStorage.getItem('dashboard_widget_order');
            if (savedOrder) {
                const order = JSON.parse(savedOrder);
                const container = document.getElementById('widget-grid');
                order.forEach(name => {
                    const el = container.querySelector(`[data-widget="${name}"]`);
                    if (el) {
                        container.appendChild(el);
                    }
                });
            }
        },

        saveOrder() {
            const widgets = document.querySelectorAll('.widget-card');
            const order = Array.from(widgets).map(el => el.dataset.widget);
            localStorage.setItem('dashboard_widget_order', JSON.stringify(order));
            
            // Also save to server
            this.saveToServer(order);
        },

        async saveToServer(order) {
            try {
                await fetch('/api/widget-preferences', {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({ order }),
                });
            } catch (e) {
                console.error('Failed to save widget order:', e);
            }
        },

        resetLayout() {
            if (confirm('Reset widget layout to defaults?')) {
                localStorage.removeItem('dashboard_widget_order');
                location.reload();
            }
        },

        async loadPreferences() {
            try {
                const response = await fetch('/api/widget-preferences', {
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
                    },
                });
                const data = await response.json();
                if (data.preferences) {
                    this.preferences = data.preferences;
                }
            } catch (e) {
                console.error('Failed to load preferences:', e);
            }
        },
    }));
});

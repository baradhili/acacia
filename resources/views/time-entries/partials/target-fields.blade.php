@php($entryModel = $timeEntry ?? null)

<div>
    <label for="project_id" class="block text-sm font-medium text-gray-700">Project *</label>
    <select name="project_id" id="project_id" required
        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
        <option value="">Select Project</option>
        @foreach($projects as $project)
            <option value="{{ $project->id }}" data-client="{{ $project->client?->name }}"
                data-po="{{ $project->purchaseOrder?->po_number }}"
                {{ old('project_id', $entryModel?->project_id) == $project->id ? 'selected' : '' }}>
                {{ $project->name }} ({{ $project->client?->name }})
            </option>
        @endforeach
    </select>
    @error('project_id')
        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
    @enderror
</div>

<div>
    <label class="block text-sm font-medium text-gray-700">Client</label>
    <div id="clientDisplay"
        class="mt-1 block w-full rounded-md border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-700">—</div>
    <p class="mt-1 text-sm text-gray-500">From the selected project — time is always booked against a project</p>
</div>

<div>
    <label class="block text-sm font-medium text-gray-700">Purchase Order</label>
    <div id="poDisplay"
        class="mt-1 block w-full rounded-md border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-700">—</div>
    <p class="mt-1 text-sm text-gray-500">From the selected project</p>
</div>

@push('scripts')
    <script>
        // The project drives everything: the client and purchase order
        // displays follow the selection (the server derives and stores
        // both from the project via TimeEntry's saving hook).
        (function () {
            const projectSelect = document.getElementById('project_id');
            const clientDisplay = document.getElementById('clientDisplay');
            const poDisplay = document.getElementById('poDisplay');
            if (!projectSelect || !clientDisplay || !poDisplay) return;

            function syncDisplays() {
                const option = projectSelect.selectedOptions[0];
                clientDisplay.textContent = option && option.dataset.client ? option.dataset.client : '—';
                poDisplay.textContent = option && option.dataset.po ? option.dataset.po : '—';
            }

            projectSelect.addEventListener('change', syncDisplays);
            syncDisplays();
        })();
    </script>
@endpush

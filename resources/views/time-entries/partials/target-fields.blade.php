@php($entryModel = $timeEntry ?? null)

<div>
    <label for="client_id" class="block text-sm font-medium text-gray-700">Client</label>
    <select name="client_id" id="client_id"
        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
        <option value="">No client (internal time)</option>
        @foreach($clients as $id => $name)
            <option value="{{ $id }}" {{ old('client_id', $entryModel?->client_id) == $id ? 'selected' : '' }}>{{ $name }}</option>
        @endforeach
    </select>
    <p class="mt-1 text-sm text-gray-500">For time worked directly for a client outside any project — filled in automatically when a project is chosen</p>
    @error('client_id')
        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
    @enderror
</div>

<div>
    <label for="project_id" class="block text-sm font-medium text-gray-700">Project</label>
    <select name="project_id" id="project_id"
        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
        <option value="">Select Project</option>
        @foreach($projects as $project)
            <option value="{{ $project->id }}" data-client-id="{{ $project->client_id }}"
                {{ old('project_id', $entryModel?->project_id) == $project->id ? 'selected' : '' }}>{{ $project->name }}</option>
        @endforeach
    </select>
</div>

<div>
    <label for="purchase_order_id" class="block text-sm font-medium text-gray-700">Purchase Order</label>
    <select name="purchase_order_id" id="purchase_order_id"
        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
        <option value="">Select PO</option>
        @foreach($purchaseOrders as $id => $poNumber)
            <option value="{{ $id }}" {{ old('purchase_order_id', $entryModel?->purchase_order_id) == $id ? 'selected' : '' }}>{{ $poNumber }}</option>
        @endforeach
    </select>
</div>

@push('scripts')
    <script>
        // Picking a project fills the client from the project (the server
        // enforces the same precedence via TimeEntry's saving hook).
        (function () {
            const projectSelect = document.getElementById('project_id');
            const clientSelect = document.getElementById('client_id');
            if (!projectSelect || !clientSelect) return;

            projectSelect.addEventListener('change', function () {
                const option = projectSelect.selectedOptions[0];
                const clientId = option ? option.dataset.clientId : null;
                if (clientId) {
                    clientSelect.value = clientId;
                }
            });
        })();
    </script>
@endpush

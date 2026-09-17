@extends('layouts.app')
@section('title', 'Create Estimate')
@section('content')

    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-800">Create Estimate</h1>
    </div>

    <form action="{{ route('estimates.store') }}" method="POST" class="space-y-6">
        @csrf

        <div class="bg-white rounded-lg shadow p-6">
            <h2 class="text-lg font-semibold text-gray-800 mb-4">Client & Project</h2>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Client *</label>
                    <select name="client_id" id="clientSelect" required
                        class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 w-full">
                        <option value="">Select Client</option>
                        @foreach($clients as $id => $name)
                            <option value="{{ $id }}" {{ old('client_id', $selectedClient?->id) == $id ? 'selected' : '' }}>{{ $name }}</option>
                        @endforeach
                    </select>
                    @error('client_id')
                        <p class="text-red-500 text-sm mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Project</label>
                    <select name="project_id" id="projectSelect"
                        class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 w-full">
                        <option value="">Select Project (optional)</option>
                        @php
                            $allProjects = App\Models\Project::with('client')->orderBy('name')->get();
                        @endphp
                        @foreach($allProjects as $project)
                            <option value="{{ $project->id }}"
                                data-client-id="{{ $project->client_id }}"
                                {{ old('project_id', $selectedProject?->id) == $project->id ? 'selected' : '' }}>
                                {{ $project->name }} ({{ $project->client->name ?? 'No client' }})
                            </option>
                        @endforeach
                    </select>
                    @error('project_id')
                        <p class="text-red-500 text-sm mt-1">{{ $message }}</p>
                    @enderror
                </div>
            </div>
        </div>

        <div class="bg-white rounded-lg shadow p-6">
            <h2 class="text-lg font-semibold text-gray-800 mb-4">Estimate Details</h2>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Issue Date *</label>
                    <input type="date" name="issue_date" value="{{ old('issue_date', now()->toDateString()) }}" required
                        class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 w-full">
                    @error('issue_date')
                        <p class="text-red-500 text-sm mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Valid Until *</label>
                    <input type="date" name="valid_until" value="{{ old('valid_until', now()->addDays(30)->toDateString()) }}" required
                        class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 w-full">
                    @error('valid_until')
                        <p class="text-red-500 text-sm mt-1">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            <div class="mt-4">
                <label class="block text-sm font-medium text-gray-700 mb-1">Notes</label>
                <textarea name="notes" rows="2"
                    class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 w-full">{{ old('notes') }}</textarea>
            </div>

            <div class="mt-4">
                <label class="block text-sm font-medium text-gray-700 mb-1">Terms & Conditions</label>
                <textarea name="terms" rows="2"
                    class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 w-full">{{ old('terms', config('australian.estimate_terms', 'This estimate is valid for 30 days from the issue date.')) }}</textarea>
            </div>
        </div>

        <div class="bg-white rounded-lg shadow p-6">
            <h2 class="text-lg font-semibold text-gray-800 mb-4">Line Items</h2>

            <div id="itemsContainer">
                @php $items = old('items', [[]]); @endphp
                @php
                    // Next row index past the highest submitted key —
                    // removed rows leave gaps in old('items'), so count()
                    // could collide with a surviving key.
                    $nextIndex = $items !== [] ? max(array_keys($items)) + 1 : 0;
                @endphp
                @foreach($items as $index => $item)
                    <div class="item-row mb-4 p-4 bg-gray-50 rounded-lg">
                        <div class="grid grid-cols-12 gap-2 mb-2">
                            <div class="col-span-4">
                                <label class="block text-xs font-medium text-gray-700 mb-1">Section <span class="font-normal text-gray-400">(groups lines)</span></label>
                                <input type="text" name="items[{{ $index }}][section]"
                                    value="{{ $item['section'] ?? '' }}" maxlength="100"
                                    class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 w-full text-sm"
                                    placeholder="e.g. Discovery, Build">
                            </div>
                            <div class="col-span-5">
                                <label class="block text-xs font-medium text-gray-700 mb-1">Catalogue service</label>
                                <select name="items[{{ $index }}][service_id]" class="service-select rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 w-full text-sm">
                                    <option value="">— free text —</option>
                                    @foreach($services as $service)
                                        <option value="{{ $service->id }}" data-name="{{ $service->name }}" data-rate="{{ $service->hourly_rate }}"
                                            {{ (string) ($item['service_id'] ?? '') === (string) $service->id ? 'selected' : '' }}>
                                            {{ $service->name }}@if($service->hourly_rate !== null) — {{ $service->formattedRate() }}/hr @endif
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-span-3 flex items-end justify-end pb-1">
                                <label class="flex items-center gap-2 text-xs font-medium text-gray-700"
                                    title="Quoted as an extra outside the committed total — only invoiced when chosen at conversion">
                                    <input type="checkbox" name="items[{{ $index }}][is_optional]" value="1"
                                        {{ ! empty($item['is_optional']) ? 'checked' : '' }}
                                        class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 optional-input">
                                    Optional extra
                                </label>
                            </div>
                        </div>
                        <div class="grid grid-cols-12 gap-2">
                            <div class="col-span-4">
                                <label class="block text-xs font-medium text-gray-700 mb-1">Description</label>
                                <input type="text" name="items[{{ $index }}][description]"
                                    value="{{ $item['description'] ?? '' }}" required
                                    class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 w-full text-sm"
                                    placeholder="Service description">
                            </div>
                            <div class="col-span-2">
                                <label class="block text-xs font-medium text-gray-700 mb-1">Qty</label>
                                <input type="number" name="items[{{ $index }}][quantity]"
                                    value="{{ $item['quantity'] ?? 1 }}" step="0.01" min="0" required
                                    class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 w-full text-sm quantity-input">
                            </div>
                            <div class="col-span-2">
                                <label class="block text-xs font-medium text-gray-700 mb-1">Unit Price</label>
                                <input type="number" name="items[{{ $index }}][unit_price]"
                                    value="{{ $item['unit_price'] ?? 0 }}" step="0.01" min="0" required
                                    class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 w-full text-sm unit-price-input">
                            </div>
                            <div class="col-span-1">
                                <label class="block text-xs font-medium text-gray-700 mb-1">Tax %</label>
                                <input type="number" name="items[{{ $index }}][tax_rate]"
                                    value="{{ $item['tax_rate'] ?? 10 }}" step="0.01" min="0" max="100"
                                    class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 w-full text-sm">
                            </div>
                            <div class="col-span-1">
                                <label class="block text-xs font-medium text-gray-700 mb-1">Disc %</label>
                                <input type="number" name="items[{{ $index }}][discount_percent]"
                                    value="{{ $item['discount_percent'] ?? 0 }}" step="0.01" min="0" max="100"
                                    class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 w-full text-sm">
                            </div>
                            <div class="col-span-2">
                                <label class="block text-xs font-medium text-gray-700 mb-1">Total</label>
                                <div class="text-sm font-medium text-gray-900 pt-5 line-total">$0.00</div>
                                <button type="button" class="remove-item text-red-600 hover:text-red-800 text-xs mt-1">Remove</button>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>

            <button type="button" id="addItemBtn" class="mt-4 bg-gray-100 hover:bg-gray-200 text-gray-700 px-4 py-2 rounded-lg text-sm">
                + Add Line Item
            </button>
        </div>

        <div class="flex justify-end gap-4">
            <a href="{{ route('estimates.index') }}" class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-6 py-2 rounded-lg">
                Cancel
            </a>
            <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white px-6 py-2 rounded-lg">
                Create Estimate
            </button>
        </div>
    </form>

    <template id="itemTemplate">
        <div class="item-row mb-4 p-4 bg-gray-50 rounded-lg">
            <div class="grid grid-cols-12 gap-2 mb-2">
                <div class="col-span-4">
                    <label class="block text-xs font-medium text-gray-700 mb-1">Section <span class="font-normal text-gray-400">(groups lines)</span></label>
                    <input type="text" name="items[__INDEX__][section]" maxlength="100"
                        class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 w-full text-sm"
                        placeholder="e.g. Discovery, Build">
                </div>
                <div class="col-span-5">
                    <label class="block text-xs font-medium text-gray-700 mb-1">Catalogue service</label>
                    <select name="items[__INDEX__][service_id]" class="service-select rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 w-full text-sm">
                        <option value="">— free text —</option>
                        @foreach($services as $service)
                            <option value="{{ $service->id }}" data-name="{{ $service->name }}" data-rate="{{ $service->hourly_rate }}">
                                {{ $service->name }}@if($service->hourly_rate !== null) — {{ $service->formattedRate() }}/hr @endif
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-span-3 flex items-end justify-end pb-1">
                    <label class="flex items-center gap-2 text-xs font-medium text-gray-700"
                        title="Quoted as an extra outside the committed total — only invoiced when chosen at conversion">
                        <input type="checkbox" name="items[__INDEX__][is_optional]" value="1"
                            class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 optional-input">
                        Optional extra
                    </label>
                </div>
            </div>
            <div class="grid grid-cols-12 gap-2">
                <div class="col-span-4">
                    <label class="block text-xs font-medium text-gray-700 mb-1">Description</label>
                    <input type="text" name="items[__INDEX__][description]" required
                        class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 w-full text-sm"
                        placeholder="Service description">
                </div>
                <div class="col-span-2">
                    <label class="block text-xs font-medium text-gray-700 mb-1">Qty</label>
                    <input type="number" name="items[__INDEX__][quantity]" value="1" step="0.01" min="0" required
                        class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 w-full text-sm quantity-input">
                </div>
                <div class="col-span-2">
                    <label class="block text-xs font-medium text-gray-700 mb-1">Unit Price</label>
                    <input type="number" name="items[__INDEX__][unit_price]" value="0" step="0.01" min="0" required
                        class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 w-full text-sm unit-price-input">
                </div>
                <div class="col-span-1">
                    <label class="block text-xs font-medium text-gray-700 mb-1">Tax %</label>
                    <input type="number" name="items[__INDEX__][tax_rate]" value="10" step="0.01" min="0" max="100"
                        class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 w-full text-sm">
                </div>
                <div class="col-span-1">
                    <label class="block text-xs font-medium text-gray-700 mb-1">Disc %</label>
                    <input type="number" name="items[__INDEX__][discount_percent]" value="0" step="0.01" min="0" max="100"
                        class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 w-full text-sm">
                </div>
                <div class="col-span-2">
                    <label class="block text-xs font-medium text-gray-700 mb-1">Total</label>
                    <div class="text-sm font-medium text-gray-900 pt-5 line-total">$0.00</div>
                    <button type="button" class="remove-item text-red-600 hover:text-red-800 text-xs mt-1">Remove</button>
                </div>
            </div>
        </div>
    </template>

    <script>
        let itemIndex = {{ $nextIndex }};

        document.getElementById('addItemBtn').addEventListener('click', function() {
            const container = document.getElementById('itemsContainer');
            const template = document.getElementById('itemTemplate').innerHTML;

            // Skip any index a surviving row still uses (sparse old()
            // keys or rows re-added after removal).
            while (container.querySelector('[name="items[' + itemIndex + '][description]"]') !== null) {
                itemIndex++;
            }

            const html = template.replace(/__INDEX__/g, itemIndex);
            container.insertAdjacentHTML('beforeend', html);
            itemIndex++;
            attachEventListeners();
        });

        function attachEventListeners() {
            document.querySelectorAll('.item-row').forEach(row => {
                const qtyInput = row.querySelector('.quantity-input');
                const priceInput = row.querySelector('.unit-price-input');
                const taxInput = row.querySelector('input[name*="[tax_rate]"]');
                const discInput = row.querySelector('input[name*="[discount_percent]"]');
                const totalDiv = row.querySelector('.line-total');

                function updateTotal() {
                    const qty = parseFloat(qtyInput.value) || 0;
                    const price = parseFloat(priceInput.value) || 0;
                    const tax = parseFloat(taxInput.value) || 0;
                    const disc = parseFloat(discInput.value) || 0;

                    const subtotal = qty * price;
                    const discountAmount = subtotal * (disc / 100);
                    const afterDiscount = subtotal - discountAmount;
                    const total = afterDiscount * (1 + tax / 100);

                    const optional = row.querySelector('.optional-input');
                    totalDiv.textContent = '$' + total.toFixed(2) + (optional && optional.checked ? ' (optional)' : '');
                    totalDiv.classList.toggle('text-amber-700', !!optional && optional.checked);
                }

                [qtyInput, priceInput, taxInput, discInput].forEach(input => {
                    if (input) input.addEventListener('input', updateTotal);
                });

                const optionalInput = row.querySelector('.optional-input');
                if (optionalInput) optionalInput.addEventListener('change', updateTotal);

                updateTotal();
            });

            document.querySelectorAll('.remove-item').forEach(btn => {
                btn.addEventListener('click', function() {
                    this.closest('.item-row').remove();
                });
            });
        }

        attachEventListeners();

        // Selecting a catalogue service pre-fills the blank fields: the
        // description with the service name (dataset.name — the visible
        // label interleaves the rate, so it must not be parsed), the
        // unit price with its standard rate (fixed-fee services carry
        // no rate).
        document.getElementById('itemsContainer').addEventListener('change', function (event) {
            if (!event.target.matches('.service-select')) return;

            const option = event.target.selectedOptions[0];
            if (!option || !option.value) return;

            const row = event.target.closest('.item-row');
            const description = row.querySelector('input[name*="[description]"]');
            if (description && !description.value.trim() && option.dataset.name) {
                description.value = option.dataset.name;
            }

            const rate = parseFloat(option.dataset.rate);
            const price = row.querySelector('.unit-price-input');
            if (!Number.isNaN(rate) && price && !(parseFloat(price.value) > 0)) {
                price.value = rate;
                price.dispatchEvent(new Event('input', { bubbles: true }));
            }
        });

        // Auto-select client when project is selected
        document.getElementById('projectSelect').addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            const clientId = selectedOption.dataset.clientId;
            if (clientId) {
                document.getElementById('clientSelect').value = clientId;
            }
        });
    </script>

@endsection

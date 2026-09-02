@php
    $gstRate = (float) config('australian.gst.rate', 10);
    $dueDays = (int) config('australian.invoice.due_days', 30);
@endphp

<div class="bg-white rounded-lg shadow p-6">
    <div class="flex justify-between items-center mb-4">
        <h2 class="text-lg font-semibold text-gray-800">Unbilled Time Entries</h2>
        <label class="flex items-center gap-2 text-sm text-gray-600">
            <input type="checkbox" id="selectAllEntries"
                class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
            Select all
        </label>
    </div>

    @if ($timeEntries->isEmpty())
        <p class="text-gray-500 text-center py-4">No uninvoiced approved time entries.</p>
    @else
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-3 py-3 w-10"></th>
                        <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                        <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase">Staff</th>
                        <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase">Client / Project</th>
                        <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase">Description</th>
                        <th class="px-3 py-3 text-right text-xs font-medium text-gray-500 uppercase">Hours</th>
                        <th class="px-3 py-3 text-right text-xs font-medium text-gray-500 uppercase">Rate</th>
                        <th class="px-3 py-3 text-right text-xs font-medium text-gray-500 uppercase">Amount</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    @foreach ($timeEntries as $entry)
                        <tr>
                            <td class="px-3 py-3">
                                <input type="checkbox" name="time_entry_ids[]" value="{{ $entry->id }}"
                                    data-amount="{{ $entry->hours * $entry->effective_rate }}"
                                    class="entry-checkbox rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                    {{ in_array($entry->id, old('time_entry_ids', [])) ? 'checked' : '' }}>
                            </td>
                            <td class="px-3 py-3 text-sm text-gray-900 whitespace-nowrap">{{ $entry->entry_date?->format('d M Y') ?? '-' }}</td>
                            <td class="px-3 py-3 text-sm text-gray-900">{{ $entry->user?->name ?? '-' }}</td>
                            <td class="px-3 py-3 text-sm text-gray-900">
                                {{ $entry->client?->name ?? $entry->project?->client?->name ?? '-' }}
                                @if ($entry->project)
                                    <span class="text-gray-400">/</span> {{ $entry->project->name }}
                                @endif
                            </td>
                            <td class="px-3 py-3 text-sm text-gray-900">{{ Str::limit($entry->description, 50) }}</td>
                            <td class="px-3 py-3 text-sm text-right text-gray-900">{{ number_format($entry->hours, 2) }}</td>
                            <td class="px-3 py-3 text-sm text-right text-gray-900">${{ number_format($entry->effective_rate, 2) }}</td>
                            <td class="px-3 py-3 text-sm text-right font-medium text-gray-900">${{ number_format($entry->hours * $entry->effective_rate, 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @error('time_entry_ids')
            <p class="text-red-500 text-sm mt-2">{{ $message }}</p>
        @enderror
    @endif
</div>

<div class="bg-white rounded-lg shadow p-6">
    <h2 class="text-lg font-semibold text-gray-800 mb-4">Invoice Details</h2>

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
            <label class="block text-sm font-medium text-gray-700 mb-1">Due Date *</label>
            <input type="date" name="due_date" value="{{ old('due_date', now()->addDays($dueDays)->toDateString()) }}"
                required
                class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 w-full">
            @error('due_date')
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
            class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 w-full">{{ old('terms', config('australian.invoice.terms')) }}</textarea>
    </div>

    <div class="mt-6 flex justify-end gap-8 border-t border-gray-200 pt-4">
        <div class="text-right">
            <p class="text-sm text-gray-500">Subtotal</p>
            <p class="text-lg font-semibold text-gray-800" id="pickerSubtotal">$0.00</p>
        </div>
        <div class="text-right">
            <p class="text-sm text-gray-500">GST ({{ rtrim(rtrim(number_format($gstRate, 1), '0'), '.') }}%)</p>
            <p class="text-lg font-semibold text-gray-800" id="pickerTax">$0.00</p>
        </div>
        <div class="text-right">
            <p class="text-sm text-gray-500">Total</p>
            <p class="text-lg font-bold text-indigo-600" id="pickerTotal">$0.00</p>
        </div>
    </div>
</div>

@push('scripts')
    <script>
        (function() {
            const gstRate = {{ $gstRate }};
            const checkboxes = () => Array.from(document.querySelectorAll('.entry-checkbox'));

            function recalc() {
                const subtotal = checkboxes()
                    .filter(cb => cb.checked)
                    .reduce((sum, cb) => sum + parseFloat(cb.dataset.amount || 0), 0);
                const tax = subtotal * (gstRate / 100);

                document.getElementById('pickerSubtotal').textContent = '$' + subtotal.toFixed(2);
                document.getElementById('pickerTax').textContent = '$' + tax.toFixed(2);
                document.getElementById('pickerTotal').textContent = '$' + (subtotal + tax).toFixed(2);
            }

            document.querySelectorAll('.entry-checkbox').forEach(cb => cb.addEventListener('change', function() {
                recalc();
                const all = checkboxes();
                document.getElementById('selectAllEntries').checked =
                    all.length > 0 && all.every(cb => cb.checked);
            }));

            const selectAll = document.getElementById('selectAllEntries');
            if (selectAll) {
                selectAll.addEventListener('change', function() {
                    checkboxes().forEach(cb => cb.checked = this.checked);
                    recalc();
                });
            }

            recalc();
        })();
    </script>
@endpush

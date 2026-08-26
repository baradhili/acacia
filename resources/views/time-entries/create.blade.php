@extends('layouts.app')
@section('title', 'Create Time Entry')
@section('content')

    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-800">Create Time Entry</h1>
    </div>

    <div class="bg-white rounded-lg shadow p-6">
        <form action="{{ route('time-entries.store') }}" method="POST">
            @csrf

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <label for="project_id" class="block text-sm font-medium text-gray-700">Project</label>
                    <select name="project_id" id="project_id"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="">Select Project</option>
                        @foreach($projects as $id => $name)
                            <option value="{{ $id }}" {{ old('project_id') == $id ? 'selected' : '' }}>{{ $name }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="purchase_order_id" class="block text-sm font-medium text-gray-700">Purchase Order</label>
                    <select name="purchase_order_id" id="purchase_order_id"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="">Select PO</option>
                        @foreach($purchaseOrders as $id => $poNumber)
                            <option value="{{ $id }}" {{ old('purchase_order_id') == $id ? 'selected' : '' }}>{{ $poNumber }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="entry_date" class="block text-sm font-medium text-gray-700">Date *</label>
                    <input type="date" name="entry_date" id="entry_date" value="{{ old('entry_date', now()->toDateString()) }}" required
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                    @error('entry_date')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="hours" class="block text-sm font-medium text-gray-700">Hours *</label>
                    <input type="number" name="hours" id="hours" value="{{ old('hours') }}" step="0.01" min="0" max="24" required
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                    <p class="mt-1 text-sm text-gray-500">Entered manually — filled in automatically when start/end times are set</p>
                    @error('hours')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="start_time" class="block text-sm font-medium text-gray-700">Start Time</label>
                    <input type="time" name="start_time" id="start_time" value="{{ old('start_time') }}"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                    <p class="mt-1 text-sm text-gray-500">Optional</p>
                    @error('start_time')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="end_time" class="block text-sm font-medium text-gray-700">End Time</label>
                    <input type="time" name="end_time" id="end_time" value="{{ old('end_time') }}"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                    <p class="mt-1 text-sm text-gray-500">Optional — hours are derived from the times when both are set</p>
                    @error('end_time')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            <div class="mt-6">
                <div class="flex items-center justify-between mb-2">
                    <label class="block text-sm font-medium text-gray-700">Breaks</label>
                    <button type="button" id="addBreakBtn"
                        class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-3 py-1 rounded-lg text-sm">
                        + Add Break
                    </button>
                </div>
                <p class="text-sm text-gray-500 mb-2">Unpaid breaks within the start/end times (e.g. lunch), deducted from the hours</p>
                <div id="breaksContainer" class="space-y-2">
                    @php $oldBreaks = old('breaks', []); @endphp
                    @foreach ($oldBreaks as $i => $break)
                        <div class="break-row flex items-center gap-3">
                            <input type="time" name="breaks[{{ $i }}][start]" value="{{ $break['start'] ?? '' }}"
                                class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            <span class="text-gray-500">to</span>
                            <input type="time" name="breaks[{{ $i }}][end]" value="{{ $break['end'] ?? '' }}"
                                class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            <button type="button" class="remove-break text-red-600 hover:text-red-800 text-sm">Remove</button>
                        </div>
                    @endforeach
                </div>
                @error('breaks')
                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div class="mt-6">
                <label for="rate" class="block text-sm font-medium text-gray-700">Hourly Rate ($)</label>
                <input type="number" name="rate" id="rate" value="{{ old('rate') }}" step="0.01" min="0"
                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                <p class="mt-1 text-sm text-gray-500">Leave empty to use project default</p>
            </div>

            <div class="mt-6 flex items-center">
                <input type="checkbox" name="billable" id="billable" value="1" {{ old('billable', '1') ? 'checked' : '' }}
                    class="h-4 w-4 text-indigo-600 focus:ring-indigo-500 border-gray-300 rounded">
                <label for="billable" class="ml-2 block text-sm text-gray-700">Billable</label>
            </div>

            <div class="mt-6">
                <label for="description" class="block text-sm font-medium text-gray-700">Description</label>
                <textarea name="description" id="description" rows="3"
                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">{{ old('description') }}</textarea>
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <a href="{{ route('time-entries.index') }}" class="bg-gray-300 hover:bg-gray-400 text-gray-800 px-4 py-2 rounded-lg">
                    Cancel
                </a>
                <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg">
                    Create Time Entry
                </button>
            </div>
        </form>
    </div>

    <template id="breakTemplate">
        <div class="break-row flex items-center gap-3">
            <input type="time" name="breaks[__INDEX__][start]"
                class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
            <span class="text-gray-500">to</span>
            <input type="time" name="breaks[__INDEX__][end]"
                class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
            <button type="button" class="remove-break text-red-600 hover:text-red-800 text-sm">Remove</button>
        </div>
    </template>

    @push('scripts')
        <script>
            let breakIndex = {{ max(count(old('breaks', [])), 0) }};

            document.getElementById('addBreakBtn').addEventListener('click', function() {
                const container = document.getElementById('breaksContainer');
                const html = document.getElementById('breakTemplate').innerHTML.replace(/__INDEX__/g, breakIndex);
                container.insertAdjacentHTML('beforeend', html);
                breakIndex++;
            });

            document.getElementById('breaksContainer').addEventListener('click', function(e) {
                if (e.target.classList.contains('remove-break')) {
                    e.target.closest('.break-row').remove();
                    syncHours();
                }
            });

            // Hours: manual by default; when both start and end times are
            // set the field is derived (span minus breaks) and read-only.
            const hoursInput = document.getElementById('hours');
            const startInput = document.getElementById('start_time');
            const endInput = document.getElementById('end_time');

            function toMinutes(v) {
                const [h, m] = (v || '').split(':').map(Number);
                return isNaN(h) ? null : h * 60 + (m || 0);
            }

            function syncHours() {
                const start = toMinutes(startInput.value);
                const end = toMinutes(endInput.value);

                if (start === null || end === null || end <= start) {
                    hoursInput.readOnly = false;
                    return;
                }

                let breakMinutes = 0;
                document.querySelectorAll('.break-row').forEach(row => {
                    const bStart = toMinutes(row.querySelector('input[name$="[start]"]').value);
                    const bEnd = toMinutes(row.querySelector('input[name$="[end]"]').value);
                    if (bStart !== null && bEnd !== null && bEnd > bStart) {
                        breakMinutes += bEnd - bStart;
                    }
                });

                hoursInput.value = ((end - start - breakMinutes) / 60).toFixed(2);
                hoursInput.readOnly = true;
            }

            [startInput, endInput].forEach(el => el.addEventListener('input', syncHours));
            document.getElementById('breaksContainer').addEventListener('input', syncHours);
            syncHours();
        </script>
    @endpush

@endsection

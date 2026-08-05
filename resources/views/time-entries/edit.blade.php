<x-app-layout title="Edit Time Entry">
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-800">Edit Time Entry</h1>
    </div>

    <div class="bg-white rounded-lg shadow p-6">
        <form action="{{ route('time-entries.update', $timeEntry) }}" method="POST">
            @csrf
            @method('PUT')

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <label for="project_id" class="block text-sm font-medium text-gray-700">Project</label>
                    <select name="project_id" id="project_id"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="">Select Project</option>
                        @foreach($projects as $id => $name)
                            <option value="{{ $id }}" {{ $timeEntry->project_id == $id ? 'selected' : '' }}>{{ $name }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="purchase_order_id" class="block text-sm font-medium text-gray-700">Purchase Order</label>
                    <select name="purchase_order_id" id="purchase_order_id"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="">Select PO</option>
                        @foreach($purchaseOrders as $id => $poNumber)
                            <option value="{{ $id }}" {{ $timeEntry->purchase_order_id == $id ? 'selected' : '' }}>{{ $poNumber }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="start_time" class="block text-sm font-medium text-gray-700">Start Time *</label>
                    <input type="datetime-local" name="start_time" id="start_time" value="{{ old('start_time', $timeEntry->start_time->format('Y-m-d\TH:i')) }}" required
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                </div>

                <div>
                    <label for="end_time" class="block text-sm font-medium text-gray-700">End Time</label>
                    <input type="datetime-local" name="end_time" id="end_time" value="{{ old('end_time', $timeEntry->end_time?->format('Y-m-d\TH:i')) }}"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                </div>

                <div>
                    <label for="hours" class="block text-sm font-medium text-gray-700">Manual Hours</label>
                    <input type="number" name="hours" id="hours" value="{{ old('hours', $timeEntry->hours) }}" step="0.01" min="0"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                </div>

                <div>
                    <label for="rate" class="block text-sm font-medium text-gray-700">Hourly Rate ($)</label>
                    <input type="number" name="rate" id="rate" value="{{ old('rate', $timeEntry->rate) }}" step="0.01" min="0"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                </div>

                <div class="flex items-center">
                    <input type="checkbox" name="billable" id="billable" value="1" {{ $timeEntry->billable ? 'checked' : '' }}
                        class="h-4 w-4 text-indigo-600 focus:ring-indigo-500 border-gray-300 rounded">
                    <label for="billable" class="ml-2 block text-sm text-gray-700">Billable</label>
                </div>
            </div>

            <div class="mt-6">
                <label for="description" class="block text-sm font-medium text-gray-700">Description</label>
                <textarea name="description" id="description" rows="3"
                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">{{ old('description', $timeEntry->description) }}</textarea>
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <a href="{{ route('time-entries.show', $timeEntry) }}" class="bg-gray-300 hover:bg-gray-400 text-gray-800 px-4 py-2 rounded-lg">
                    Cancel
                </a>
                <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg">
                    Update Time Entry
                </button>
            </div>
        </form>
    </div>
</x-app-layout>

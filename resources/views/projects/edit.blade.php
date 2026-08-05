<x-app-layout title="Edit Project">
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-800">Edit Project</h1>
    </div>

    <div class="bg-white rounded-lg shadow p-6">
        <form action="{{ route('projects.update', $project) }}" method="POST">
            @csrf
            @method('PUT')

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <label for="client_id" class="block text-sm font-medium text-gray-700">Client *</label>
                    <select name="client_id" id="client_id" required
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="">Select Client</option>
                        @foreach($clients as $id => $name)
                            <option value="{{ $id }}" {{ $project->client_id == $id ? 'selected' : '' }}>{{ $name }}</option>
                        @endforeach
                    </select>
                    @error('client_id')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="name" class="block text-sm font-medium text-gray-700">Project Name *</label>
                    <input type="text" name="name" id="name" value="{{ old('name', $project->name) }}" required
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                    @error('name')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div class="md:col-span-2">
                    <label for="description" class="block text-sm font-medium text-gray-700">Description</label>
                    <textarea name="description" id="description" rows="3"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">{{ old('description', $project->description) }}</textarea>
                </div>

                <div>
                    <label for="budget_hours" class="block text-sm font-medium text-gray-700">Budget Hours</label>
                    <input type="number" name="budget_hours" id="budget_hours" value="{{ old('budget_hours', $project->budget_hours) }}" step="0.01" min="0"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                </div>

                <div>
                    <label for="budget_amount" class="block text-sm font-medium text-gray-700">Budget Amount ($)</label>
                    <input type="number" name="budget_amount" id="budget_amount" value="{{ old('budget_amount', $project->budget_amount) }}" step="0.01" min="0"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                </div>

                <div>
                    <label for="hourly_rate" class="block text-sm font-medium text-gray-700">Default Hourly Rate ($)</label>
                    <input type="number" name="hourly_rate" id="hourly_rate" value="{{ old('hourly_rate', $project->hourly_rate) }}" step="0.01" min="0"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                </div>

                <div>
                    <label for="status" class="block text-sm font-medium text-gray-700">Status</label>
                    <select name="status" id="status"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="active" {{ $project->status == 'active' ? 'selected' : '' }}>Active</option>
                        <option value="on_hold" {{ $project->status == 'on_hold' ? 'selected' : '' }}>On Hold</option>
                        <option value="completed" {{ $project->status == 'completed' ? 'selected' : '' }}>Completed</option>
                        <option value="cancelled" {{ $project->status == 'cancelled' ? 'selected' : '' }}>Cancelled</option>
                    </select>
                </div>

                <div>
                    <label for="start_date" class="block text-sm font-medium text-gray-700">Start Date</label>
                    <input type="date" name="start_date" id="start_date" value="{{ old('start_date', $project->start_date?->format('Y-m-d')) }}"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                </div>

                <div>
                    <label for="end_date" class="block text-sm font-medium text-gray-700">End Date</label>
                    <input type="date" name="end_date" id="end_date" value="{{ old('end_date', $project->end_date?->format('Y-m-d')) }}"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                </div>
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <a href="{{ route('projects.show', $project) }}" class="bg-gray-300 hover:bg-gray-400 text-gray-800 px-4 py-2 rounded-lg">
                    Cancel
                </a>
                <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg">
                    Update Project
                </button>
            </div>
        </form>
    </div>
</x-app-layout>

@extends('layouts.app')
@section('title', 'Create Project')
@section('content')

    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-800">Create Project</h1>
    </div>

    <div class="bg-white rounded-lg shadow p-6">
        <form action="{{ route('projects.store') }}" method="POST">
            @csrf

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <label for="client_id" class="block text-sm font-medium text-gray-700">Client *</label>
                    <select name="client_id" id="client_id" required
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="">Select Client</option>
                        @foreach($clients as $id => $name)
                            <option value="{{ $id }}" {{ old('client_id') == $id ? 'selected' : '' }}>{{ $name }}</option>
                        @endforeach
                    </select>
                    @error('client_id')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="name" class="block text-sm font-medium text-gray-700">Project Name *</label>
                    <input type="text" name="name" id="name" value="{{ old('name') }}" required
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                    @error('name')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div class="md:col-span-2">
                    <label for="description" class="block text-sm font-medium text-gray-700">Description</label>
                    <textarea name="description" id="description" rows="3"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">{{ old('description') }}</textarea>
                </div>

                <div>
                    <label for="budget_hours" class="block text-sm font-medium text-gray-700">Budget Hours</label>
                    <input type="number" name="budget_hours" id="budget_hours" value="{{ old('budget_hours') }}" step="0.01" min="0"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                </div>

                <div>
                    <label for="budget_amount" class="block text-sm font-medium text-gray-700">Budget Amount ($)</label>
                    <input type="number" name="budget_amount" id="budget_amount" value="{{ old('budget_amount') }}" step="0.01" min="0"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                </div>

                <div>
                    <label for="hourly_rate" class="block text-sm font-medium text-gray-700">Default Hourly Rate ($)</label>
                    <input type="number" name="hourly_rate" id="hourly_rate" value="{{ old('hourly_rate') }}" step="0.01" min="0"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                </div>

                <div>
                    <label for="status" class="block text-sm font-medium text-gray-700">Status</label>
                    <select name="status" id="status"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="active" {{ old('status', 'active') == 'active' ? 'selected' : '' }}>Active</option>
                        <option value="on_hold" {{ old('status') == 'on_hold' ? 'selected' : '' }}>On Hold</option>
                    </select>
                </div>

                <div>
                    <label for="start_date" class="block text-sm font-medium text-gray-700">Start Date</label>
                    <input type="date" name="start_date" id="start_date" value="{{ old('start_date') }}"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                </div>

                <div>
                    <label for="end_date" class="block text-sm font-medium text-gray-700">End Date</label>
                    <input type="date" name="end_date" id="end_date" value="{{ old('end_date') }}"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                </div>
            </div>

            <!-- Staff Assignment Section -->
            <div class="mt-8 pt-6 border-t">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-lg font-semibold text-gray-800">Staff Assignments</h3>
                    <button type="button" id="add-staff-btn" class="text-indigo-600 hover:text-indigo-800 text-sm font-medium">
                        + Add Staff Member
                    </button>
                </div>

                <div id="staff-container" class="space-y-3">
                    <!-- Staff rows will be added here dynamically -->
                </div>

                <p class="text-sm text-gray-500 mt-2">Leave hourly rate blank to use project default rate.</p>
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <a href="{{ route('projects.index') }}" class="bg-gray-300 hover:bg-gray-400 text-gray-800 px-4 py-2 rounded-lg">
                    Cancel
                </a>
                <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg">
                    Create Project
                </button>
            </div>
        </form>
    </div>

    <template id="staff-row-template">
        <div class="staff-row flex gap-3 items-center">
            <select name="staff[@{{index}}][user_id]" required
                class="flex-1 rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                <option value="">Select Staff Member</option>
                @foreach($staff as $s)
                    <option value="{{ $s->id }}">{{ $s->name }}</option>
                @endforeach
            </select>
            <input type="number" name="staff[@{{index}}][hourly_rate]" placeholder="Hourly Rate" step="0.01" min="0"
                class="w-32 rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
            <button type="button" class="remove-staff-btn text-red-600 hover:text-red-800">Remove</button>
        </div>
    </template>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const container = document.getElementById('staff-container');
            const addBtn = document.getElementById('add-staff-btn');
            const template = document.getElementById('staff-row-template');
            let staffIndex = 0;

            addBtn.addEventListener('click', function() {
                const html = template.innerHTML.replace(/@{{index}}/g, staffIndex);
                container.insertAdjacentHTML('beforeend', html);
                staffIndex++;
            });

            container.addEventListener('click', function(e) {
                if (e.target.classList.contains('remove-staff-btn')) {
                    e.target.closest('.staff-row').remove();
                }
            });
        });
    </script>

@endsection
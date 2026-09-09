@extends('layouts.app')
@section('title', $employee->exists ? 'Edit payee' : 'Add payee')
@section('content')

    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-800">{{ $employee->exists ? 'Edit payee' : 'Add payee' }}</h1>
        <p class="text-sm text-gray-500 mt-1">
            Contractors (typically personal-services workers) withhold nothing and draw no super; employees
            withhold under ATO scale {{ $employee->taxScale() }} ({{ $employee->tax_free_threshold ? 'claiming' : 'not claiming' }}
            the tax-free threshold) and earn super on ordinary earnings.
        </p>
    </div>

    @if (session('error'))
        <div class="mb-4 bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg">{{ session('error') }}</div>
    @endif

    <form method="POST" action="{{ $employee->exists ? route('payroll.employees.update', $employee) : route('payroll.employees.store') }}"
        class="bg-white rounded-lg shadow p-6 max-w-3xl space-y-4">
        @csrf

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Name</label>
                <input type="text" name="name" value="{{ old('name', $employee->name) }}" required
                    class="w-full border-gray-300 rounded-lg text-sm">
                @error('name') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                <input type="email" name="email" value="{{ old('email', $employee->email) }}"
                    class="w-full border-gray-300 rounded-lg text-sm">
                @error('email') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">TFN</label>
                <input type="text" name="tfn" value="{{ old('tfn', $employee->tfn) }}" placeholder="required to avoid 47% withholding"
                    class="w-full border-gray-300 rounded-lg text-sm">
                @error('tfn') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Employment type</label>
                <select name="employment_type" class="w-full border-gray-300 rounded-lg text-sm">
                    @foreach (\App\Models\Employee::types() as $value => $label)
                        <option value="{{ $value }}" {{ old('employment_type', $employee->employment_type) === $value ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>
                @error('employment_type') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Payment basis</label>
                <select name="payment_basis" class="w-full border-gray-300 rounded-lg text-sm">
                    @foreach (\App\Models\Employee::bases() as $value => $label)
                        <option value="{{ $value }}" {{ old('payment_basis', $employee->payment_basis) === $value ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>
                @error('payment_basis') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Hourly rate</label>
                <input type="number" name="hourly_rate" step="0.0001" min="0" value="{{ old('hourly_rate', $employee->hourly_rate) }}"
                    class="w-full border-gray-300 rounded-lg text-sm">
                @error('hourly_rate') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Annual salary</label>
                <input type="number" name="annual_salary" step="0.01" min="0" value="{{ old('annual_salary', $employee->annual_salary) }}"
                    class="w-full border-gray-300 rounded-lg text-sm">
                @error('annual_salary') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Super rate (blank = SG)</label>
                <input type="number" name="super_rate" step="0.0001" min="0" max="0.5" value="{{ old('super_rate', $employee->super_rate) }}"
                    placeholder="e.g. 0.12"
                    class="w-full border-gray-300 rounded-lg text-sm">
                @error('super_rate') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Super fund</label>
                <input type="text" name="super_fund" value="{{ old('super_fund', $employee->super_fund) }}"
                    class="w-full border-gray-300 rounded-lg text-sm">
                @error('super_fund') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Super member no.</label>
                <input type="text" name="super_member_id" value="{{ old('super_member_id', $employee->super_member_id) }}"
                    class="w-full border-gray-300 rounded-lg text-sm">
                @error('super_member_id') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Start date</label>
                <input type="date" name="start_date" value="{{ old('start_date', $employee->start_date?->toDateString()) }}"
                    class="w-full border-gray-300 rounded-lg text-sm">
                @error('start_date') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">End date</label>
                <input type="date" name="end_date" value="{{ old('end_date', $employee->end_date?->toDateString()) }}"
                    class="w-full border-gray-300 rounded-lg text-sm">
                @error('end_date') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                <select name="status" class="w-full border-gray-300 rounded-lg text-sm">
                    <option value="active" {{ old('status', $employee->status) === 'active' ? 'selected' : '' }}>Active</option>
                    <option value="inactive" {{ old('status', $employee->status) === 'inactive' ? 'selected' : '' }}>Inactive</option>
                </select>
                @error('status') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>
        </div>

        <fieldset class="border border-gray-200 rounded-lg p-4">
            <legend class="text-sm font-medium text-gray-700 px-1">Tax treatment &amp; flags</legend>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                <label class="flex items-center gap-2 text-sm text-gray-700">
                    <input type="hidden" name="tax_free_threshold" value="0">
                    <input type="checkbox" name="tax_free_threshold" value="1"
                        {{ old('tax_free_threshold', $employee->tax_free_threshold) ? 'checked' : '' }}>
                    Claims the tax-free threshold
                </label>
                <label class="flex items-center gap-2 text-sm text-gray-700" title="Related to directors/shareholders — FBT and PSI attention">
                    <input type="hidden" name="is_closely_linked" value="0">
                    <input type="checkbox" name="is_closely_linked" value="1"
                        {{ old('is_closely_linked', $employee->is_closely_linked) ? 'checked' : '' }}>
                    Closely linked payee
                </label>
                <label class="flex items-center gap-2 text-sm text-gray-700" title="Paid for an individual's personal exertion — PSI rules apply">
                    <input type="hidden" name="is_personal_services" value="0">
                    <input type="checkbox" name="is_personal_services" value="1"
                        {{ old('is_personal_services', $employee->is_personal_services) ? 'checked' : '' }}>
                    Personal services income
                </label>
                <label class="flex items-center gap-2 text-sm text-gray-700" title="Sole-trader contractors whose contract is wholly or principally for their labour still earn super">
                    <input type="hidden" name="labour_only" value="0">
                    <input type="checkbox" name="labour_only" value="1"
                        {{ old('labour_only', $employee->labour_only) ? 'checked' : '' }}>
                    Labour-only contractor (SG applies)
                </label>
            </div>
        </fieldset>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Notes</label>
            <textarea name="notes" rows="2" class="w-full border-gray-300 rounded-lg text-sm">{{ old('notes', $employee->notes) }}</textarea>
            @error('notes') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
        </div>

        <div class="flex justify-end gap-2 pt-2">
            <a href="{{ route('payroll.employees.index') }}"
                class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 text-sm hover:bg-gray-50">Cancel</a>
            <button class="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm hover:bg-indigo-700">
                {{ $employee->exists ? 'Save' : 'Add' }} payee
            </button>
        </div>
    </form>
@endsection

@extends('layouts.app')
@section('title', 'Employee skills')
@section('content')

    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-800">Employee skills</h1>
        <p class="text-sm text-gray-500 mt-1">
            Every payroll payee and the skills they hold, at a proficiency level.
        </p>
    </div>

    @include('skills.tabs', ['active' => 'employees'])

    @if (session('success'))
        <div class="mb-4 bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg">{{ session('success') }}</div>
    @endif

    <div class="bg-white rounded-lg shadow overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Payee</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Skills held</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    @forelse ($employees as $employee)
                        <tr class="hover:bg-gray-50 {{ $employee->status === 'inactive' ? 'opacity-60' : '' }}">
                            <td class="px-4 py-3">
                                <div class="font-medium text-gray-900">{{ $employee->name }}</div>
                                <div class="text-xs text-gray-500">{{ $employee->email }}</div>
                            </td>
                            <td class="px-4 py-3 text-gray-600">
                                {{ ucfirst($employee->employment_type) }}
                                @if ($employee->status === 'inactive')<span class="text-xs">· inactive</span>@endif
                            </td>
                            <td class="px-4 py-3 text-gray-600">{{ $skillCounts[$employee->id] ?? 0 }}</td>
                            <td class="px-4 py-3 text-right">
                                <a href="{{ route('skills.employees.show', $employee) }}"
                                    class="text-indigo-600 hover:text-indigo-900 font-medium">
                                    {{ $canManage ? 'Manage skills' : 'View skills' }}
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-4 py-8 text-center text-gray-500">No payroll payees yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection

@extends('layouts.app')
@section('title', $user->name)
@section('content')

    <div class="mb-6 flex justify-between items-center">
        <h1 class="text-2xl font-bold text-gray-800">{{ $user->name }}</h1>
        <div class="flex gap-2">
            <a href="{{ route('users.edit', $user) }}" class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg">
                Edit User
            </a>
            @if($user->id !== auth()->id())
                <form action="{{ route('users.destroy', $user) }}" method="POST" class="inline">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg" onclick="return confirm('Are you sure?')">Delete</button>
                </form>
            @endif
        </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <div class="bg-white rounded-lg shadow p-6">
            <h2 class="text-lg font-semibold text-gray-800 mb-4">User Details</h2>
            <dl class="space-y-3">
                <div class="flex">
                    <dt class="w-32 text-sm font-medium text-gray-500">Email</dt>
                    <dd class="text-sm text-gray-900">{{ $user->email }}</dd>
                </div>
                <div class="flex">
                    <dt class="w-32 text-sm font-medium text-gray-500">Position</dt>
                    <dd class="text-sm text-gray-900">{{ $user->position ?? '-' }}</dd>
                </div>
                <div class="flex">
                    <dt class="w-32 text-sm font-medium text-gray-500">Phone</dt>
                    <dd class="text-sm text-gray-900">{{ $user->phone ?? '-' }}</dd>
                </div>
                <div class="flex">
                    <dt class="w-32 text-sm font-medium text-gray-500">Salary</dt>
                    <dd class="text-sm text-gray-900">{{ $user->salary ? '$' . number_format($user->salary, 2) : '-' }}</dd>
                </div>
                <div class="flex">
                    <dt class="w-32 text-sm font-medium text-gray-500">Charge Out Rate</dt>
                    <dd class="text-sm text-gray-900">{{ $user->charge_out_rate ? '$' . number_format($user->charge_out_rate, 2) . '/hr' : '-' }}</dd>
                </div>
                <div class="flex">
                    <dt class="w-32 text-sm font-medium text-gray-500">Created</dt>
                    <dd class="text-sm text-gray-900">{{ $user->created_at->format('d M Y') }}</dd>
                </div>
            </dl>
        </div>

        <div class="bg-white rounded-lg shadow p-6">
            <h2 class="text-lg font-semibold text-gray-800 mb-4">Roles</h2>
            @if($user->roles->isEmpty())
                <p class="text-gray-500">No roles assigned.</p>
            @else
                <div class="flex flex-wrap gap-2">
                    @foreach($user->roles as $role)
                        <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-blue-100 text-blue-800">
                            {{ ucfirst($role->name) }}
                        </span>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    <div class="mt-6">
        <a href="{{ route('users.index') }}" class="text-blue-600 hover:text-blue-800">&larr; Back to Users</a>
    </div>

@endsection

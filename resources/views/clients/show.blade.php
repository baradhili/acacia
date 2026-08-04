<x-app-layout title="{{ $client->name }}">
    <div class="mb-6 flex justify-between items-center">
        <h1 class="text-2xl font-bold text-gray-800">{{ $client->name }}</h1>
        <div class="flex gap-3">
            <a href="{{ route('clients.edit', $client) }}" class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg">
                Edit Client
            </a>
            <a href="{{ route('clients.index') }}" class="bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded-lg">
                Back
            </a>
        </div>
    </div>

    <div class="bg-white rounded-lg shadow p-6">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div>
                <h3 class="text-sm font-medium text-gray-500">Contact Information</h3>
                <dl class="mt-4 space-y-3">
                    <div class="flex justify-between">
                        <dt class="text-gray-600">Email:</dt>
                        <dd class="text-gray-900">{{ $client->email ?? '-' }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-gray-600">Phone:</dt>
                        <dd class="text-gray-900">{{ $client->phone ?? '-' }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-gray-600">ABN:</dt>
                        <dd class="text-gray-900">{{ $client->abn ?? '-' }}</dd>
                    </div>
                </dl>
            </div>
            <div>
                <h3 class="text-sm font-medium text-gray-500">Address</h3>
                <dl class="mt-4 space-y-3">
                    <div>
                        <dt class="text-gray-600">Street:</dt>
                        <dd class="text-gray-900">{{ $client->address ?? '-' }}</dd>
                    </div>
                    <div class="flex gap-4">
                        <div>
                            <dt class="text-gray-600">City:</dt>
                            <dd class="text-gray-900">{{ $client->city ?? '-' }}</dd>
                        </div>
                        <div>
                            <dt class="text-gray-600">State:</dt>
                            <dd class="text-gray-900">{{ $client->state ?? '-' }}</dd>
                        </div>
                        <div>
                            <dt class="text-gray-600">Postcode:</dt>
                            <dd class="text-gray-900">{{ $client->postcode ?? '-' }}</dd>
                        </div>
                    </div>
                    <div>
                        <dt class="text-gray-600">Country:</dt>
                        <dd class="text-gray-900">{{ $client->country ?? '-' }}</dd>
                    </div>
                </dl>
            </div>
        </div>
        @if($client->notes)
            <div class="mt-6 pt-6 border-t">
                <h3 class="text-sm font-medium text-gray-500">Notes</h3>
                <p class="mt-2 text-gray-700">{{ $client->notes }}</p>
            </div>
        @endif
    </div>
</x-app-layout>

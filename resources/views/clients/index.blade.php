<x-app-layout title="Clients">
    <div class="mb-6 flex justify-between items-center">
        <h1 class="text-2xl font-bold text-gray-800">Clients</h1>
        <a href="{{ route('clients.create') }}" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg">
            Add Client
        </a>
    </div>

    <div class="bg-white rounded-lg shadow">
        <div class="p-6">
            @if($clients->isEmpty())
                <p class="text-gray-500 text-center py-8">No clients yet. Add your first client to get started.</p>
            @else
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Name</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Email</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Phone</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">ABN</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        @foreach($clients as $client)
                            <tr>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <a href="{{ route('clients.show', $client) }}" class="text-blue-600 hover:text-blue-900">{{ $client->name }}</a>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-gray-500">{{ $client->email ?? '-' }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-gray-500">{{ $client->phone ?? '-' }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-gray-500">{{ $client->abn ?? '-' }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                    <a href="{{ route('clients.edit', $client) }}" class="text-indigo-600 hover:text-indigo-900 mr-3">Edit</a>
                                    <form action="{{ route('clients.destroy', $client) }}" method="POST" class="inline">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="text-red-600 hover:text-red-900" onclick="return confirm('Are you sure?')">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <div class="px-6 py-4">
                    {{ $clients->links() }}
                </div>
            @endif
        </div>
    </div>
</x-app-layout>

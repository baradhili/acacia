<x-app-layout title="Vendors">
    <div class="mb-6 flex justify-between items-center">
        <h1 class="text-2xl font-bold text-gray-800">Vendors</h1>
        <a href="{{ route('vendors.create') }}" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg">
            Add Vendor
        </a>
    </div>

    <div class="bg-white rounded-lg shadow">
        <div class="p-6">
            @if($vendors->isEmpty())
                <p class="text-gray-500 text-center py-8">No vendors yet. Add your first vendor to get started.</p>
            @else
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Name</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Category</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Email</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Phone</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        @foreach($vendors as $vendor)
                            <tr>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <a href="{{ route('vendors.show', $vendor) }}" class="text-blue-600 hover:text-blue-900">{{ $vendor->name }}</a>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-gray-500">{{ $vendor->category ?? '-' }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-gray-500">{{ $vendor->email ?? '-' }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-gray-500">{{ $vendor->phone ?? '-' }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                    <a href="{{ route('vendors.edit', $vendor) }}" class="text-indigo-600 hover:text-indigo-900 mr-3">Edit</a>
                                    <form action="{{ route('vendors.destroy', $vendor) }}" method="POST" class="inline">
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
                    {{ $vendors->links() }}
                </div>
            @endif
        </div>
    </div>
</x-app-layout>

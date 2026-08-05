@extends('layouts.app')
@section('title', 'Create Credit Note')
@section('content')

    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-800">Create Credit Note</h1>
    </div>

    <form action="{{ route('credit-notes.store') }}" method="POST" class="space-y-6">
        @csrf
        
        <div class="bg-white rounded-lg shadow p-6">
            <h2 class="text-lg font-semibold text-gray-800 mb-4">Credit Note Details</h2>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Client *</label>
                    <select name="client_id" required
                        class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 w-full">
                        <option value="">Select Client</option>
                        @foreach($clients as $id => $name)
                            <option value="{{ $id }}" {{ old('client_id') == $id ? 'selected' : '' }}>{{ $name }}</option>
                        @endforeach
                    </select>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Issue Date *</label>
                    <input type="date" name="issue_date" value="{{ old('issue_date', now()->toDateString()) }}" required
                        class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 w-full">
                </div>
                
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Reason *</label>
                    <select name="reason" required
                        class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 w-full">
                        <option value="">Select Reason</option>
                        <option value="Product return">Product Return</option>
                        <option value="Service not delivered">Service Not Delivered</option>
                        <option value="Overcharge">Overcharge</option>
                        <option value="Discount adjustment">Discount Adjustment</option>
                        <option value="Other">Other</option>
                    </select>
                </div>
                
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Notes</label>
                    <textarea name="notes" rows="2"
                        class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 w-full">{{ old('notes') }}</textarea>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-lg shadow p-6">
            <h2 class="text-lg font-semibold text-gray-800 mb-4">Line Items</h2>
            
            <div id="itemsContainer">
                @php $items = old('items', [[]]); @endphp
                @foreach($items as $index => $item)
                    <div class="item-row grid grid-cols-12 gap-2 mb-4 p-4 bg-gray-50 rounded-lg">
                        <div class="col-span-5">
                            <label class="block text-xs font-medium text-gray-700 mb-1">Description</label>
                            <input type="text" name="items[{{ $index }}][description]" 
                                value="{{ $item['description'] ?? '' }}" required
                                class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 w-full text-sm">
                        </div>
                        <div class="col-span-2">
                            <label class="block text-xs font-medium text-gray-700 mb-1">Qty</label>
                            <input type="number" name="items[{{ $index }}][quantity]" 
                                value="{{ $item['quantity'] ?? 1 }}" step="0.01" min="0.01" required
                                class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 w-full text-sm qty-input">
                        </div>
                        <div class="col-span-2">
                            <label class="block text-xs font-medium text-gray-700 mb-1">Unit Price</label>
                            <input type="number" name="items[{{ $index }}][unit_price]" 
                                value="{{ $item['unit_price'] ?? 0 }}" step="0.01" min="0" required
                                class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 w-full text-sm price-input">
                        </div>
                        <div class="col-span-1">
                            <label class="block text-xs font-medium text-gray-700 mb-1">Tax %</label>
                            <input type="number" name="items[{{ $index }}][tax_rate]" 
                                value="{{ $item['tax_rate'] ?? 10 }}" step="0.01" min="0" max="100"
                                class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 w-full text-sm tax-input">
                        </div>
                        <div class="col-span-2">
                            <label class="block text-xs font-medium text-gray-700 mb-1">Total</label>
                            <div class="text-sm font-medium text-gray-900 pt-5 line-total">$0.00</div>
                            @if($index > 0)
                                <button type="button" class="remove-item text-red-600 hover:text-red-800 text-xs mt-1">Remove</button>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
            
            <button type="button" id="addItemBtn" class="mt-4 bg-gray-100 hover:bg-gray-200 text-gray-700 px-4 py-2 rounded-lg text-sm">
                + Add Item
            </button>
        </div>

        <div class="flex justify-end gap-4">
            <a href="{{ route('credit-notes.index') }}" class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-6 py-2 rounded-lg">Cancel</a>
            <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white px-6 py-2 rounded-lg">Create Credit Note</button>
        </div>
    </form>

    <template id="itemTemplate">
        <div class="item-row grid grid-cols-12 gap-2 mb-4 p-4 bg-gray-50 rounded-lg">
            <div class="col-span-5">
                <label class="block text-xs font-medium text-gray-700 mb-1">Description</label>
                <input type="text" name="items[__INDEX__][description]" required
                    class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 w-full text-sm">
            </div>
            <div class="col-span-2">
                <label class="block text-xs font-medium text-gray-700 mb-1">Qty</label>
                <input type="number" name="items[__INDEX__][quantity]" value="1" step="0.01" min="0.01" required
                    class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 w-full text-sm qty-input">
            </div>
            <div class="col-span-2">
                <label class="block text-xs font-medium text-gray-700 mb-1">Unit Price</label>
                <input type="number" name="items[__INDEX__][unit_price]" value="0" step="0.01" min="0" required
                    class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 w-full text-sm price-input">
            </div>
            <div class="col-span-1">
                <label class="block text-xs font-medium text-gray-700 mb-1">Tax %</label>
                <input type="number" name="items[__INDEX__][tax_rate]" value="10" step="0.01" min="0" max="100"
                    class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 w-full text-sm tax-input">
            </div>
            <div class="col-span-2">
                <label class="block text-xs font-medium text-gray-700 mb-1">Total</label>
                <div class="text-sm font-medium text-gray-900 pt-5 line-total">$0.00</div>
                <button type="button" class="remove-item text-red-600 hover:text-red-800 text-xs mt-1">Remove</button>
            </div>
        </div>
    </template>

    <script>
        let itemIndex = {{ count(old('items', [[]])) }};
        
        document.getElementById('addItemBtn').addEventListener('click', function() {
            const container = document.getElementById('itemsContainer');
            const template = document.getElementById('itemTemplate').innerHTML;
            const html = template.replace(/__INDEX__/g, itemIndex);
            container.insertAdjacentHTML('beforeend', html);
            itemIndex++;
            attachEventListeners();
        });
        
        function attachEventListeners() {
            document.querySelectorAll('.item-row').forEach(row => {
                function updateTotal() {
                    const qty = parseFloat(row.querySelector('.qty-input').value) || 0;
                    const price = parseFloat(row.querySelector('.price-input').value) || 0;
                    const tax = parseFloat(row.querySelector('.tax-input').value) || 0;
                    const total = qty * price * (1 + tax / 100);
                    row.querySelector('.line-total').textContent = '$' + total.toFixed(2);
                }
                row.querySelectorAll('input').forEach(input => input.addEventListener('input', updateTotal));
                updateTotal();
            });
            
            document.querySelectorAll('.remove-item').forEach(btn => {
                btn.addEventListener('click', function() {
                    this.closest('.item-row').remove();
                });
            });
        }
        
        attachEventListeners();
    </script>

@endsection

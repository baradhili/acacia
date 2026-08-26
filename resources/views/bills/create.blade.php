@extends('layouts.app')
@section('title', 'Create Bill')
@section('content')

    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-800">Create Bill</h1>
    </div>

    <form action="{{ route('bills.store') }}" method="POST" enctype="multipart/form-data" class="space-y-6">
        @csrf

        <div class="bg-white rounded-lg shadow p-6">
            <h2 class="text-lg font-semibold text-gray-800 mb-4">Supplier & Project</h2>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Supplier *</label>
                    <select name="supplier_id" id="supplierSelect" required
                        class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 w-full">
                        <option value="">Select Supplier</option>
                        @foreach ($suppliers as $id => $name)
                            <option value="{{ $id }}"
                                {{ old('supplier_id', $selectedSupplier?->id) == $id ? 'selected' : '' }}>{{ $name }}
                            </option>
                        @endforeach
                    </select>
                    @error('supplier_id')
                        <p class="text-red-500 text-sm mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Project</label>
                    <select name="project_id" id="projectSelect"
                        class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 w-full">
                        <option value="">Select Project (optional)</option>
                        @foreach ($projects as $project)
                            <option value="{{ $project->id }}"
                                {{ old('project_id', $selectedProject?->id) == $project->id ? 'selected' : '' }}>
                                {{ $project->name }}
                            </option>
                        @endforeach
                    </select>
                    @error('project_id')
                        <p class="text-red-500 text-sm mt-1">{{ $message }}</p>
                    @enderror
                </div>
            </div>
        </div>

        <div class="bg-white rounded-lg shadow p-6">
            <h2 class="text-lg font-semibold text-gray-800 mb-4">Bill Details</h2>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Bill Date *</label>
                    <input type="date" name="bill_date" id="billDate" value="{{ old('bill_date', now()->toDateString()) }}" required
                        class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 w-full">
                    @error('bill_date')
                        <p class="text-red-500 text-sm mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Due Date *</label>
                    <input type="date" name="due_date" value="{{ old('due_date', now()->addDays(30)->toDateString()) }}"
                        required
                        class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 w-full">
                    @error('due_date')
                        <p class="text-red-500 text-sm mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Reference</label>
                    <input type="text" name="reference" value="{{ old('reference') }}"
                        class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 w-full"
                        placeholder="Supplier invoice no.">
                    @error('reference')
                        <p class="text-red-500 text-sm mt-1">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            <div class="mt-4">
                <label class="block text-sm font-medium text-gray-700 mb-1">Notes</label>
                <textarea name="notes" rows="2"
                    class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 w-full">{{ old('notes') }}</textarea>
            </div>
        </div>

        <div class="bg-white rounded-lg shadow p-6">
            <h2 class="text-lg font-semibold text-gray-800 mb-4">Line Items</h2>
            <p class="text-sm text-gray-500 mb-4">
                Tick the GST treatment for each line: Incl. GST when the amount you entered is
                what you pay and already includes GST (portion calculated: $110 → $100 + $10);
                Add GST for suppliers who quote ex-GST lines and add GST at the subtotal
                ($100 → $110); neither for GST-free supplies (bank fees, rego, basic food…).
                Pick the expense account each line should post to.
            </p>

            <div id="itemsContainer">
                @php $items = old('items', [[]]); @endphp
                @foreach ($items as $index => $item)
                    <div class="item-row grid grid-cols-12 gap-2 mb-4 p-4 bg-gray-50 rounded-lg">
                        <div class="col-span-3">
                            <label class="block text-xs font-medium text-gray-700 mb-1">Description</label>
                            <input type="text" name="items[{{ $index }}][description]"
                                value="{{ $item['description'] ?? '' }}" required
                                class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 w-full text-sm"
                                placeholder="Item description">
                        </div>
                        <div class="col-span-1">
                            <label class="block text-xs font-medium text-gray-700 mb-1">Qty</label>
                            <input type="number" name="items[{{ $index }}][quantity]"
                                value="{{ $item['quantity'] ?? 1 }}" step="0.01" min="0" required
                                class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 w-full text-sm quantity-input">
                        </div>
                        <div class="col-span-2">
                            <label class="block text-xs font-medium text-gray-700 mb-1">Unit Price</label>
                            <input type="number" name="items[{{ $index }}][unit_price]"
                                value="{{ $item['unit_price'] ?? 0 }}" step="0.01" min="0" required
                                class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 w-full text-sm unit-price-input">
                        </div>
                        <div class="col-span-1">
                            <label class="block text-xs font-medium text-gray-700 mb-1">Disc %</label>
                            <input type="number" name="items[{{ $index }}][discount_percent]"
                                value="{{ $item['discount_percent'] ?? 0 }}" step="0.01" min="0" max="100"
                                class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 w-full text-sm discount-input">
                        </div>
                        <div class="col-span-1 flex flex-col justify-center">
                            <label class="block text-xs font-medium text-gray-700 mb-1 text-center">GST</label>
                            <label class="flex items-center justify-center gap-1 text-xs text-gray-600"
                                title="The amount you entered already includes GST — the GST portion is calculated from it">
                                <input type="checkbox" name="items[{{ $index }}][gst]" value="1" checked
                                    class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 gst-toggle"> Incl
                            </label>
                            <label class="flex items-center justify-center gap-1 text-xs text-gray-600 mt-1"
                                title="The amount you entered excludes GST (ex-GST supplier) — GST is added on top">
                                <input type="checkbox" name="items[{{ $index }}][gst_add]" value="1"
                                    class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 gst-add-toggle"> Add
                            </label>
                        </div>
                        <div class="col-span-2">
                            <label class="block text-xs font-medium text-gray-700 mb-1">Category</label>
                            <select name="items[{{ $index }}][expense_account_id]"
                                class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 w-full text-sm">
                                <option value="">— Select —</option>
                                @foreach ($purchaseAccounts as $groupLabel => $groupAccounts)
                                    <optgroup label="{{ $groupLabel }}">
                                        @foreach ($groupAccounts as $accountId => $label)
                                            <option value="{{ $accountId }}"
                                                {{ ($item['expense_account_id'] ?? '') == $accountId ? 'selected' : '' }}>{{ $label }}</option>
                                        @endforeach
                                    </optgroup>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-span-2">
                            <label class="block text-xs font-medium text-gray-700 mb-1">Total</label>
                            <div class="text-sm font-medium text-gray-900 pt-4 line-total">$0.00</div>
                        </div>
                    </div>
                @endforeach
            </div>

            <button type="button" id="addItemBtn"
                class="mt-4 bg-gray-100 hover:bg-gray-200 text-gray-700 px-4 py-2 rounded-lg text-sm">
                + Add Line Item
            </button>

            <div class="mt-6 border-t pt-4">
                <div class="flex justify-between text-sm mb-1">
                    <span class="text-gray-600">Subtotal (ex GST)</span>
                    <span id="billSubtotal">$0.00</span>
                </div>
                <div class="flex justify-between text-sm mb-1">
                    <span class="text-gray-600">GST</span>
                    <span id="billTax">$0.00</span>
                </div>
                <div class="flex justify-between text-lg font-bold border-t pt-2">
                    <span>Total</span>
                    <span id="billTotal">$0.00</span>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-lg shadow p-6">
            <h2 class="text-lg font-semibold text-gray-800 mb-4">Payment</h2>

            <label class="flex items-center mb-4">
                <input type="checkbox" name="paid_now" id="paidNowToggle" value="1"
                    class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                    {{ old('paid_now') ? 'checked' : '' }}>
                <span class="ml-2 text-sm text-gray-700">
                    This bill was already paid (parking, entertainment, online purchase…)
                </span>
            </label>

            <div id="paidNowFields" class="hidden grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Payment Date *</label>
                    <input type="date" name="payment_date" value="{{ old('payment_date', now()->toDateString()) }}"
                        class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 w-full">
                    @error('payment_date')
                        <p class="text-red-500 text-sm mt-1">{{ $message }}</p>
                    @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Payment Method *</label>
                    <select name="payment_method"
                        class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 w-full">
                        @foreach ($paymentMethods as $value => $label)
                            <option value="{{ $value }}" {{ old('payment_method') == $value ? 'selected' : '' }}>
                                {{ $label }}</option>
                        @endforeach
                    </select>
                    @error('payment_method')
                        <p class="text-red-500 text-sm mt-1">{{ $message }}</p>
                    @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Payment Reference</label>
                    <input type="text" name="payment_reference" value="{{ old('payment_reference') }}"
                        class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 w-full"
                        placeholder="Transaction ID, card statement ref…">
                    @error('payment_reference')
                        <p class="text-red-500 text-sm mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <div class="md:col-span-3">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Receipt / supporting document</label>
                    <input type="file" name="documents[]" multiple
                        class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 w-full"
                        accept=".pdf,.jpg,.jpeg,.png,.gif,.doc,.docx,.xls,.xlsx,.txt,.zip,.rar">
                    <p class="text-xs text-gray-500 mt-1">
                        Attach the receipt or invoice for this paid expense (PDF, JPG, PNG, DOC up to 20MB).
                    </p>
                    @error('documents.*')
                        <p class="text-red-500 text-sm mt-1">{{ $message }}</p>
                    @enderror
                </div>
            </div>
        </div>

        <div class="flex justify-end gap-4">
            <a href="{{ route('bills.index') }}"
                class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-6 py-2 rounded-lg">
                Cancel
            </a>
            <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white px-6 py-2 rounded-lg">
                Create Bill
            </button>
        </div>
    </form>

    <template id="itemTemplate">
        <div class="item-row grid grid-cols-12 gap-2 mb-4 p-4 bg-gray-50 rounded-lg">
            <div class="col-span-3">
                <label class="block text-xs font-medium text-gray-700 mb-1">Description</label>
                <input type="text" name="items[__INDEX__][description]" required
                    class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 w-full text-sm"
                    placeholder="Item description">
            </div>
            <div class="col-span-1">
                <label class="block text-xs font-medium text-gray-700 mb-1">Qty</label>
                <input type="number" name="items[__INDEX__][quantity]" value="1" step="0.01" min="0"
                    required
                    class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 w-full text-sm quantity-input">
            </div>
            <div class="col-span-2">
                <label class="block text-xs font-medium text-gray-700 mb-1">Unit Price</label>
                <input type="number" name="items[__INDEX__][unit_price]" value="0" step="0.01" min="0"
                    required
                    class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 w-full text-sm unit-price-input">
            </div>
            <div class="col-span-1">
                <label class="block text-xs font-medium text-gray-700 mb-1">Disc %</label>
                <input type="number" name="items[__INDEX__][discount_percent]" value="0" step="0.01"
                    min="0" max="100"
                    class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 w-full text-sm discount-input">
            </div>
            <div class="col-span-1 flex flex-col justify-center">
                <label class="block text-xs font-medium text-gray-700 mb-1 text-center">GST</label>
                <label class="flex items-center justify-center gap-1 text-xs text-gray-600"
                    title="The amount you entered already includes GST — the GST portion is calculated from it">
                    <input type="checkbox" name="items[__INDEX__][gst]" value="1" checked
                        class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 gst-toggle"> Incl
                </label>
                <label class="flex items-center justify-center gap-1 text-xs text-gray-600 mt-1"
                    title="The amount you entered excludes GST (ex-GST supplier) — GST is added on top">
                    <input type="checkbox" name="items[__INDEX__][gst_add]" value="1"
                        class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 gst-add-toggle"> Add
                </label>
            </div>
            <div class="col-span-2">
                <label class="block text-xs font-medium text-gray-700 mb-1">Category</label>
                <select name="items[__INDEX__][expense_account_id]"
                    class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 w-full text-sm">
                    <option value="">— Select —</option>
                    @foreach ($purchaseAccounts as $groupLabel => $groupAccounts)
                        <optgroup label="{{ $groupLabel }}">
                            @foreach ($groupAccounts as $accountId => $label)
                                <option value="{{ $accountId }}">{{ $label }}</option>
                            @endforeach
                        </optgroup>
                    @endforeach
                </select>
            </div>
            <div class="col-span-2">
                <label class="block text-xs font-medium text-gray-700 mb-1">Total</label>
                <div class="text-sm font-medium text-gray-900 pt-4 line-total">$0.00</div>
                <button type="button" class="remove-item text-red-600 hover:text-red-800 text-xs mt-1">Remove</button>
            </div>
        </div>
    </template>

    @push('scripts')
        <script>
            let itemIndex = {{ count(old('items', [[]])) }};
            const GST_RATE = {{ config('australian.gst.rate', 10) }};

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
                    const qtyInput = row.querySelector('.quantity-input');
                    const priceInput = row.querySelector('.unit-price-input');
                    const gstToggle = row.querySelector('.gst-toggle');
                    const gstAddToggle = row.querySelector('.gst-add-toggle');
                    const discInput = row.querySelector('.discount-input');
                    const totalDiv = row.querySelector('.line-total');

                    function updateTotal() {
                        const qty = parseFloat(qtyInput.value) || 0;
                        const price = parseFloat(priceInput.value) || 0;
                        const disc = parseFloat(discInput.value) || 0;

                        const gross = qty * price;
                        const afterDiscount = gross * (1 - disc / 100);
                        // Mirrors BillItem::calculateTotals — three modes:
                        // Add (ex-GST, GST on top), Incl (GST backed out),
                        // or free.
                        const total = gstAddToggle.checked
                            ? afterDiscount * (1 + GST_RATE / 100)
                            : afterDiscount;

                        totalDiv.textContent = '$' + total.toFixed(2);
                        totalDiv.classList.toggle('text-gray-400', !gstToggle.checked && !gstAddToggle.checked);
                        updateBillTotals();
                    }

                    // The two GST ticks are mutually exclusive
                    gstToggle.addEventListener('change', function() {
                        if (this.checked) gstAddToggle.checked = false;
                        updateTotal();
                    });
                    gstAddToggle.addEventListener('change', function() {
                        if (this.checked) gstToggle.checked = false;
                        updateTotal();
                    });

                    [qtyInput, priceInput, gstToggle, discInput].forEach(input => {
                        if (input) input.addEventListener('input', updateTotal);
                    });

                    updateTotal();
                });

                document.querySelectorAll('.remove-item').forEach(btn => {
                    btn.addEventListener('click', function() {
                        this.closest('.item-row').remove();
                        updateBillTotals();
                    });
                });
            }

            function updateBillTotals() {
                let subtotal = 0, tax = 0;
                document.querySelectorAll('.item-row').forEach(row => {
                    const qty = parseFloat(row.querySelector('.quantity-input')?.value) || 0;
                    const price = parseFloat(row.querySelector('.unit-price-input')?.value) || 0;
                    const disc = parseFloat(row.querySelector('.discount-input')?.value) || 0;
                    // Three GST modes mirror BillItem::calculateTotals:
                    // add (GST on top), incl (GST backed out of the paid
                    // amount) or free.
                    const incl = row.querySelector('.gst-toggle')?.checked ?? true;
                    const add = row.querySelector('.gst-add-toggle')?.checked ?? false;
                    const afterDiscount = qty * price * (1 - disc / 100);
                    let lineTotal, lineTax;
                    if (add) {
                        lineTax = afterDiscount * (GST_RATE / 100);
                        lineTotal = afterDiscount + lineTax;
                    } else {
                        lineTotal = afterDiscount;
                        lineTax = incl ? lineTotal * (GST_RATE / (100 + GST_RATE)) : 0;
                    }
                    subtotal += lineTotal - lineTax;
                    tax += lineTax;
                });

                document.getElementById('billSubtotal').textContent = '$' + subtotal.toFixed(2);
                document.getElementById('billTax').textContent = '$' + tax.toFixed(2);
                document.getElementById('billTotal').textContent = '$' + (subtotal + tax).toFixed(2);
            }

            attachEventListeners();

            // Paid-at-entry toggle
            const paidNowToggle = document.getElementById('paidNowToggle');
            const paidNowFields = document.getElementById('paidNowFields');
            function syncPaidNow() {
                paidNowFields.classList.toggle('hidden', !paidNowToggle.checked);
            }
            paidNowToggle.addEventListener('change', syncPaidNow);
            syncPaidNow();
        </script>
    @endpush

@endsection

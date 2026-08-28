{{-- Shared create/edit form fields for a dividend declaration (draft only). --}}
<div class="grid grid-cols-1 md:grid-cols-2 gap-4">
    <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Declaration date</label>
        <input type="date" name="declaration_date" value="{{ old('declaration_date', $declaration->declaration_date?->format('Y-m-d') ?? now()->toDateString()) }}" required
            class="w-full border-gray-300 rounded-lg" {{ $declaration->exists ? '' : '' }}>
        @error('declaration_date') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Share class</label>
        <select name="share_class_id" required class="w-full border-gray-300 rounded-lg">
            @foreach($shareClasses as $class)
                <option value="{{ $class->id }}" {{ old('share_class_id', $declaration->share_class_id) == $class->id ? 'selected' : '' }}>
                    {{ $class->code }} — {{ $class->description ?: 'shares' }}
                </option>
            @endforeach
        </select>
        @error('share_class_id') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Dividend type</label>
        <select name="dividend_type" class="w-full border-gray-300 rounded-lg">
            @foreach(\App\Models\DividendDeclaration::dividendTypes() as $value => $label)
                <option value="{{ $value }}" {{ old('dividend_type', $declaration->dividend_type ?? 'I') === $value ? 'selected' : '' }}>{{ $label }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Amount per share ($)</label>
        <input type="number" name="amount_per_share" step="0.000001" min="0" required
            value="{{ old('amount_per_share', $declaration->exists ? $declaration->amount_per_share : '') }}"
            placeholder="e.g. 0.05" class="w-full border-gray-300 rounded-lg">
        @error('amount_per_share') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Franking percentage (0–100)</label>
        <input type="number" name="franking_percentage" step="0.01" min="0" max="100" required
            value="{{ old('franking_percentage', $declaration->franking_percentage ?? $frankingPercentage) }}"
            class="w-full border-gray-300 rounded-lg">
        @error('franking_percentage') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Franking credit rate (corporate tax rate %)</label>
        <input type="number" name="franking_credit_rate" step="0.01" min="0.01" max="99.99" required
            value="{{ old('franking_credit_rate', $declaration->franking_credit_rate ?? $frankingRate) }}"
            class="w-full border-gray-300 rounded-lg">
        @error('franking_credit_rate') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Books close date (entitlement)</label>
        <input type="date" name="books_close_date" value="{{ old('books_close_date', $declaration->books_close_date?->format('Y-m-d')) }}" required
            class="w-full border-gray-300 rounded-lg">
        @error('books_close_date') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Payment date</label>
        <input type="date" name="payment_date" value="{{ old('payment_date', $declaration->payment_date?->format('Y-m-d')) }}" required
            class="w-full border-gray-300 rounded-lg">
        @error('payment_date') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
    </div>
    <div class="md:col-span-2">
        <label class="block text-sm font-medium text-gray-700 mb-1">Notes</label>
        <textarea name="notes" rows="2" maxlength="500"
            class="w-full border-gray-300 rounded-lg">{{ old('notes', $declaration->notes) }}</textarea>
        @error('notes') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
    </div>
</div>

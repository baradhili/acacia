<div class="grid grid-cols-1 md:grid-cols-2 gap-4">
    <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Name <span class="text-red-500">*</span></label>
        <input type="text" name="name" value="{{ old('name', $lead->name ?? '') }}" required maxlength="255"
            class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
            placeholder="Contact or account name">
        @error('name') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Company</label>
        <input type="text" name="company" value="{{ old('company', $lead->company ?? '') }}" maxlength="255"
            class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
        <input type="email" name="email" value="{{ old('email', $lead->email ?? '') }}" maxlength="255"
            class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
        @error('email') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Phone</label>
        <input type="text" name="phone" value="{{ old('phone', $lead->phone ?? '') }}" maxlength="50"
            class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Source</label>
        <select name="source" class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
            <option value="">—</option>
            @foreach (\Modules\Crm\Models\Lead::sources() as $source)
                <option value="{{ $source }}" {{ old('source', $lead->source ?? '') === $source ? 'selected' : '' }}>{{ str_replace('_', ' ', ucfirst($source)) }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Owner</label>
        <select name="owner_id" class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
            <option value="">—</option>
            @foreach ($owners as $id => $name)
                <option value="{{ $id }}" {{ (string) old('owner_id', $lead->owner_id ?? '') === (string) $id ? 'selected' : '' }}>{{ $name }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Estimated value</label>
        <input type="number" name="estimated_value" value="{{ old('estimated_value', $lead->estimated_value ?? 0) }}" step="0.01" min="0"
            class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Win probability %</label>
        <input type="number" name="probability" value="{{ old('probability', $lead->probability ?? 0) }}" step="0.5" min="0" max="100"
            class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Next follow-up</label>
        <input type="date" name="next_follow_up" value="{{ old('next_follow_up', $lead->next_follow_up?->toDateString() ?? '') }}"
            class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
    </div>
    <div class="md:col-span-2">
        <label class="block text-sm font-medium text-gray-700 mb-1">The plan — what we're selling and how</label>
        <textarea name="notes" rows="3"
            class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">{{ old('notes', $lead->notes ?? '') }}</textarea>
    </div>
</div>

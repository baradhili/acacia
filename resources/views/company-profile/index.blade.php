@extends('layouts.app')
@section('title', 'Company Details')
@section('content')

    <div class="mb-6 flex justify-between items-center">
        <h1 class="text-2xl font-bold text-gray-800">Company Details</h1>
        <p class="text-sm text-gray-500">
            Identity, registered address, directors and shareholders for
            <span class="font-medium text-gray-700">{{ $entity->name }}</span>
        </p>
    </div>

    @if (session('success'))
        <div class="mb-4 bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg">
            {{ session('success') }}
        </div>
    @endif
    @if (session('error'))
        <div class="mb-4 bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg">
            {{ session('error') }}
        </div>
    @endif
    @if ($errors->any())
        <div class="mb-4 bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg">
            <p class="font-semibold mb-1">Please correct the following:</p>
            <ul class="list-disc list-inside text-sm">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('company-profile.update') }}">
        @csrf
        @method('PUT')

        <!-- Company identity -->
        <div class="bg-white rounded-lg shadow p-6 mb-6">
            <h2 class="text-lg font-semibold text-gray-800 mb-4">Company Identity</h2>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1" for="abn">ABN</label>
                    <input id="abn" name="abn" type="text" inputmode="numeric" maxlength="11" value="{{ old('abn', $profile->abn) }}"
                        placeholder="11 digits"
                        class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 @error('abn') border-red-300 @enderror">
                    @error('abn') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1" for="tfn">TFN</label>
                    <input id="tfn" name="tfn" type="text" inputmode="numeric" maxlength="9" value="{{ old('tfn', $profile->tfn) }}"
                        placeholder="9 digits"
                        class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 @error('tfn') border-red-300 @enderror">
                    @error('tfn') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1" for="acn">ACN</label>
                    <input id="acn" name="acn" type="text" inputmode="numeric" maxlength="9" value="{{ old('acn', $profile->acn) }}"
                        placeholder="9 digits"
                        class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 @error('acn') border-red-300 @enderror">
                    @error('acn') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
            </div>
            <p class="text-xs text-gray-500 mt-3">
                The ABN/TFN appear on the Company Tax Report (labels and CSV/PDF exports). When blank here, the
                <code>COMPANY_ABN</code>/<code>COMPANY_TFN</code> environment values are used as a fallback.
            </p>
        </div>

        <!-- Registered address -->
        <div class="bg-white rounded-lg shadow p-6 mb-6">
            <h2 class="text-lg font-semibold text-gray-800 mb-4">Registered Address &amp; Contact</h2>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1" for="address_line1">Address line 1</label>
                    <input id="address_line1" name="address_line1" type="text" value="{{ old('address_line1', $profile->address_line1) }}"
                        class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1" for="address_line2">Address line 2</label>
                    <input id="address_line2" name="address_line2" type="text" value="{{ old('address_line2', $profile->address_line2) }}"
                        class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1" for="suburb">Suburb</label>
                    <input id="suburb" name="suburb" type="text" value="{{ old('suburb', $profile->suburb) }}"
                        class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1" for="state">State</label>
                    <input id="state" name="state" type="text" maxlength="3" value="{{ old('state', $profile->state) }}" placeholder="NSW"
                        class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1" for="postcode">Postcode</label>
                    <input id="postcode" name="postcode" type="text" maxlength="4" value="{{ old('postcode', $profile->postcode) }}"
                        class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1" for="country">Country</label>
                    <input id="country" name="country" type="text" maxlength="2" value="{{ old('country', $profile->country ?? 'AU') }}"
                        class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1" for="email">Email</label>
                    <input id="email" name="email" type="email" value="{{ old('email', $profile->email) }}"
                        class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1" for="phone">Phone</label>
                    <input id="phone" name="phone" type="text" value="{{ old('phone', $profile->phone) }}"
                        class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                </div>
            </div>
        </div>

        <!-- Directors -->
        <div class="bg-white rounded-lg shadow p-6 mb-6">
            <div class="flex justify-between items-center mb-4">
                <h2 class="text-lg font-semibold text-gray-800">Directors</h2>
                <button type="button" data-add-row="director-rows" data-template="director-template"
                    class="px-3 py-1.5 text-sm bg-indigo-600 text-white rounded-md hover:bg-indigo-700">Add director</button>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Name</th>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Appointed</th>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Resigned</th>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Email</th>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Phone</th>
                            <th class="px-3 py-2"></th>
                        </tr>
                    </thead>
                    <tbody id="director-rows" class="divide-y divide-gray-100">
                        @foreach (old('directors', $profile->directors) as $director)
                            <tr>
                                <td class="px-3 py-2"><input name="directors[{{ $loop->index }}][name]" type="text" value="{{ $director['name'] ?? $director->name }}"
                                    class="w-full rounded-md border-gray-300 shadow-sm"></td>
                                <td class="px-3 py-2"><input name="directors[{{ $loop->index }}][appointment_date]" type="date" value="{{ $director['appointment_date'] ?? optional($director->appointment_date)->format('Y-m-d') }}"
                                    class="rounded-md border-gray-300 shadow-sm"></td>
                                <td class="px-3 py-2"><input name="directors[{{ $loop->index }}][resignation_date]" type="date" value="{{ $director['resignation_date'] ?? optional($director->resignation_date)->format('Y-m-d') }}"
                                    class="rounded-md border-gray-300 shadow-sm"></td>
                                <td class="px-3 py-2"><input name="directors[{{ $loop->index }}][email]" type="email" value="{{ $director['email'] ?? $director->email }}"
                                    class="w-full rounded-md border-gray-300 shadow-sm"></td>
                                <td class="px-3 py-2"><input name="directors[{{ $loop->index }}][phone]" type="text" value="{{ $director['phone'] ?? $director->phone }}"
                                    class="rounded-md border-gray-300 shadow-sm"></td>
                                <td class="px-3 py-2 text-center">
                                    <button type="button" data-remove-row class="text-red-600 hover:text-red-800" title="Remove">&times;</button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <template id="director-template">
                <tr>
                    <td class="px-3 py-2"><input name="directors[__INDEX__][name]" type="text" class="w-full rounded-md border-gray-300 shadow-sm"></td>
                    <td class="px-3 py-2"><input name="directors[__INDEX__][appointment_date]" type="date" class="rounded-md border-gray-300 shadow-sm"></td>
                    <td class="px-3 py-2"><input name="directors[__INDEX__][resignation_date]" type="date" class="rounded-md border-gray-300 shadow-sm"></td>
                    <td class="px-3 py-2"><input name="directors[__INDEX__][email]" type="email" class="w-full rounded-md border-gray-300 shadow-sm"></td>
                    <td class="px-3 py-2"><input name="directors[__INDEX__][phone]" type="text" class="rounded-md border-gray-300 shadow-sm"></td>
                    <td class="px-3 py-2 text-center">
                        <button type="button" data-remove-row class="text-red-600 hover:text-red-800" title="Remove">&times;</button>
                    </td>
                </tr>
            </template>
        </div>

        <!-- Shareholders -->
        <div class="bg-white rounded-lg shadow p-6 mb-6">
            <div class="flex justify-between items-center mb-4">
                <h2 class="text-lg font-semibold text-gray-800">Shareholders</h2>
                <button type="button" data-add-row="shareholder-rows" data-template="shareholder-template"
                    class="px-3 py-1.5 text-sm bg-indigo-600 text-white rounded-md hover:bg-indigo-700">Add shareholder</button>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Name</th>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Class</th>
                            <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">Shares</th>
                            <th class="px-3 py-2 text-center text-xs font-medium text-gray-500 uppercase">Resident</th>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">ABN / TFN</th>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Email</th>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Address</th>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Contact / Bank</th>
                            <th class="px-3 py-2"></th>
                        </tr>
                    </thead>
                    <tbody id="shareholder-rows" class="divide-y divide-gray-100">
                        @foreach (old('shareholders', $profile->allShareholders) as $shareholder)
                            <tr>
                                <td class="px-3 py-2">
                                    @if(!is_array($shareholder))<input type="hidden" name="shareholders[{{ $loop->index }}][id]" value="{{ $shareholder->id }}">@endif
                                    <input name="shareholders[{{ $loop->index }}][name]" type="text" value="{{ $shareholder['name'] ?? $shareholder->name }}"
                                        class="w-full rounded-md border-gray-300 shadow-sm"></td>
                                <td class="px-3 py-2"><input name="shareholders[{{ $loop->index }}][share_class]" type="text" maxlength="10" value="{{ $shareholder['share_class'] ?? $shareholder->share_class }}"
                                    class="w-20 rounded-md border-gray-300 shadow-sm"></td>
                                <td class="px-3 py-2"><input name="shareholders[{{ $loop->index }}][shares_held]" type="number" min="0" value="{{ $shareholder['shares_held'] ?? $shareholder->shares_held }}"
                                    class="w-28 rounded-md border-gray-300 shadow-sm text-right"></td>
                                <td class="px-3 py-2 text-center"><input name="shareholders[{{ $loop->index }}][resident_for_tax]" type="checkbox" value="1"
                                    {{ (bool) ($shareholder['resident_for_tax'] ?? $shareholder->resident_for_tax) ? 'checked' : '' }} class="rounded border-gray-300"></td>
                                <td class="px-3 py-2">
                                    <select name="shareholders[{{ $loop->index }}][status]" class="rounded-md border-gray-300 shadow-sm">
                                        <option value="A" {{ ($shareholder['status'] ?? $shareholder->status) === 'A' ? 'selected' : '' }}>Active</option>
                                        <option value="I" {{ ($shareholder['status'] ?? $shareholder->status) === 'I' ? 'selected' : '' }}>Inactive</option>
                                    </select>
                                </td>
                                <td class="px-3 py-2">
                                    <input name="shareholders[{{ $loop->index }}][abn]" type="text" maxlength="11" value="{{ $shareholder['abn'] ?? $shareholder->abn }}" placeholder="ABN"
                                        class="w-28 rounded-md border-gray-300 shadow-sm">
                                    <input name="shareholders[{{ $loop->index }}][tfn]" type="text" maxlength="9" value="{{ $shareholder['tfn'] ?? $shareholder->tfn }}" placeholder="TFN"
                                        class="w-20 rounded-md border-gray-300 shadow-sm">
                                </td>
                                <td class="px-3 py-2"><input name="shareholders[{{ $loop->index }}][email]" type="email" value="{{ $shareholder['email'] ?? $shareholder->email }}"
                                    class="w-40 rounded-md border-gray-300 shadow-sm"></td>
                                <td class="px-3 py-2">
                                    <input name="shareholders[{{ $loop->index }}][address_line1]" type="text" value="{{ $shareholder['address_line1'] ?? $shareholder->address_line1 }}" placeholder="Street"
                                        class="w-40 rounded-md border-gray-300 shadow-sm mb-1">
                                    <input name="shareholders[{{ $loop->index }}][address_line2]" type="text" value="{{ $shareholder['address_line2'] ?? $shareholder->address_line2 }}" placeholder=""
                                        class="w-40 rounded-md border-gray-300 shadow-sm mb-1">
                                    <input name="shareholders[{{ $loop->index }}][suburb]" type="text" value="{{ $shareholder['suburb'] ?? $shareholder->suburb }}" placeholder="Suburb"
                                        class="w-40 rounded-md border-gray-300 shadow-sm mb-1">
                                    <div class="flex gap-1">
                                        <input name="shareholders[{{ $loop->index }}][state]" type="text" maxlength="3" value="{{ $shareholder['state'] ?? $shareholder->state }}" placeholder="State"
                                            class="w-14 rounded-md border-gray-300 shadow-sm">
                                        <input name="shareholders[{{ $loop->index }}][postcode]" type="text" maxlength="4" value="{{ $shareholder['postcode'] ?? $shareholder->postcode }}" placeholder="Postcode"
                                            class="w-20 rounded-md border-gray-300 shadow-sm">
                                        <input name="shareholders[{{ $loop->index }}][country]" type="text" maxlength="2" value="{{ $shareholder['country'] ?? $shareholder->country }}" placeholder="AU"
                                            class="w-12 rounded-md border-gray-300 shadow-sm">
                                    </div>
                                </td>
                                <td class="px-3 py-2">
                                    <input name="shareholders[{{ $loop->index }}][contact_name]" type="text" maxlength="60" value="{{ $shareholder['contact_name'] ?? $shareholder->contact_name }}" placeholder="Contact"
                                        class="w-32 rounded-md border-gray-300 shadow-sm mb-1">
                                    <input name="shareholders[{{ $loop->index }}][bank_bsb]" type="text" maxlength="7" value="{{ $shareholder['bank_bsb'] ?? $shareholder->bank_bsb }}" placeholder="BSB"
                                        class="w-20 rounded-md border-gray-300 shadow-sm mb-1">
                                    <input name="shareholders[{{ $loop->index }}][bank_account_number]" type="text" maxlength="9" value="{{ $shareholder['bank_account_number'] ?? $shareholder->bank_account_number }}" placeholder="Account"
                                        class="w-24 rounded-md border-gray-300 shadow-sm mb-1">
                                    <input name="shareholders[{{ $loop->index }}][bank_account_name]" type="text" maxlength="60" value="{{ $shareholder['bank_account_name'] ?? $shareholder->bank_account_name }}" placeholder="Account name"
                                        class="w-40 rounded-md border-gray-300 shadow-sm">
                                </td>
                                <td class="px-3 py-2 text-center">
                                    <button type="button" data-remove-row class="text-red-600 hover:text-red-800" title="Remove">&times;</button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="text-xs text-gray-500 mt-3">
                Share counts and class are derived from the shareholding ledger (see Shareholders under
                Shares &amp; Dividends) — they are only settable when adding a new shareholder here, which
                records an opening issue transaction. Bank details feed the manual dividend payment run.
            </p>
            <template id="shareholder-template">
                <tr>
                    <td class="px-3 py-2"><input name="shareholders[__INDEX__][name]" type="text" class="w-full rounded-md border-gray-300 shadow-sm"></td>
                    <td class="px-3 py-2"><input name="shareholders[__INDEX__][share_class]" type="text" maxlength="10" value="ORD" class="w-20 rounded-md border-gray-300 shadow-sm"></td>
                    <td class="px-3 py-2"><input name="shareholders[__INDEX__][shares_held]" type="number" min="0" value="0" class="w-28 rounded-md border-gray-300 shadow-sm text-right"></td>
                    <td class="px-3 py-2 text-center"><input name="shareholders[__INDEX__][resident_for_tax]" type="checkbox" value="1" checked class="rounded border-gray-300"></td>
                    <td class="px-3 py-2">
                        <select name="shareholders[__INDEX__][status]" class="rounded-md border-gray-300 shadow-sm">
                            <option value="A" selected>Active</option>
                            <option value="I">Inactive</option>
                        </select>
                    </td>
                    <td class="px-3 py-2">
                        <input name="shareholders[__INDEX__][abn]" type="text" maxlength="11" placeholder="ABN" class="w-28 rounded-md border-gray-300 shadow-sm">
                        <input name="shareholders[__INDEX__][tfn]" type="text" maxlength="9" placeholder="TFN" class="w-20 rounded-md border-gray-300 shadow-sm">
                    </td>
                    <td class="px-3 py-2"><input name="shareholders[__INDEX__][email]" type="email" class="w-40 rounded-md border-gray-300 shadow-sm"></td>
                    <td class="px-3 py-2">
                        <input name="shareholders[__INDEX__][address_line1]" type="text" placeholder="Street" class="w-40 rounded-md border-gray-300 shadow-sm mb-1">
                        <input name="shareholders[__INDEX__][address_line2]" type="text" placeholder="" class="w-40 rounded-md border-gray-300 shadow-sm mb-1">
                        <input name="shareholders[__INDEX__][suburb]" type="text" placeholder="Suburb" class="w-40 rounded-md border-gray-300 shadow-sm mb-1">
                        <div class="flex gap-1">
                            <input name="shareholders[__INDEX__][state]" type="text" maxlength="3" placeholder="State" class="w-14 rounded-md border-gray-300 shadow-sm">
                            <input name="shareholders[__INDEX__][postcode]" type="text" maxlength="4" placeholder="Postcode" class="w-20 rounded-md border-gray-300 shadow-sm">
                            <input name="shareholders[__INDEX__][country]" type="text" maxlength="2" placeholder="AU" class="w-12 rounded-md border-gray-300 shadow-sm">
                        </div>
                    </td>
                    <td class="px-3 py-2">
                        <input name="shareholders[__INDEX__][contact_name]" type="text" maxlength="60" placeholder="Contact" class="w-32 rounded-md border-gray-300 shadow-sm mb-1">
                        <input name="shareholders[__INDEX__][bank_bsb]" type="text" maxlength="7" placeholder="BSB" class="w-20 rounded-md border-gray-300 shadow-sm mb-1">
                        <input name="shareholders[__INDEX__][bank_account_number]" type="text" maxlength="9" placeholder="Account" class="w-24 rounded-md border-gray-300 shadow-sm mb-1">
                        <input name="shareholders[__INDEX__][bank_account_name]" type="text" maxlength="60" placeholder="Account name" class="w-40 rounded-md border-gray-300 shadow-sm">
                    </td>
                    <td class="px-3 py-2 text-center">
                        <button type="button" data-remove-row class="text-red-600 hover:text-red-800" title="Remove">&times;</button>
                    </td>
                </tr>
            </template>
        </div>

        <div class="flex justify-end">
            <button type="submit" class="px-6 py-2 bg-indigo-600 text-white rounded-md hover:bg-indigo-700">Save company details</button>
        </div>
    </form>

    <script>
        document.addEventListener('click', function (event) {
            const add = event.target.closest('[data-add-row]');
            if (add) {
                const tbody = document.getElementById(add.dataset.addRow);
                const template = document.getElementById(add.dataset.template);
                const index = tbody.querySelectorAll('tr').length;
                const row = document.createElement('tr');
                row.innerHTML = template.innerHTML.split('__INDEX__').join(index);
                tbody.appendChild(row);
            }

            const remove = event.target.closest('[data-remove-row]');
            if (remove) {
                remove.closest('tr').remove();
            }
        });
    </script>
@endsection

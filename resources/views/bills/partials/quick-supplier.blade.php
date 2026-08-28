@php
    // Quick-add supplier modal opened by the "+" button beside the supplier
    // select on the bill create/edit forms. Submits via fetch to
    // suppliers.quick-store so the in-progress bill form is not lost; the
    // new supplier is appended to #supplierSelect and selected on success.
@endphp
<div id="quickSupplierModal"
    class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4">
    <div class="bg-white rounded-lg shadow-xl max-w-2xl w-full max-h-[90vh] overflow-y-auto p-6">
        <h3 class="text-lg font-semibold text-gray-800 mb-4">Add Supplier</h3>
        <form id="quickSupplierForm">
            @include('suppliers.partials.fields')
            <div id="quickSupplierErrors" class="hidden text-red-600 text-sm mt-3"></div>
            <div class="mt-6 flex justify-end gap-3">
                <button type="button" id="quickSupplierCancel"
                    class="px-4 py-2 text-gray-700 hover:text-gray-900">Cancel</button>
                <button type="submit"
                    class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg">Save Supplier</button>
            </div>
        </form>
    </div>
</div>

@push('scripts')
    <script>
        (function() {
            const modal = document.getElementById('quickSupplierModal');
            const form = document.getElementById('quickSupplierForm');
            const openBtn = document.getElementById('addSupplierBtn');
            const cancelBtn = document.getElementById('quickSupplierCancel');
            const errorsDiv = document.getElementById('quickSupplierErrors');
            const supplierSelect = document.getElementById('supplierSelect');

            function openModal() {
                form.reset();
                errorsDiv.classList.add('hidden');
                errorsDiv.innerHTML = '';
                modal.classList.remove('hidden');
                form.querySelector('input[name="name"]').focus();
            }

            function closeModal() {
                modal.classList.add('hidden');
            }

            openBtn.addEventListener('click', openModal);
            cancelBtn.addEventListener('click', closeModal);
            modal.addEventListener('click', e => {
                if (e.target === modal) closeModal();
            });

            form.addEventListener('submit', async function(e) {
                e.preventDefault();
                const submitBtn = form.querySelector('button[type="submit"]');
                submitBtn.disabled = true;

                try {
                    const response = await fetch('{{ route('suppliers.quick-store') }}', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        },
                        body: JSON.stringify(Object.fromEntries(new FormData(form))),
                    });

                    if (response.ok) {
                        const supplier = await response.json();
                        supplierSelect.add(new Option(supplier.name, supplier.id, true, true));
                        closeModal();
                        return;
                    }

                    if (response.status === 422) {
                        const data = await response.json();
                        errorsDiv.innerHTML = Object.values(data.errors || {}).flat().join('<br>');
                    } else {
                        errorsDiv.textContent = 'Could not create the supplier. Please try again.';
                    }
                    errorsDiv.classList.remove('hidden');
                } catch (err) {
                    errorsDiv.textContent = 'Could not create the supplier. Please try again.';
                    errorsDiv.classList.remove('hidden');
                } finally {
                    submitBtn.disabled = false;
                }
            });
        })();
    </script>
@endpush

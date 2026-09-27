@php($tabs = [
    ['key' => 'library', 'label' => 'Skill library', 'route' => 'skills.index'],
    ['key' => 'employees', 'label' => 'Employee skills', 'route' => 'skills.employees.index'],
    ['key' => 'services', 'label' => 'Service skills', 'route' => 'skills.services.index'],
])
<div class="mb-6 border-b border-gray-200">
    <nav class="-mb-px flex gap-6">
        @foreach ($tabs as $tab)
            <a href="{{ route($tab['route']) }}"
                class="pb-3 text-sm font-medium {{ $active === $tab['key']
                    ? 'border-b-2 border-indigo-600 text-indigo-600'
                    : 'border-b-2 border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' }}">
                {{ $tab['label'] }}
            </a>
        @endforeach
    </nav>
</div>

@props([
    'active' => 0,
    'tabs' => [],
])

<div x-data="{ activeTab: {{ $active }} }" {{ $attributes }}>
    {{-- Tab Headers --}}
    <div class="border-b border-border" role="tablist">
        <div class="flex gap-0 -mb-px">
            @foreach($tabs as $index => $tab)
                <button
                    @click="activeTab = {{ $index }}"
                    :class="activeTab === {{ $index }}
                        ? 'border-brand text-brand'
                        : 'border-transparent text-text-muted hover:text-text-secondary hover:border-border'"
                    class="border-b-2 px-4 py-3 text-sm font-medium transition-colors duration-200"
                    role="tab"
                    :aria-selected="activeTab === {{ $index }}"
                >
                    {{ $tab }}
                </button>
            @endforeach
        </div>
    </div>

    {{-- Tab Panels --}}
    <div class="pt-6">
        {{ $slot }}
    </div>
</div>

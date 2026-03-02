@props([
    'heading' => 'Co zawiera usługa',
    'layout' => 'simple',
    'service' => null,
])

@php
    // Get features from service
    $features = $service->features ?? [];

    // Handle empty features gracefully
    if (!is_array($features) || count($features) === 0) {
        return;
    }
@endphp

<div class="service-features mb-12">
    <div class="bg-white rounded-2xl p-8 shadow-sm">
        {{-- Heading --}}
        @if($heading)
            <h3 class="text-2xl md:text-3xl font-bold text-gray-900 mb-6">{{ $heading }}</h3>
        @endif

        {{-- Features List - Simple Layout (default) --}}
        @if($layout === 'simple')
            <ul class="space-y-3">
                @foreach($features as $feature)
                    <li class="flex items-start gap-3">
                        <x-heroicon-s-check-circle class="w-6 h-6 text-green-500 flex-shrink-0 mt-0.5" />
                        <span class="text-gray-700 text-lg leading-relaxed">{{ $feature }}</span>
                    </li>
                @endforeach
            </ul>
        @endif

        {{-- Features Grid - 2 Columns --}}
        @if($layout === 'grid')
            <ul class="grid grid-cols-1 md:grid-cols-2 gap-4">
                @foreach($features as $feature)
                    <li class="flex items-start gap-3">
                        <x-heroicon-s-check-circle class="w-6 h-6 text-green-500 flex-shrink-0 mt-0.5" />
                        <span class="text-gray-700 text-lg leading-relaxed">{{ $feature }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</div>

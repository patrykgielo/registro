@props([
    'type' => 'info',           // success|error|warning|info
    'message' => '',            // Alert text (or use slot)
    'title' => null,            // Optional bold heading
    'dismissible' => false,     // Show X close button
    'icon' => true,             // Show Heroicon icon
])

@php
$baseClasses = 'rounded-xl p-4 backdrop-blur-sm border-l-4 flex items-start gap-3';

$typeConfig = [
    'success' => [
        'container' => 'bg-green-50 border-green-500 text-green-900',
        'icon' => 'check-circle',
        'iconColor' => 'text-green-500',
    ],
    'error' => [
        'container' => 'bg-red-50 border-red-500 text-red-900',
        'icon' => 'x-circle',
        'iconColor' => 'text-red-500',
    ],
    'warning' => [
        'container' => 'bg-orange-50 border-orange-500 text-orange-900',
        'icon' => 'exclamation-triangle',
        'iconColor' => 'text-orange-500',
    ],
    'info' => [
        'container' => 'bg-blue-50 border-blue-500 text-blue-900',
        'icon' => 'information-circle',
        'iconColor' => 'text-blue-500',
    ],
];

$config = $typeConfig[$type];
$classes = $baseClasses . ' ' . $config['container'];
@endphp

<div
    {{ $attributes->merge(['class' => $classes]) }}
    x-data="{ show: true }"
    x-show="show"
    x-transition:enter="transition ease-out duration-300"
    x-transition:enter-start="opacity-0 transform -translate-y-2"
    x-transition:enter-end="opacity-100 transform translate-y-0"
    x-transition:leave="transition ease-in duration-200"
    x-transition:leave-start="opacity-100 transform translate-y-0"
    x-transition:leave-end="opacity-0 transform -translate-y-2"
    style="transition-timing-function: cubic-bezier(0.36, 0.66, 0.04, 1);"
    role="alert">

    @if($icon)
        <div class="flex-shrink-0">
            <x-dynamic-component
                :component="'heroicon-o-' . $config['icon']"
                class="w-5 h-5 {{ $config['iconColor'] }}" />
        </div>
    @endif

    <div class="flex-1 min-w-0">
        @if($title)
            <p class="font-semibold mb-1">{{ $title }}</p>
        @endif
        <p class="text-sm">
            {{ $message ?: $slot }}
        </p>
    </div>

    @if($dismissible)
        <button
            @click="show = false"
            type="button"
            class="flex-shrink-0 ml-auto rounded-full p-1.5 hover:bg-black/5 active:scale-95 transition-all duration-150 focus:outline-none focus:ring-2 focus:ring-offset-2 {{ $config['iconColor'] }}"
            style="transition-timing-function: cubic-bezier(0.36, 0.66, 0.04, 1);"
            aria-label="Zamknij">
            <x-heroicon-o-x-mark class="w-4 h-4" />
        </button>
    @endif
</div>

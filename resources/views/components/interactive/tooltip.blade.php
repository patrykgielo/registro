@props([
    'text' => '',
    'position' => 'top',
])

@php
$positionClasses = match($position) {
    'top' => 'bottom-full left-1/2 -translate-x-1/2 mb-2',
    'bottom' => 'top-full left-1/2 -translate-x-1/2 mt-2',
    'left' => 'right-full top-1/2 -translate-y-1/2 mr-2',
    'right' => 'left-full top-1/2 -translate-y-1/2 ml-2',
    default => 'bottom-full left-1/2 -translate-x-1/2 mb-2',
};
@endphp

<div x-data="{ show: false }" class="relative inline-flex" {{ $attributes }}>
    <div @mouseenter="show = true" @mouseleave="show = false" @focus="show = true" @blur="show = false">
        {{ $slot }}
    </div>

    <div
        x-show="show"
        x-transition:enter="duration-150 ease-out"
        x-transition:enter-start="opacity-0 scale-95"
        x-transition:enter-end="opacity-100 scale-100"
        x-transition:leave="duration-100 ease-in"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        class="absolute {{ $positionClasses }} z-[var(--z-tooltip)] whitespace-nowrap rounded-md bg-text-primary px-2.5 py-1.5 text-xs text-text-inverse shadow-md pointer-events-none"
        role="tooltip"
        x-cloak
    >
        {{ $text }}
    </div>
</div>

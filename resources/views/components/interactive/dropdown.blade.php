@props([
    'align' => 'right',
    'width' => '48',
])

@php
$alignClasses = match($align) {
    'left' => 'left-0',
    'right' => 'right-0',
    'center' => 'left-1/2 -translate-x-1/2',
    default => 'right-0',
};
$widthClasses = match($width) {
    '48' => 'w-48',
    '56' => 'w-56',
    '64' => 'w-64',
    default => 'w-48',
};
@endphp

<div x-data="{ open: false }" @click.outside="open = false" class="relative" {{ $attributes }}>
    {{-- Trigger --}}
    <div @click="open = !open" class="cursor-pointer">
        {{ $trigger }}
    </div>

    {{-- Menu --}}
    <div
        x-show="open"
        x-transition:enter="duration-150 ease-out"
        x-transition:enter-start="opacity-0 scale-95"
        x-transition:enter-end="opacity-100 scale-100"
        x-transition:leave="duration-100 ease-in"
        x-transition:leave-start="opacity-100 scale-100"
        x-transition:leave-end="opacity-0 scale-95"
        class="absolute {{ $alignClasses }} {{ $widthClasses }} z-[var(--z-dropdown)] mt-2 rounded-lg border border-border bg-surface-raised shadow-lg py-1"
        role="menu"
        @keydown.escape="open = false"
        x-cloak
    >
        {{ $slot }}
    </div>
</div>

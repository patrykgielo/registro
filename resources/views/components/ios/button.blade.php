@props([
    'variant' => 'primary',      // primary|secondary|ghost|danger
    'type' => 'button',          // submit|button|reset
    'label' => '',               // Button text (or use slot)
    'icon' => null,              // Heroicon name (e.g., 'arrow-right')
    'iconPosition' => 'left',    // left|right
    'loading' => false,          // Show spinner state
    'fullWidth' => false,        // Apply w-full
    'href' => null,              // Convert to <a> tag
    'disabled' => false,         // Disabled state
])

@php
$baseClasses = 'inline-flex items-center justify-center min-h-[44px] px-6 py-3 rounded-lg font-semibold text-base transition-all duration-300 focus:outline-none focus:ring-2 focus:ring-offset-2 disabled:opacity-50 disabled:cursor-not-allowed active:scale-95';

$variantClasses = [
    'primary' => 'bg-primary-500 text-white shadow-lg hover:shadow-xl hover:scale-[1.02] focus:ring-primary-500',
    'secondary' => 'bg-neutral-100 text-neutral-900 hover:bg-neutral-200 hover:scale-[1.02] focus:ring-neutral-400',
    'ghost' => 'bg-transparent text-primary-500 hover:underline hover:text-primary-600 focus:ring-primary-500',
    'danger' => 'bg-red-500 text-white shadow-lg hover:shadow-xl hover:scale-[1.02] focus:ring-red-500',
    'inverse' => 'bg-white text-primary-600 shadow-lg hover:bg-gray-100 hover:shadow-xl hover:scale-[1.02] focus:ring-white',
    'outline-light' => 'bg-transparent border-2 border-white text-white hover:bg-white hover:text-primary-600 focus:ring-white',
];

$widthClass = $fullWidth ? 'w-full' : '';
$classes = $baseClasses . ' ' . $variantClasses[$variant] . ' ' . $widthClass;

$tag = $href ? 'a' : 'button';
$attributes = $attributes->merge(['class' => $classes]);

if ($tag === 'button') {
    $attributes = $attributes->merge(['type' => $type]);
}

if ($href) {
    $attributes = $attributes->merge(['href' => $href]);
}

if ($disabled || $loading) {
    $attributes = $attributes->merge(['disabled' => true]);
}
@endphp

<{{ $tag }} {{ $attributes }} style="transition-timing-function: cubic-bezier(0.36, 0.66, 0.04, 1);">
    @if($loading)
        <svg class="animate-spin h-5 w-5 {{ $label || $slot->isNotEmpty() ? 'mr-2' : '' }}" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
        </svg>
    @elseif($icon && $iconPosition === 'left')
        <x-dynamic-component :component="'heroicon-o-' . $icon" class="w-5 h-5 {{ $label || $slot->isNotEmpty() ? 'mr-2' : '' }}" />
    @endif

    {{ $label ?: $slot }}

    @if($icon && $iconPosition === 'right' && !$loading)
        <x-dynamic-component :component="'heroicon-o-' . $icon" class="w-5 h-5 {{ $label || $slot->isNotEmpty() ? 'ml-2' : '' }}" />
    @endif
</{{ $tag }}>

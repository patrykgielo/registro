@props([
    'variant' => 'primary',
    'size' => 'md',
    'href' => null,
    'icon' => null,
    'iconRight' => null,
    'loading' => false,
    'disabled' => false,
])

@php
$tag = $href ? 'a' : 'button';

$variants = [
    'primary' => 'bg-brand text-text-inverse hover:bg-brand-hover shadow-sm active:scale-[0.98]',
    'secondary' => 'bg-surface-raised border border-border text-text-primary hover:bg-surface-sunken hover:border-border-strong shadow-xs',
    'ghost' => 'text-text-secondary hover:text-text-primary hover:bg-surface-sunken',
    'danger' => 'bg-error text-text-inverse hover:opacity-90 shadow-sm active:scale-[0.98]',
    'link' => 'text-brand hover:text-brand-hover underline-offset-4 hover:underline p-0 min-h-0',
];

$sizes = [
    'sm' => 'text-sm px-3 py-1.5 gap-1.5',
    'md' => 'text-sm px-4 py-2.5 gap-2',
    'lg' => 'text-base px-6 py-3 gap-2.5',
    'xl' => 'text-base px-8 py-4 gap-3',
];

$baseClasses = 'inline-flex items-center justify-center font-medium rounded-lg transition-all duration-200 ease-out focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50 focus-visible:ring-offset-2 cursor-pointer select-none';
$variantClasses = $variants[$variant] ?? $variants['primary'];
$sizeClasses = $sizes[$size] ?? $sizes['md'];
$disabledClasses = $disabled ? 'opacity-50 pointer-events-none' : '';
@endphp

<{{ $tag }}
    @if($href) href="{{ $href }}" @endif
    @if($tag === 'button') type="{{ $attributes->get('type', 'button') }}" @endif
    @disabled($disabled)
    @if($loading) aria-busy="true" @endif
    {{ $attributes->class([$baseClasses, $variantClasses, $sizeClasses, $disabledClasses]) }}
>
    @if($loading)
        <svg class="animate-spin -ml-1 h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
        </svg>
    @elseif($icon)
        <x-dynamic-component :component="'heroicon-m-' . $icon" class="h-4 w-4 shrink-0" />
    @endif

    {{ $slot }}

    @if($iconRight)
        <x-dynamic-component :component="'heroicon-m-' . $iconRight" class="h-4 w-4 shrink-0" />
    @endif
</{{ $tag }}>

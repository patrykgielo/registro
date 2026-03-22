@props([
    'variant' => 'default',
    'size' => 'md',
    'dot' => false,
    'icon' => null,
])

@php
$variants = [
    'default' => 'bg-surface-sunken text-text-secondary',
    'brand' => 'bg-brand-subtle text-brand',
    'success' => 'bg-success/10 text-success',
    'warning' => 'bg-warning/10 text-warning',
    'error' => 'bg-error/10 text-error',
    'info' => 'bg-info/10 text-info',
];

$sizes = [
    'sm' => 'text-xs px-2 py-0.5',
    'md' => 'text-xs px-2.5 py-1',
    'lg' => 'text-sm px-3 py-1',
];

$dotColors = [
    'default' => 'bg-text-muted',
    'brand' => 'bg-brand',
    'success' => 'bg-success',
    'warning' => 'bg-warning',
    'error' => 'bg-error',
    'info' => 'bg-info',
];
@endphp

<span {{ $attributes->class([
    'inline-flex items-center gap-1.5 rounded-full font-medium',
    $variants[$variant] ?? $variants['default'],
    $sizes[$size] ?? $sizes['md'],
]) }}>
    @if($dot)
        <span class="h-1.5 w-1.5 rounded-full {{ $dotColors[$variant] ?? $dotColors['default'] }}"></span>
    @elseif($icon)
        <x-dynamic-component :component="'heroicon-m-' . $icon" class="h-3.5 w-3.5 shrink-0" />
    @endif

    {{ $slot }}
</span>

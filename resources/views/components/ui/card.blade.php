@props([
    'padding' => true,
    'hover' => false,
    'href' => null,
])

@php
$tag = $href ? 'a' : 'div';
@endphp

<{{ $tag }}
    @if($href) href="{{ $href }}" @endif
    {{ $attributes->class([
        'rounded-xl border border-border bg-surface-raised',
        'shadow-xs',
        'p-6' => $padding,
        'transition-all duration-200 ease-out hover:shadow-md hover:border-border-strong hover:-translate-y-0.5' => $hover,
        'cursor-pointer' => $href || $hover,
    ]) }}
>
    {{ $slot }}
</{{ $tag }}>

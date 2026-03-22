@props([
    'src' => null,
    'alt' => '',
    'size' => 'md',
    'initials' => null,
])

@php
$sizes = [
    'sm' => 'h-8 w-8 text-xs',
    'md' => 'h-10 w-10 text-sm',
    'lg' => 'h-12 w-12 text-base',
    'xl' => 'h-16 w-16 text-lg',
];
$sizeClasses = $sizes[$size] ?? $sizes['md'];
@endphp

@if($src)
    <img
        src="{{ $src }}"
        alt="{{ $alt }}"
        {{ $attributes->class([$sizeClasses, 'rounded-full object-cover']) }}
        loading="lazy"
    />
@else
    <span {{ $attributes->class([
        $sizeClasses,
        'inline-flex items-center justify-center rounded-full bg-brand-subtle text-brand font-medium',
    ]) }}>
        {{ $initials ?? mb_substr($alt, 0, 2) }}
    </span>
@endif

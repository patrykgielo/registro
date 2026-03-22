@props([
    'dark' => false,
    'narrow' => false,
    'spacing' => 'default',
])

@php
$spacingClasses = match($spacing) {
    'sm' => 'py-12 md:py-16',
    'lg' => 'py-20 md:py-28',
    'none' => '',
    default => 'py-16 md:py-24',
};
@endphp

<section {{ $attributes->class([
    $spacingClasses,
    'bg-section-dark' => $dark,
]) }}>
    <x-layout.container :narrow="$narrow">
        {{ $slot }}
    </x-layout.container>
</section>

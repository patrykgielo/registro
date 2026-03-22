@props([
    'narrow' => false,
])

<div {{ $attributes->class([
    'mx-auto w-full px-4 sm:px-6 lg:px-8',
    'max-w-7xl' => !$narrow,
    'max-w-3xl' => $narrow,
]) }}>
    {{ $slot }}
</div>

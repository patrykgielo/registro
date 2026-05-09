@props([
    'cols' => 3,
    'gap' => '6',
])

@php
$colClasses = match((int) $cols) {
    1 => 'grid-cols-1',
    2 => 'grid-cols-1 md:grid-cols-2',
    3 => 'grid-cols-1 md:grid-cols-2 lg:grid-cols-3',
    4 => 'grid-cols-1 md:grid-cols-2 lg:grid-cols-4',
    default => 'grid-cols-1 md:grid-cols-2 lg:grid-cols-3',
};
@endphp

<div {{ $attributes->class(["grid $colClasses gap-$gap"]) }}>
    {{ $slot }}
</div>

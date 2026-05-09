@props([
    'gap' => '4',
])

<div {{ $attributes->class(["flex flex-col gap-$gap"]) }}>
    {{ $slot }}
</div>

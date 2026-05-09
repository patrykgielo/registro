@props([
    'src' => null,
    'alt' => '',
    'aspect' => '16/9',
    'rounded' => 'lg',
    'lazy' => true,
])

@if($src)
<div {{ $attributes->class(["overflow-hidden rounded-$rounded"]) }}>
    <img
        src="{{ Str::startsWith($src, ['http', '/']) ? $src : Storage::url($src) }}"
        alt="{{ $alt }}"
        class="h-full w-full object-cover"
        style="aspect-ratio: {{ $aspect }};"
        @if($lazy) loading="lazy" decoding="async" @endif
    />
</div>
@endif

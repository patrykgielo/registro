@props(['data' => []])

@php
    $html = $data['html'] ?? '';
    $containerWrapper = $data['container_wrapper'] ?? true;
@endphp

<x-blocks.partials.section-wrapper :data="$data">
    @if($containerWrapper)
        <div class="container mx-auto">
            {!! $html !!}
        </div>
    @else
        {!! $html !!}
    @endif
</x-blocks.partials.section-wrapper>

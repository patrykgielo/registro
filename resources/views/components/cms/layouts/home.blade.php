{{--
    Homepage Layout: Special Full-Control Layout

    Use for: Homepage only, custom marketing pages.
    No wrapper, no container - full control over sections.
    Builder blocks render edge-to-edge for maximum design flexibility.
--}}

@props([
    'model',  // Page instance (typically homepage)
])

<main id="main-content">
{{-- Full-width sections, no container --}}
@if($model->content)
    @foreach($model->content as $block)
        @if($block['type'] === 'hero')
            <x-content-blocks.hero :data="$block['data']" />

        @elseif($block['type'] === 'content_grid')
            <x-content-blocks.content-grid :data="$block['data']" />

        @elseif($block['type'] === 'feature_list')
            <x-content-blocks.feature-list :data="$block['data']" />

        @elseif($block['type'] === 'cta_banner')
            <x-content-blocks.cta-banner :data="$block['data']" />

        @elseif($block['type'] === 'text_block')
            <x-content-blocks.text-block :data="$block['data']" />

        @elseif($block['type'] === 'custom_html')
            <x-content-blocks.custom-html :data="$block['data']" />
        @endif
    @endforeach
@endif

{{-- Body content (if exists) in container --}}
@if($model->body)
    <div class="container mx-auto px-4 py-8">
        <div class="prose prose-lg max-w-none">
            {!! $model->body !!}
        </div>
    </div>
@endif
</main>

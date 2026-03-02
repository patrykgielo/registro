{{--
    Full-Width Layout: Edge-to-Edge

    Use for: Landing pages, hero sections, galleries.
    Max-width: None (spans full viewport)
    Builder blocks render edge-to-edge for maximum design flexibility.
    Body content (if exists) renders in a container below blocks.
--}}

@props([
    'model',  // Page|Post|Promotion instance
    'type',   // 'page'|'post'|'promotion'
])

<main id="main-content" class="w-full">
    {{-- Builder Blocks FIRST (full-width, edge-to-edge) --}}
    @if($model->content)
        <x-cms.partials.builder-blocks :blocks="$model->content" fullWidth />
    @endif

    {{-- Body Content (if exists) - in container --}}
    @if($model->body)
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
            <article class="prose prose-lg prose-registro max-w-none">
                {!! $model->body !!}
            </article>
        </div>
    @endif

</main>

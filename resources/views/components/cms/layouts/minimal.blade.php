{{--
    Minimal Layout: Narrow Reading Column

    Use for: Long-form articles, documentation, privacy policies.
    Max-width: 1280px (max-w-7xl)
    Typography-focused with generous line-height
--}}

@props([
    'model',  // Page|Post|Promotion instance
    'type',   // 'page'|'post'|'promotion'
])

<main id="main-content" class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
    <article class="p-8 md:p-12">
        {{-- Header --}}
        <x-cms.partials.content-header :model="$model" :type="$type" minimal />

        {{-- Body Content (enhanced typography) --}}
        @if($model->body)
            <div class="prose prose-lg prose-registro max-w-none mb-8">
                {!! $model->body !!}
            </div>
        @endif

        {{-- Builder Blocks (constrained to prose width) --}}
        @if($model->content)
            <x-cms.partials.builder-blocks :blocks="$model->content" minimal />
        @endif

        {{-- Footer --}}
        <x-cms.partials.content-footer :model="$model" :type="$type" minimal />
    </article>

</main>

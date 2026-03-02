{{--
    Default Layout: 8+4 Grid (Content + Sidebar)

    Use for: Blog posts, articles with related content.
    Max-width: 1440px
    Responsive: Full-width on mobile, grid on lg+
--}}

@props([
    'model',         // Page|Post|Promotion instance
    'type',          // 'page'|'post'|'promotion'
    'relatedPosts',  // Collection of related posts (for posts)
    'recentPosts',   // Collection of recent posts (for pages/promotions)
])

<div class="max-w-[1440px] mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-8">

        {{-- Main Content Area (8 columns) --}}
        <main id="main-content" class="lg:col-span-8">
            <article class="p-8">
                {{-- Header --}}
                <x-cms.partials.content-header :model="$model" :type="$type" />

                {{-- Body Content --}}
                @if($model->body)
                    <div class="prose prose-lg prose-registro max-w-none mb-8">
                        {!! $model->body !!}
                    </div>
                @endif

                {{-- Builder Blocks --}}
                @if($model->content)
                    <x-cms.partials.builder-blocks :blocks="$model->content" />
                @endif

                {{-- Footer --}}
                <x-cms.partials.content-footer :model="$model" :type="$type" />
            </article>
        </main>

        {{-- Sidebar (4 columns) --}}
        <aside class="lg:col-span-4">
            <x-cms.partials.sidebar :model="$model" :type="$type" :relatedPosts="$relatedPosts" :recentPosts="$recentPosts" />
        </aside>

    </div>
</div>

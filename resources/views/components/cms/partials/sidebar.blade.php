{{--
    Sidebar Partial (Default Layout Only)

    Displays related content, recent posts, categories, CTA widgets.
    Data is passed from controller via layout-wrapper for proper MVC separation.
--}}

@props([
    'model',         // Page|Post|Promotion instance
    'type',          // 'page'|'post'|'promotion'
    'relatedPosts',  // Collection of related posts (for posts)
    'recentPosts',   // Collection of recent posts (for pages/promotions)
])

<div class="space-y-6">
    {{-- Related Posts Widget (for Posts) --}}
    @if($type === 'post' && $model->category && $relatedPosts)
        <div class="bg-white rounded-lg shadow-lg p-6">
            <h3 class="text-lg font-bold text-gray-900 mb-4">Powiązane artykuły</h3>

            @if($relatedPosts->count() > 0)
                <ul class="space-y-3">
                    @foreach($relatedPosts as $post)
                        <li>
                            <a href="{{ $post->url }}" class="text-primary-400 hover:text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-400 rounded transition-colors">
                                {{ $post->title }}
                            </a>
                            <p class="text-sm text-gray-500 mt-1">
                                {{ $post->published_at->format('d.m.Y') }}
                            </p>
                        </li>
                    @endforeach
                </ul>
            @else
                <p class="text-gray-600 text-sm">Brak powiązanych artykułów</p>
            @endif
        </div>
    @endif

    {{-- CTA Widget removed — it hardcoded "Profesjonalny detailing dla
         Twojego auta" (mobile car-wash copy), unconditionally rendered on
         every CMS page/post of every tenant via cms.layouts.default. A
         shared layout partial isn't the place to guess what a tenant sells,
         and this project's rule is "no invented replacement copy" — see
         app/docs/features/tenant-branding.md. If a CTA belongs here, it
         needs to be content-driven (a CMS block, or a per-tenant setting),
         not a hardcoded string — that's a feature decision for the owner,
         not something to reword in a branding cleanup. --}}

    {{-- Recent Posts (for Pages/Promotions) --}}
    @if(in_array($type, ['page', 'promotion']) && $recentPosts)
        <div class="bg-white rounded-lg shadow-lg p-6">
            <h3 class="text-lg font-bold text-gray-900 mb-4">Najnowsze wpisy</h3>

            @if($recentPosts->count() > 0)
                <ul class="space-y-3">
                    @foreach($recentPosts as $post)
                        <li>
                            <a href="{{ $post->url }}" class="text-primary-400 hover:text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-400 rounded transition-colors">
                                {{ $post->title }}
                            </a>
                            <p class="text-sm text-gray-500 mt-1">
                                {{ $post->published_at->format('d.m.Y') }}
                            </p>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    @endif
</div>

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

    {{-- CTA Widget --}}
    <div class="bg-gradient-to-br from-primary-400 to-primary-500 rounded-lg shadow-lg p-6 text-white">
        <h3 class="text-xl font-bold mb-2">Umów wizytę już dziś!</h3>
        <p class="text-primary-100 mb-4">
            Profesjonalny detailing dla Twojego auta. Rezerwacja online w 60 sekund.
        </p>
        <a href="{{ route('home') }}"
           class="block w-full text-center min-h-11 px-6 py-3 bg-white text-primary-600 font-semibold rounded-lg
                  hover:bg-primary-50 focus:outline-none focus:ring-2 focus:ring-white focus:ring-offset-2 focus:ring-offset-primary-500 transition-colors">
            Zarezerwuj termin
        </a>
    </div>

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

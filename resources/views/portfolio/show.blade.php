@extends('layouts.app')

@section('content')
<div class="max-w-6xl mx-auto">
    <article class="bg-white rounded-lg shadow-lg p-8">
        <header class="mb-8">
            @if($portfolioItem->category)
                <span class="inline-block px-3 py-1 bg-purple-100 text-purple-800 text-sm font-semibold rounded-full mb-4">
                    {{ $portfolioItem->category->name }}
                </span>
            @endif

            <h1 class="text-4xl font-bold text-gray-900 mb-6">{{ $portfolioItem->title }}</h1>
        </header>

        @if($portfolioItem->before_image || $portfolioItem->after_image)
            <div class="mb-8 grid grid-cols-1 md:grid-cols-2 gap-6">
                @if($portfolioItem->before_image)
                    <div class="relative">
                        <span class="absolute top-4 left-4 bg-red-600 text-white px-3 py-1 rounded-lg font-semibold z-10">
                            PRZED
                        </span>
                        <img src="{{ Storage::url($portfolioItem->before_image) }}"
                             alt="{{ $portfolioItem->title }} - Przed"
                             class="w-full h-96 object-cover rounded-lg">
                    </div>
                @endif

                @if($portfolioItem->after_image)
                    <div class="relative">
                        <span class="absolute top-4 left-4 bg-green-600 text-white px-3 py-1 rounded-lg font-semibold z-10">
                            PO
                        </span>
                        <img src="{{ Storage::url($portfolioItem->after_image) }}"
                             alt="{{ $portfolioItem->title }} - Po"
                             class="w-full h-96 object-cover rounded-lg">
                    </div>
                @endif
            </div>
        @endif

        @if($portfolioItem->body)
            <div class="prose prose-registro max-w-none mb-8">
                {!! $portfolioItem->body !!}
            </div>
        @endif

        @if($portfolioItem->gallery && count($portfolioItem->gallery) > 0)
            <div class="mb-8">
                <h2 class="text-2xl font-bold text-gray-900 mb-4">Galeria zdjęć</h2>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    @foreach($portfolioItem->gallery as $image)
                        <img src="{{ Storage::url($image) }}"
                             alt="{{ $portfolioItem->title }}"
                             class="w-full h-64 object-cover rounded-lg hover:scale-105 transition-transform cursor-pointer">
                    @endforeach
                </div>
            </div>
        @endif

        @if($portfolioItem->content)
            @foreach($portfolioItem->content as $block)
                @if($block['type'] === 'image')
                    <div class="mb-8 @if($block['data']['size'] === 'full') w-full @elseif($block['data']['size'] === 'large') max-w-3xl mx-auto @elseif($block['data']['size'] === 'medium') max-w-2xl mx-auto @else max-w-xl mx-auto @endif">
                        <img src="{{ Storage::url($block['data']['image']) }}"
                             alt="{{ $block['data']['alt'] ?? '' }}"
                             class="w-full rounded-lg">
                        @if(!empty($block['data']['caption']))
                            <p class="text-sm text-gray-600 text-center mt-2">{{ $block['data']['caption'] }}</p>
                        @endif
                    </div>

                @elseif($block['type'] === 'gallery')
                    <div class="mb-8">
                        <div class="grid grid-cols-{{ $block['data']['columns'] ?? 3 }} gap-4">
                            @foreach($block['data']['images'] as $image)
                                <img src="{{ Storage::url($image) }}"
                                     alt=""
                                     class="w-full h-64 object-cover rounded-lg">
                            @endforeach
                        </div>
                    </div>

                @elseif($block['type'] === 'video')
                    <div class="mb-8">
                        <div class="aspect-w-16 aspect-h-9">
                            <iframe src="{{ $block['data']['url'] }}"
                                    frameborder="0"
                                    allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                                    allowfullscreen
                                    class="w-full h-96 rounded-lg"></iframe>
                        </div>
                        @if(!empty($block['data']['caption']))
                            <p class="text-sm text-gray-600 text-center mt-2">{{ $block['data']['caption'] }}</p>
                        @endif
                    </div>

                @elseif($block['type'] === 'cta')
                    <div class="mb-8 p-8 rounded-lg @if($block['data']['style'] === 'primary') bg-blue-50 @elseif($block['data']['style'] === 'accent') bg-green-50 @else bg-gray-50 @endif">
                        <h3 class="text-2xl font-bold mb-4">{{ $block['data']['heading'] }}</h3>
                        @if(!empty($block['data']['description']))
                            <p class="text-gray-700 mb-6">{{ $block['data']['description'] }}</p>
                        @endif
                        @if(!empty($block['data']['button_url']))
                            <a href="{{ $block['data']['button_url'] }}"
                               class="inline-block px-6 py-3 rounded-lg font-semibold @if($block['data']['style'] === 'primary') bg-blue-600 text-white hover:bg-blue-700 @elseif($block['data']['style'] === 'accent') bg-green-600 text-white hover:bg-green-700 @else bg-gray-600 text-white hover:bg-gray-700 @endif">
                                {{ $block['data']['button_text'] ?? 'Dowiedz się więcej' }}
                            </a>
                        @endif
                    </div>

                @elseif($block['type'] === 'two_columns')
                    <div class="mb-8 grid grid-cols-1 md:grid-cols-2 gap-8">
                        <div class="prose prose-registro max-w-none">{!! $block['data']['left_column'] !!}</div>
                        <div class="prose prose-registro max-w-none">{!! $block['data']['right_column'] !!}</div>
                    </div>

                @elseif($block['type'] === 'three_columns')
                    <div class="mb-8 grid grid-cols-1 md:grid-cols-3 gap-6">
                        <div class="prose prose-registro max-w-none">{!! $block['data']['column_1'] !!}</div>
                        <div class="prose prose-registro max-w-none">{!! $block['data']['column_2'] !!}</div>
                        <div class="prose prose-registro max-w-none">{!! $block['data']['column_3'] !!}</div>
                    </div>

                @elseif($block['type'] === 'quote')
                    <blockquote class="mb-8 border-l-4 border-purple-600 pl-6 py-4 bg-purple-50 rounded-r-lg">
                        <p class="text-xl text-gray-700 italic mb-4">{{ $block['data']['quote'] }}</p>
                        @if(!empty($block['data']['author']))
                            <footer class="text-gray-600">
                                <strong>{{ $block['data']['author'] }}</strong>
                                @if(!empty($block['data']['author_title']))
                                    <span class="text-gray-500"> - {{ $block['data']['author_title'] }}</span>
                                @endif
                            </footer>
                        @endif
                    </blockquote>

                @elseif($block['type'] === 'hero')
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

        <footer class="mt-8 pt-6 border-t border-gray-200">
            <div class="flex items-center justify-between">
                <p class="text-sm text-gray-600">
                    Opublikowano: {{ $portfolioItem->published_at?->format('d.m.Y') }}
                </p>
                @if($portfolioItem->category)
                    <p class="text-sm text-gray-600">
                        Kategoria: <strong>{{ $portfolioItem->category->name }}</strong>
                    </p>
                @endif
            </div>
        </footer>

        <div class="mt-8 p-6 bg-blue-50 rounded-lg">
            <h3 class="text-xl font-bold text-gray-900 mb-4">Chcesz podobny efekt?</h3>
            <p class="text-gray-700 mb-4">
                Skontaktuj się z nami i umów wizytę. Nasi specjaliści pomogą Ci uzyskać wymarzone rezultaty!
            </p>
            <a href="{{ route('home') }}" class="inline-block px-6 py-3 bg-blue-600 text-white font-semibold rounded-lg hover:bg-blue-700">
                Umów wizytę
            </a>
        </div>
    </article>

    <div class="mt-8">
        <a href="{{ route('home') }}" class="inline-flex items-center text-blue-600 hover:text-blue-800">
            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
            </svg>
            Powrót do strony głównej
        </a>
    </div>
</div>
@endsection

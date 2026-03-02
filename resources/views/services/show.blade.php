@extends('layouts.app')

@push('head')
    <!-- Open Graph Meta Tags -->
    <meta property="og:title" content="{{ $service->meta_title ?? $service->name }}">
    <meta property="og:description" content="{{ $service->meta_description ?? $service->excerpt }}">
    <meta property="og:type" content="website">
    <meta property="og:url" content="{{ route('service.show', $service) }}">
    @if($service->featured_image)
        <meta property="og:image" content="{{ Storage::url($service->featured_image) }}">
    @endif

    <!-- SEO Meta Tags -->
    <meta name="description" content="{{ $service->meta_description ?? $service->excerpt }}">
    <title>{{ $service->meta_title ?? $service->name . ' - ' . config('app.name') }}</title>

    <!-- Schema.org Service JSON-LD -->
    <script type="application/ld+json">{!! $schemaService !!}</script>

    <!-- Schema.org BreadcrumbList JSON-LD -->
    <script type="application/ld+json">{!! $schemaBreadcrumbs !!}</script>
@endpush

@section('content')

{{-- Service Hero (Full Width) --}}
<x-ios.service-hero :service="$service" />

{{-- Service Details Cards (Floating above hero) --}}
<x-ios.service-details :service="$service" />

{{-- Breadcrumbs --}}
<x-ios.breadcrumbs :items="[
    ['label' => 'Strona główna', 'url' => route('home')],
    ['label' => 'Usługi', 'url' => route('services.index')],
    ['label' => $service->name],
]" />

{{-- Main Content Container --}}
<div class="container mx-auto px-4 md:px-6 py-8">
    <article class="max-w-4xl mx-auto">
        {{-- Main Content (body) --}}
        @if($service->body && trim(strip_tags($service->body)))
            <div class="prose prose-lg prose-registro max-w-none mb-12 bg-white rounded-2xl p-8 shadow-sm">
                {!! clean($service->body) !!}
            </div>
        @endif

        {{-- Advanced Builder Blocks --}}
        @if($service->content)
            @foreach($service->content as $block)
                @if($block['type'] === 'image')
                    <div class="mb-8 @if($block['data']['size'] === 'full') w-full @elseif($block['data']['size'] === 'large') max-w-3xl mx-auto @elseif($block['data']['size'] === 'medium') max-w-2xl mx-auto @else max-w-xl mx-auto @endif">
                        <img src="{{ Storage::url($block['data']['image']) }}"
                             alt="{{ $block['data']['alt'] ?? '' }}"
                             class="w-full rounded-2xl shadow-lg">
                        @if(!empty($block['data']['caption']))
                            <p class="text-sm text-gray-600 text-center mt-3">{{ $block['data']['caption'] }}</p>
                        @endif
                    </div>

                @elseif($block['type'] === 'gallery')
                    <div class="mb-12">
                        <div class="grid grid-cols-{{ $block['data']['columns'] ?? 3 }} gap-4">
                            @foreach($block['data']['images'] as $image)
                                <img src="{{ Storage::url($image) }}"
                                     alt=""
                                     class="w-full h-64 object-cover rounded-xl shadow-md hover:shadow-xl transition-shadow duration-300">
                            @endforeach
                        </div>
                    </div>

                @elseif($block['type'] === 'video')
                    <div class="mb-12">
                        @php
                            $videoUrl = $block['data']['url'] ?? '';
                            $isValidVideo = preg_match('%^https://(www\.youtube\.com/embed/|player\.vimeo\.com/video/)%', $videoUrl);
                        @endphp

                        @if($isValidVideo)
                            <div class="aspect-w-16 aspect-h-9 rounded-2xl overflow-hidden shadow-lg">
                                <iframe src="{{ $videoUrl }}"
                                        frameborder="0"
                                        allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                                        allowfullscreen
                                        class="w-full h-96"></iframe>
                            </div>
                            @if(!empty($block['data']['caption']))
                                <p class="text-sm text-gray-600 text-center mt-3">{{ $block['data']['caption'] }}</p>
                            @endif
                        @else
                            <div class="bg-red-50 border-2 border-red-200 rounded-2xl p-8 text-center">
                                <x-heroicon-o-exclamation-triangle class="w-16 h-16 text-red-500 mx-auto mb-4" />
                                <p class="text-red-700 font-semibold text-lg">Nieprawidłowy URL wideo</p>
                                <p class="text-red-600 text-sm mt-2">Dozwolone tylko linki z YouTube (embed) i Vimeo (player)</p>
                            </div>
                        @endif
                    </div>

                @elseif($block['type'] === 'cta')
                    <div class="mb-12 p-8 rounded-2xl shadow-lg @if($block['data']['style'] === 'primary') bg-gradient-to-br from-blue-50 to-blue-100 @elseif($block['data']['style'] === 'accent') bg-gradient-to-br from-green-50 to-green-100 @else bg-gradient-to-br from-gray-50 to-gray-100 @endif">
                        <h3 class="text-2xl md:text-3xl font-bold mb-4 text-gray-900">{{ $block['data']['heading'] }}</h3>
                        @if(!empty($block['data']['description']))
                            <p class="text-gray-700 text-lg mb-6">{{ $block['data']['description'] }}</p>
                        @endif
                        @if(!empty($block['data']['button_url']))
                            <a href="{{ $block['data']['button_url'] }}"
                               class="inline-block px-8 py-4 rounded-full font-semibold text-lg transition-all duration-300 ios-spring hover:scale-105 active:scale-95 shadow-lg @if($block['data']['style'] === 'primary') bg-blue-600 text-white hover:bg-blue-700 @elseif($block['data']['style'] === 'accent') bg-green-600 text-white hover:bg-green-700 @else bg-gray-800 text-white hover:bg-gray-900 @endif">
                                {{ $block['data']['button_text'] ?? 'Dowiedz się więcej' }}
                            </a>
                        @endif
                    </div>

                @elseif($block['type'] === 'two_columns')
                    <div class="mb-12 grid grid-cols-1 md:grid-cols-2 gap-8">
                        <div class="prose prose-registro max-w-none bg-white rounded-2xl p-6 shadow-sm">{!! clean($block['data']['left_column']) !!}</div>
                        <div class="prose prose-registro max-w-none bg-white rounded-2xl p-6 shadow-sm">{!! clean($block['data']['right_column']) !!}</div>
                    </div>

                @elseif($block['type'] === 'three_columns')
                    <div class="mb-12 grid grid-cols-1 md:grid-cols-3 gap-6">
                        <div class="prose prose-registro max-w-none bg-white rounded-2xl p-6 shadow-sm">{!! clean($block['data']['column_1']) !!}</div>
                        <div class="prose prose-registro max-w-none bg-white rounded-2xl p-6 shadow-sm">{!! clean($block['data']['column_2']) !!}</div>
                        <div class="prose prose-registro max-w-none bg-white rounded-2xl p-6 shadow-sm">{!! clean($block['data']['column_3']) !!}</div>
                    </div>

                @elseif($block['type'] === 'quote')
                    <blockquote class="mb-12 border-l-4 border-primary pl-8 py-6 bg-gray-50 rounded-r-2xl shadow-sm">
                        <p class="text-xl md:text-2xl text-gray-700 italic mb-4 leading-relaxed">{{ $block['data']['quote'] }}</p>
                        @if(!empty($block['data']['author']))
                            <footer class="text-gray-600">
                                <strong class="text-gray-900">{{ $block['data']['author'] }}</strong>
                                @if(!empty($block['data']['author_title']))
                                    <span class="text-gray-500 text-sm"> - {{ $block['data']['author_title'] }}</span>
                                @endif
                            </footer>
                        @endif
                    </blockquote>

                @elseif($block['type'] === 'service_features')
                    <x-content-blocks.service-features
                        :heading="$block['data']['heading'] ?? 'Co zawiera usługa'"
                        :layout="$block['data']['layout'] ?? 'simple'"
                        :service="$service"
                    />

                @elseif($block['type'] === 'text_block')
                    <x-content-blocks.text-block :data="$block['data']" />
                @endif
            @endforeach
        @endif

        {{-- Footer CTA (iOS Style) --}}
        <div class="mt-16 p-8 md:p-12 bg-gradient-to-br from-primary via-blue-600 to-indigo-700 rounded-2xl text-center shadow-2xl">
            <h2 class="text-3xl md:text-4xl font-bold text-white mb-4">Gotowy rozpocząć?</h2>
            @if($bookingEnabled)
                <p class="text-white/90 text-lg mb-8 max-w-2xl mx-auto">Zarezerwuj termin online i doświadcz profesjonalnego detailingu</p>
                <a href="{{ route('booking.create', $service) }}"
                   class="inline-block bg-white text-gray-900 px-8 py-4 rounded-full font-semibold text-lg hover:bg-gray-100 transition-all duration-300 ios-spring hover:scale-105 active:scale-95 shadow-xl">
                    Zarezerwuj Termin
                    <x-heroicon-m-arrow-right class="w-5 h-5 inline ml-2" />
                </a>
            @else
                <p class="text-white/90 text-lg mb-8 max-w-2xl mx-auto">Skontaktuj się z nami telefonicznie, aby umówić wizytę</p>
                <a href="tel:{{ $contactPhone }}"
                   class="inline-block bg-white text-gray-900 px-8 py-4 rounded-full font-semibold text-lg hover:bg-gray-100 transition-all duration-300 ios-spring hover:scale-105 active:scale-95 shadow-xl">
                    Skontaktuj się z nami
                    <x-heroicon-m-phone class="w-5 h-5 inline ml-2" />
                </a>
            @endif
        </div>
    </article>

    {{-- Related Services (Dark Theme) --}}
    @if($relatedServices->count() > 0)
        <div class="mt-16 -mx-4 md:-mx-6 px-4 md:px-6 py-16 bg-section-dark">
            <h2 class="text-3xl font-bold text-white mb-8 text-center">Powiązane usługi</h2>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 max-w-6xl mx-auto">
                @foreach($relatedServices as $related)
                    <x-ios.service-card
                        :service="$related"
                        :icon="$related->icon ?? 'sparkles'"
                        variant="dark"
                    />
                @endforeach
            </div>
        </div>
    @endif
</div>

<style>
    /* iOS Spring Animation */
    .ios-spring {
        transition-timing-function: cubic-bezier(0.36, 0.66, 0.04, 1);
    }

    /* Accessibility: Reduced Motion */
    @media (prefers-reduced-motion: reduce) {
        .ios-spring {
            transition: none !important;
            transform: none !important;
        }
    }
</style>

@endsection

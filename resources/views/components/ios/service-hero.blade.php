@props([
    'service' => null,
    'title' => '',
    'excerpt' => '',
    'featuredImage' => null,
])

@php
    if ($service) {
        $title = $service->name;
        $excerpt = $service->excerpt ?? '';
        $featuredImage = $service->featured_image ? Storage::url($service->featured_image) : null;
    }

    $overlayColor = $service->hero_overlay_color ?? '#000000';
    $overlayOpacity = ($service->hero_overlay_opacity ?? 85) / 100;
@endphp

<section class="relative overflow-hidden bg-black min-h-[500px] md:min-h-[600px]">
    {{-- Featured Image Background --}}
    @if($featuredImage)
        <div class="absolute inset-0">
            <img src="{{ $featuredImage }}"
                 alt="{{ $title }}"
                 class="w-full h-full object-cover">
        </div>
    @endif

    {{-- Configurable overlay (color + opacity from CMS) --}}
    <div class="absolute inset-0" style="background-color: {{ $overlayColor }}; opacity: {{ $overlayOpacity }};"></div>

    {{-- Noise Texture (iOS App Store style) --}}
    <div class="absolute inset-0 opacity-5 mix-blend-overlay bg-noise"></div>

    {{-- Content Container --}}
    <div class="relative container mx-auto px-4 md:px-6 py-20 md:py-32 flex flex-col justify-center min-h-[500px] md:min-h-[600px]">
        <div class="max-w-4xl">
            {{-- Title with fade-in-up animation --}}
            <h1 class="text-4xl md:text-5xl lg:text-6xl font-bold tracking-tight text-white mb-6 animate-fade-in-up"
                style="animation-delay: 0.1s;">
                {{ $title }}
            </h1>

            {{-- Excerpt --}}
            @if($excerpt)
            <p class="text-xl md:text-2xl text-white/90 mb-8 max-w-2xl animate-fade-in-up"
               style="animation-delay: 0.2s;">
                {{ $excerpt }}
            </p>
            @endif

            {{-- CTA Button --}}
            @if($service)
            <div class="animate-fade-in-up" style="animation-delay: 0.3s;">
                @if($bookingEnabled)
                    <a href="{{ route('booking.create', $service) }}"
                       class="inline-block bg-white text-gray-900 px-8 py-4 rounded-full font-semibold text-lg hover:bg-gray-100 transition-all duration-300 ios-spring hover:scale-105 active:scale-95 shadow-2xl">
                        Zarezerwuj Termin
                        <x-heroicon-m-arrow-right class="w-5 h-5 inline ml-2" />
                    </a>
                @else
                    <a href="tel:{{ $contactPhone }}"
                       class="inline-block bg-white text-gray-900 px-8 py-4 rounded-full font-semibold text-lg hover:bg-gray-100 transition-all duration-300 ios-spring hover:scale-105 active:scale-95 shadow-2xl">
                        Skontaktuj się z nami
                        <x-heroicon-m-phone class="w-5 h-5 inline ml-2" />
                    </a>
                @endif
            </div>
            @endif
        </div>
    </div>

    {{-- Scroll Indicator (iOS style) --}}
    <div class="absolute bottom-8 left-1/2 transform -translate-x-1/2 animate-bounce">
        <svg class="w-6 h-6 text-white/60" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 14l-7 7m0 0l-7-7m7 7V3"/>
        </svg>
    </div>
</section>

<style>
    /* Noise texture background */
    .bg-noise {
        background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 400 400' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='noiseFilter'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23noiseFilter)'/%3E%3C/svg%3E");
    }

    /* iOS Spring Animation */
    .ios-spring {
        transition-timing-function: cubic-bezier(0.36, 0.66, 0.04, 1);
    }

    /* Accessibility: Reduced Motion */
    @media (prefers-reduced-motion: reduce) {
        .animate-fade-in-up,
        .animate-bounce {
            animation: none !important;
            opacity: 1 !important;
            transform: none !important;
        }

        .ios-spring {
            transition: none !important;
        }
    }
</style>

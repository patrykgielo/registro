@props([
    'title' => 'Detailing jak z przyszłości',
    'subtitle' => 'Profesjonalne usługi detailingowe dla Twojego auta',
])

{{-- iOS-Style Hero Banner with Responsive Height and Monochrome Orbs --}}
<section class="relative min-h-[50vh] sm:min-h-[60vh] lg:min-h-[70vh] flex items-center justify-center overflow-hidden bg-primary-600">
    {{-- Noise Texture Overlay (App Store style) --}}
    <div class="absolute inset-0 opacity-5 mix-blend-overlay" style="background-image: url('data:image/svg+xml,%3Csvg viewBox=\'0 0 400 400\' xmlns=\'http://www.w3.org/2000/svg\'%3E%3Cfilter id=\'noiseFilter\'%3E%3CfeTurbulence type=\'fractalNoise\' baseFrequency=\'0.9\' numOctaves=\'4\' stitchTiles=\'stitch\'/%3E%3C/filter%3E%3Crect width=\'100%25\' height=\'100%25\' filter=\'url(%23noiseFilter)\'/%3E%3C/svg%3E');"></div>

    {{-- Monochrome Gradient Orbs (subtle decoration) --}}
    <div class="absolute inset-0 overflow-hidden pointer-events-none">
        <div class="absolute top-1/4 left-1/4 w-[600px] h-[600px] rounded-full bg-gradient-radial from-primary-500/15 via-primary-500/8 to-transparent blur-3xl animate-blob"></div>
        <div class="absolute bottom-1/4 right-1/4 w-[500px] h-[500px] rounded-full bg-gradient-radial from-primary-400/12 via-primary-400/6 to-transparent blur-3xl animate-blob animation-delay-2000"></div>
    </div>

    {{-- Content Container --}}
    <div class="relative z-10 container mx-auto px-4 md:px-6 py-12 sm:py-16 md:py-20 lg:py-24 text-center">
        {{-- Headline with fluid typography using clamp() --}}
        <h1 class="font-bold text-white mb-6 drop-shadow-lg animate-fade-in-up"
            style="font-size: clamp(2.25rem, 6vw, 6rem); line-height: 1.1; animation-delay: 0.1s;">
            {{ $title }}
        </h1>

        {{-- Subtitle with fluid typography --}}
        @if($subtitle)
        <p class="text-white/90 mb-8 max-w-3xl mx-auto animate-fade-in-up"
           style="font-size: clamp(1.125rem, 2.5vw, 1.5rem); line-height: 1.5; animation-delay: 0.2s;">
            {{ $subtitle }}
        </p>
        @endif

        {{-- CTA Buttons (slot) --}}
        <div class="flex flex-col sm:flex-row gap-4 justify-center items-center animate-fade-in-up" style="animation-delay: 0.3s;">
            {{ $slot }}
        </div>
    </div>
</section>

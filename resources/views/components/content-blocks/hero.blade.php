@props(['data' => []])

@php
    use Illuminate\Support\Facades\Storage;

    $backgroundType = $data['background_type'] ?? 'gradient';
    $backgroundImage = $data['background_image'] ?? null;
    $backgroundColor = $data['background_color'] ?? '#0891b2';
    $title = $data['title'] ?? '';
    $subtitle = $data['subtitle'] ?? '';
    $ctaButtons = $data['cta_buttons'] ?? [];
    $overlayOpacity = $data['overlay_opacity'] ?? 50;

    // Layout options (from BlockStyling)
    $fullWidth = $data['full_width'] ?? false;
    $containerMaxWidth = $data['container_max_width'] ?? '7xl';
    $verticalPadding = $data['vertical_padding'] ?? 'lg';

    // Advanced options
    $cssId = $data['css_id'] ?? null;
    $cssClasses = $data['css_classes'] ?? '';

    // Build background style
    $bgStyle = match($backgroundType) {
        'image' => $backgroundImage
            ? "background-image: url('" . Storage::url($backgroundImage) . "'); background-size: cover; background-position: center;"
            : "background: linear-gradient(135deg, #0891b2 0%, #06b6d4 100%);",
        'solid' => "background-color: {$backgroundColor};",
        default => "background: linear-gradient(135deg, #0891b2 0%, #06b6d4 100%);",
    };

    // Build container classes
    $containerClasses = $fullWidth
        ? 'w-full'
        : "max-w-{$containerMaxWidth} mx-auto";

    // Build vertical padding classes (hero needs special handling for min-height)
    $paddingClasses = match($verticalPadding) {
        'sm' => 'py-8',
        'md' => 'py-16',
        'lg' => 'py-24',
        'xl' => 'py-32',
        default => '',
    };

    $sectionClasses = trim("relative min-h-[70vh] md:min-h-[80vh] flex items-center justify-center overflow-hidden px-4 md:px-6 {$paddingClasses} {$cssClasses}");
@endphp

<section
    @if($cssId) id="{{ $cssId }}" @endif
    class="{{ $sectionClasses }}"
    style="{{ $bgStyle }}">

    {{-- Overlay --}}
    @if($overlayOpacity > 0)
        <div class="absolute inset-0 bg-black" style="opacity: {{ $overlayOpacity / 100 }};"></div>
    @endif

    {{-- Noise texture overlay (iOS style) --}}
    <div class="absolute inset-0 opacity-10">
        <svg width="100%" height="100%">
            <filter id="noise-hero-{{ md5(json_encode($data)) }}">
                <feTurbulence type="fractalNoise" baseFrequency="0.9" numOctaves="4" />
            </filter>
            <rect width="100%" height="100%" filter="url(#noise-hero-{{ md5(json_encode($data)) }})" />
        </svg>
    </div>

    <div class="relative z-10 text-center space-y-8 {{ $containerClasses }}">
        {{-- Title --}}
        <h1 class="text-5xl md:text-7xl lg:text-8xl font-light tracking-tight text-white mb-6 animate-fade-in-up"
            style="letter-spacing: -0.02em; line-height: 1.05; animation-delay: 0.1s;">
            {!! nl2br(e($title)) !!}
        </h1>

        {{-- Subtitle --}}
        @if($subtitle)
            <p class="text-xl md:text-2xl text-white/90 max-w-3xl mx-auto font-light animate-fade-in-up"
               style="animation-delay: 0.2s;">
                {{ $subtitle }}
            </p>
        @endif

        {{-- CTA Buttons --}}
        @if(!empty($ctaButtons))
            <div class="flex flex-col sm:flex-row gap-4 justify-center items-center mt-12 animate-fade-in-up"
                 style="animation-delay: 0.3s;">
                @foreach($ctaButtons as $button)
                    @php
                        $buttonStyle = $button['style'] ?? 'primary';
                        $buttonClass = match($buttonStyle) {
                            'primary' => 'px-8 py-4 bg-white/20 backdrop-blur-xl rounded-full text-white font-semibold text-lg shadow-[0_8px_24px_rgba(0,0,0,0.15),0_0_40px_rgba(255,255,255,0.1)] hover:bg-white/30 hover:shadow-[0_12px_32px_rgba(0,0,0,0.2),0_0_60px_rgba(255,255,255,0.15)] hover:scale-105 active:scale-95 transition-all duration-300 border border-white/30',
                            'secondary' => 'px-8 py-4 text-white font-semibold text-lg hover:text-white/80 transition-colors flex items-center gap-2',
                            'accent' => 'px-8 py-4 bg-warning text-white font-semibold text-lg rounded-full shadow-lg hover:bg-warning/90 hover:scale-105 active:scale-95 transition-all duration-300',
                        };
                    @endphp

                    <a href="{{ $button['url'] }}" class="{{ $buttonClass }}">
                        {{ $button['text'] }}
                    </a>
                @endforeach
            </div>
        @endif
    </div>
</section>

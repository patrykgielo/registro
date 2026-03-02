@props(['data' => []])

@php
    $heading = $data['heading'] ?? '';
    $subheading = $data['subheading'] ?? '';
    $ctaButtons = $data['cta_buttons'] ?? [];
    $backgroundOrbs = $data['background_orbs'] ?? true;

    // BACKWARD COMPATIBILITY: Map legacy background_color to new background system
    // If background_type is not set but background_color exists, treat as solid color
    $backgroundType = $data['background_type'] ?? null;

    if (!$backgroundType && isset($data['background_color'])) {
        // Legacy data: convert to new solid type
        $data['background_type'] = 'solid';
        // $data['background_color'] is already set, keep it
    }

    // Default for new blocks without any background settings (original default was cyan)
    if (empty($data['background_type']) || $data['background_type'] === 'none') {
        $data['background_type'] = 'solid';
        $data['background_color'] = $data['background_color'] ?? '#0891b2';
    }

    // Helper function to determine if a hex color is dark
    $isColorDark = function(string $hex): bool {
        $color = ltrim($hex, '#');
        if (strlen($color) !== 6) {
            return true; // Default to dark if invalid color
        }
        $r = hexdec(substr($color, 0, 2)) / 255;
        $g = hexdec(substr($color, 2, 2)) / 255;
        $b = hexdec(substr($color, 4, 2)) / 255;
        // W3C relative luminance formula
        $luminance = 0.299 * $r + 0.587 * $g + 0.114 * $b;
        return $luminance < 0.5;
    };

    // Detect if background is dark for text color adaptation
    $bgType = $data['background_type'] ?? 'solid';
    $isDark = match($bgType) {
        'solid' => $isColorDark($data['background_color'] ?? '#0891b2'),
        'gradient' => true,  // Gradients are typically vibrant/dark colors
        'image' => true,     // Images with overlay are typically dark
        default => true,     // Default to dark background
    };

    // Text classes based on background brightness
    $headingClasses = $isDark ? 'text-white' : 'text-gray-900';
    $subheadingClasses = $isDark ? 'text-white/90' : 'text-gray-700';

    // Button classes based on background brightness
    $primaryButtonClasses = $isDark
        ? 'min-h-11 px-10 py-5 bg-white text-gray-900 font-semibold text-lg rounded-full shadow-[0_12px_32px_rgba(0,0,0,0.2),0_0_60px_rgba(255,255,255,0.2)] hover:shadow-[0_16px_40px_rgba(0,0,0,0.25),0_0_80px_rgba(255,255,255,0.25)] hover:scale-105 active:scale-95 focus:outline-none focus:ring-2 focus:ring-white focus:ring-offset-2 focus:ring-offset-primary-600 transition-all duration-300 ios-spring'
        : 'min-h-11 px-10 py-5 bg-gray-900 text-white font-semibold text-lg rounded-full shadow-lg hover:bg-gray-800 hover:scale-105 active:scale-95 focus:outline-none focus:ring-2 focus:ring-gray-900 focus:ring-offset-2 transition-all duration-300 ios-spring';

    $secondaryButtonClasses = $isDark
        ? 'min-h-11 px-10 py-5 text-white font-semibold text-lg hover:text-white/80 focus:outline-none focus:ring-2 focus:ring-white/50 transition-colors ios-spring'
        : 'min-h-11 px-10 py-5 text-gray-900 font-semibold text-lg hover:text-gray-700 focus:outline-none focus:ring-2 focus:ring-gray-900/50 transition-colors ios-spring';

    // Show orbs only for solid/gradient backgrounds (they look bad overlapping images)
    $showOrbs = $backgroundOrbs && in_array($bgType, ['solid', 'gradient']);

    // CTA Container settings
    $ctaContainerBgType = $data['cta_container_bg_type'] ?? 'none';
    $ctaContainerColor = $data['cta_container_color'] ?? '#0891b2';
    $ctaContainerGradientFrom = $data['cta_container_gradient_from'] ?? '#0891b2';
    $ctaContainerGradientTo = $data['cta_container_gradient_to'] ?? '#0e7490';
    $ctaContainerGradientDirection = $data['cta_container_gradient_direction'] ?? 'to-r';
    $ctaContainerRounded = $data['cta_container_rounded'] ?? '3xl';
    $ctaContainerPadding = $data['cta_container_padding'] ?? 'lg';
    $ctaContainerEnabled = in_array($ctaContainerBgType, ['solid', 'gradient']);

    // Map Tailwind direction to CSS
    $cssCtaGradientDirection = match($ctaContainerGradientDirection) {
        'to-r' => 'to right',
        'to-l' => 'to left',
        'to-t' => 'to top',
        'to-b' => 'to bottom',
        'to-br' => 'to bottom right',
        'to-bl' => 'to bottom left',
        'to-tr' => 'to top right',
        'to-tl' => 'to top left',
        default => 'to right',
    };

    // Build CTA container inline style
    $ctaContainerStyle = match($ctaContainerBgType) {
        'solid' => "background-color: {$ctaContainerColor};",
        'gradient' => "background: linear-gradient({$cssCtaGradientDirection}, {$ctaContainerGradientFrom}, {$ctaContainerGradientTo});",
        default => '',
    };

    // Build CTA container classes
    $ctaContainerClasses = $ctaContainerEnabled
        ? trim(implode(' ', array_filter([
            'relative z-10',
            match($ctaContainerRounded) {
                'none' => '',
                'lg' => 'rounded-lg',
                'xl' => 'rounded-xl',
                '2xl' => 'rounded-2xl',
                '3xl' => 'rounded-3xl',
                default => 'rounded-3xl',
            },
            match($ctaContainerPadding) {
                'md' => 'p-8 md:p-12',
                'lg' => 'p-10 md:p-16',
                'xl' => 'p-12 md:p-20',
                default => 'p-10 md:p-16',
            },
        ])))
        : 'relative z-10';

    // Override isDark if CTA container has its own background
    // Text contrast should be based on the closest background (container > section)
    if ($ctaContainerEnabled) {
        $isDark = match($ctaContainerBgType) {
            'solid' => $isColorDark($ctaContainerColor),
            'gradient' => true,
            default => true,
        };

        // Recalculate text/button classes based on container background
        $headingClasses = $isDark ? 'text-white' : 'text-gray-900';
        $subheadingClasses = $isDark ? 'text-white/90' : 'text-gray-700';

        $primaryButtonClasses = $isDark
            ? 'min-h-11 px-10 py-5 bg-white text-gray-900 font-semibold text-lg rounded-full shadow-[0_12px_32px_rgba(0,0,0,0.2),0_0_60px_rgba(255,255,255,0.2)] hover:shadow-[0_16px_40px_rgba(0,0,0,0.25),0_0_80px_rgba(255,255,255,0.25)] hover:scale-105 active:scale-95 focus:outline-none focus:ring-2 focus:ring-white focus:ring-offset-2 focus:ring-offset-primary-600 transition-all duration-300 ios-spring'
            : 'min-h-11 px-10 py-5 bg-gray-900 text-white font-semibold text-lg rounded-full shadow-lg hover:bg-gray-800 hover:scale-105 active:scale-95 focus:outline-none focus:ring-2 focus:ring-gray-900 focus:ring-offset-2 transition-all duration-300 ios-spring';

        $secondaryButtonClasses = $isDark
            ? 'min-h-11 px-10 py-5 text-white font-semibold text-lg hover:text-white/80 focus:outline-none focus:ring-2 focus:ring-white/50 transition-colors ios-spring'
            : 'min-h-11 px-10 py-5 text-gray-900 font-semibold text-lg hover:text-gray-700 focus:outline-none focus:ring-2 focus:ring-gray-900/50 transition-colors ios-spring';
    }
@endphp

<x-blocks.partials.section-wrapper :data="$data">
    @if($showOrbs)
        {{-- Animated monochrome mesh (only for solid/gradient backgrounds) --}}
        <div class="absolute inset-0 overflow-hidden pointer-events-none">
            <div class="absolute top-0 left-0 w-[600px] h-[600px] rounded-full bg-gradient-radial from-white/10 to-transparent blur-3xl animate-blob"></div>
            <div class="absolute bottom-0 right-0 w-[500px] h-[500px] rounded-full bg-gradient-radial from-white/8 to-transparent blur-3xl animate-blob animation-delay-2000"></div>
        </div>
    @endif

    <div class="{{ $ctaContainerClasses }}"
         @if($ctaContainerStyle) style="{{ $ctaContainerStyle }}" @endif>
        <div class="text-center space-y-8">
            <h2 class="text-5xl md:text-6xl lg:text-7xl font-light tracking-tight {{ $headingClasses }} mb-6"
                style="letter-spacing: -0.02em; line-height: 1.05;">
                {!! nl2br(e($heading)) !!}
            </h2>

            @if($subheading)
                <p class="text-xl md:text-2xl {{ $subheadingClasses }} max-w-2xl mx-auto font-light mb-12">
                    {{ $subheading }}
                </p>
            @endif

            @if(!empty($ctaButtons))
                <div class="flex flex-col sm:flex-row gap-4 justify-center items-center">
                    @foreach($ctaButtons as $button)
                        @php
                            $buttonClass = ($button['style'] ?? 'primary') === 'primary'
                                ? $primaryButtonClasses
                                : $secondaryButtonClasses;
                        @endphp

                        <a href="{{ $button['url'] ?? '#' }}" class="{{ $buttonClass }}">
                            {{ $button['text'] ?? '' }}
                            @if(($button['style'] ?? 'primary') === 'primary')
                                <x-heroicon-m-arrow-right class="w-5 h-5 inline ml-2" />
                            @endif
                        </a>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</x-blocks.partials.section-wrapper>

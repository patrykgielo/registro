@props(['data' => []])

@php
    use App\Support\ContentGridResolver;

    $contentType = $data['content_type'] ?? 'services';
    $contentItemIds = $data['content_items'] ?? [];
    $columns = (string) ($data['columns'] ?? '3');
    $heading = $data['heading'] ?? '';
    $subheading = $data['subheading'] ?? '';

    // Backwards compatibility: old background_color was a string key
    $backgroundType = $data['background_type'] ?? null;
    if (!$backgroundType && isset($data['background_color'])) {
        $oldBgColor = $data['background_color'];
        if (in_array($oldBgColor, ['white', 'neutral-50', 'primary-50', 'dark'])) {
            $data['background_type'] = 'solid';
            $data['background_color'] = match($oldBgColor) {
                'neutral-50' => '#f9fafb',
                'primary-50' => '#f0f9ff',
                'dark' => '#1f2937',
                default => '#ffffff',
            };
        } else {
            // Services default to dark theme
            $defaultBg = $contentType === 'services' ? 'dark' : 'white';
            $backgroundColor = $data['background_color'] ?? $defaultBg;
            if ($backgroundColor === 'dark') {
                $data['background_type'] = 'solid';
                $data['background_color'] = '#1f2937';
            }
        }
    }

    // Fetch content items in specified order via centralized resolver
    $items = ContentGridResolver::resolveItems($contentType, $contentItemIds);

    // Detect if background is dark (luminance calculation per WCAG)
    $isColorDark = function (string $hex): bool {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));
        $luminance = (0.299 * $r + 0.587 * $g + 0.114 * $b) / 255;
        return $luminance < 0.5;
    };

    $isDark = ($data['background_type'] ?? 'none') === 'solid'
        && isset($data['background_color'])
        && $isColorDark($data['background_color']);

    // Service card variant: user choice or auto-detect from background
    $serviceCardVariant = match($data['service_card_variant'] ?? 'auto') {
        'dark' => 'dark',
        'default' => 'default',
        default => $isDark ? 'dark' : 'default',
    };

    // Text classes based on dark variant
    $headingClasses = $isDark ? 'text-white' : 'text-gray-900';
    $subheadingClasses = $isDark ? 'text-white/80' : 'text-gray-600';

    $gridClass = match($columns) {
        '2' => 'grid-cols-1 md:grid-cols-2',
        '4' => 'grid-cols-1 md:grid-cols-2 lg:grid-cols-4',
        default => 'grid-cols-1 md:grid-cols-2 lg:grid-cols-3',
    };
@endphp

{{-- Nothing visible to render (every picked item deactivated / unpublished / deleted, or none picked):
     render nothing — a heading over an empty grid, or a warning box, is worse than no block. --}}
@if($items->isNotEmpty())
<x-blocks.partials.section-wrapper :data="$data">
    @if($heading || $subheading)
        <div class="text-center mb-16">
            @if($heading)
                <h2 class="text-5xl md:text-6xl font-light tracking-tight {{ $headingClasses }} mb-4"
                    style="letter-spacing: -0.02em;">
                    {{ $heading }}
                </h2>
            @endif

            @if($subheading)
                <p class="text-xl md:text-2xl {{ $subheadingClasses }} max-w-3xl mx-auto font-light">
                    {{ $subheading }}
                </p>
            @endif
        </div>
    @endif

    <div class="grid {{ $gridClass }} gap-8">
        @foreach($items as $item)
            @if($contentType === 'services')
                <x-ios.service-card :service="$item" :variant="$serviceCardVariant" />
            @elseif($contentType === 'locations')
                {{-- Has no detail route (out of Faza 1 scope) — own card, not x-cms.card --}}
                <x-ios.location-card :location="$item" :dark="$isDark" />
            @else
                {{-- CMS Content Card for posts, promotions, portfolio --}}
                @php
                    $itemUrl = ($item->slug ?? false) ? route(match($contentType) {
                        'posts' => 'post.show',
                        'promotions' => 'promotion.show',
                        'portfolio' => 'portfolio.show',
                        default => 'home'
                    }, $item->slug) : '#';
                @endphp
                <x-cms.card :item="$item" :url="$itemUrl" :dark="$isDark" />
            @endif
        @endforeach
    </div>
</x-blocks.partials.section-wrapper>
@endif

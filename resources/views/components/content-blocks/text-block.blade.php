@props(['data' => []])

@php
    $content = $data['content'] ?? '';

    // Backwards compatibility: old background_color was a string key
    $backgroundType = $data['background_type'] ?? null;
    if (!$backgroundType && isset($data['background_color'])) {
        // Map old string keys to new system
        $oldBgColor = $data['background_color'];
        if (in_array($oldBgColor, ['white', 'neutral-50', 'primary-50', 'dark'])) {
            $data['background_type'] = 'solid';
            $data['background_color'] = match($oldBgColor) {
                'neutral-50' => '#f9fafb',
                'primary-50' => '#f0f9ff',
                'dark' => '#1f2937',
                default => '#ffffff',
            };
        }
    }

    /**
     * Determine if a hex color is dark using relative luminance (WCAG formula).
     * Works with client colors: #00323B, #000000, #1f2937, etc.
     */
    $isColorDark = function (string $hex): bool {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }
        if (strlen($hex) !== 6) {
            return false;
        }
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));
        // Relative luminance formula (WCAG 2.0)
        $luminance = (0.299 * $r + 0.587 * $g + 0.114 * $b) / 255;
        return $luminance < 0.5;
    };

    // Detect if background is dark for prose-invert
    $isDark = ($data['background_type'] ?? 'none') === 'solid'
        && isset($data['background_color'])
        && $isColorDark($data['background_color']);

    $proseClasses = $isDark ? 'prose-invert' : '';
@endphp

<x-blocks.partials.section-wrapper :data="$data">
    <div class="prose prose-lg prose-registro max-w-none {{ $proseClasses }}">
        {!! $content !!}
    </div>
</x-blocks.partials.section-wrapper>

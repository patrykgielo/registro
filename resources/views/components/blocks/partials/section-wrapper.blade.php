@props(['data' => []])

@php
    use Illuminate\Support\Facades\Storage;

    // Background settings
    $backgroundType = $data['background_type'] ?? 'none';
    $backgroundColor = $data['background_color'] ?? '#ffffff';
    $gradientFrom = $data['gradient_from'] ?? '#0891b2';
    $gradientTo = $data['gradient_to'] ?? '#0e7490';
    $gradientDirection = $data['gradient_direction'] ?? 'to-r';

    // Map Tailwind direction classes to CSS gradient directions
    // Tailwind uses 'to-r', 'to-l', etc. but CSS linear-gradient() requires 'to right', 'to left', etc.
    $cssGradientDirection = match($gradientDirection) {
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

    $backgroundImage = $data['background_image'] ?? null;
    $backgroundOverlay = $data['background_overlay'] ?? false;
    $overlayColor = $data['overlay_color'] ?? '#000000';
    $overlayOpacity = (int) ($data['overlay_opacity'] ?? '50');

    // Layout settings
    $fullWidth = $data['full_width'] ?? false;
    $containerMaxWidth = $data['container_max_width'] ?? 'xl';
    $verticalPadding = $data['vertical_padding'] ?? 'md';

    // Advanced settings
    $cssId = $data['css_id'] ?? null;
    $cssClasses = $data['css_classes'] ?? '';

    // Build background inline styles
    $bgStyle = match($backgroundType) {
        'solid' => "background-color: {$backgroundColor};",
        'gradient' => "background: linear-gradient({$cssGradientDirection}, {$gradientFrom}, {$gradientTo});",
        'image' => $backgroundImage
            ? "background-image: url('" . Storage::url($backgroundImage) . "'); background-size: cover; background-position: center; background-repeat: no-repeat;"
            : '',
        default => '',
    };

    // Build container classes - must use full class names for Tailwind JIT
    // IMPORTANT: max-w-* classes have different values than screen breakpoints!
    // max-w-sm=384px, max-w-xl=576px, max-w-7xl=1280px
    // We use max-w-screen-* for screen-width matching or explicit rem values
    $containerClasses = $fullWidth
        ? 'w-full'
        : match($containerMaxWidth) {
            'sm' => 'max-w-screen-sm mx-auto px-4 sm:px-6 lg:px-8',      // 640px
            'md' => 'max-w-screen-md mx-auto px-4 sm:px-6 lg:px-8',      // 768px
            'lg' => 'max-w-screen-lg mx-auto px-4 sm:px-6 lg:px-8',      // 1024px
            'xl' => 'max-w-screen-xl mx-auto px-4 sm:px-6 lg:px-8',      // 1280px
            '2xl' => 'max-w-screen-2xl mx-auto px-4 sm:px-6 lg:px-8',    // 1536px
            '7xl' => 'max-w-[1920px] mx-auto px-4 sm:px-6 lg:px-8',      // 1920px (custom)
            'full' => 'w-full px-4 sm:px-6 lg:px-8',                      // no max constraint
            default => 'max-w-screen-xl mx-auto px-4 sm:px-6 lg:px-8',   // 1280px
        };

    // Build vertical padding classes
    $paddingClasses = match($verticalPadding) {
        'sm' => 'py-8',
        'md' => 'py-16',
        'lg' => 'py-24',
        'xl' => 'py-32',
        default => '',
    };

    // Combine all section classes
    $sectionClasses = trim("relative {$paddingClasses} {$cssClasses}");

    // Calculate overlay opacity as decimal (0.0 to 1.0)
    $overlayOpacityValue = $overlayOpacity / 100;
@endphp

<section
    @if($cssId) id="{{ $cssId }}" @endif
    class="{{ $sectionClasses }}"
    @if($bgStyle) style="{{ $bgStyle }}" @endif>

    {{-- Overlay (only for image background with overlay enabled) --}}
    @if($backgroundType === 'image' && $backgroundOverlay && $backgroundImage)
        <div class="absolute inset-0" style="background-color: {{ $overlayColor }}; opacity: {{ $overlayOpacityValue }};"></div>
    @endif

    {{-- Content container with relative positioning to appear above overlay --}}
    <div class="relative {{ $containerClasses }}">
        {{ $slot }}
    </div>
</section>

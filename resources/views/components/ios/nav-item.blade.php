@props([
    'href' => '#',                   // Link URL
    'label' => '',                   // Link text
    'active' => null,                // Force active state (optional)
    'routePattern' => null,          // Route name pattern for auto-active (e.g., 'services')
    'icon' => null,                  // Heroicon name (e.g., 'home', 'wrench-screwdriver')
    'external' => false,             // Opens in new tab
    'mobileOnly' => false,           // Show only on mobile drawer
    'dark' => false,                 // Dark header variant
])

@php
    // Active state detection
    $currentRoute = request()->route()?->getName();
    $isActive = $active ?? (
        $routePattern
            ? str_starts_with($currentRoute ?? '', $routePattern)
            : $href === url()->current()
    );

    // Base classes for nav item
    $baseClasses = 'flex items-center space-x-2 transition-colors duration-200';

    // Desktop-specific classes (hidden on mobile when mobileOnly=true)
    $desktopClasses = $mobileOnly ? 'hidden' : 'px-3 py-2';

    // Mobile drawer classes (always visible in drawer)
    $mobileClasses = 'min-h-[44px] px-4 py-3 rounded-lg w-full';

    // Active state styling - with dark variant support
    if ($isActive) {
        // Desktop: border bottom + accent color
        $desktopActiveClasses = $dark
            ? 'text-[#0AB1EA] font-semibold border-b-2 border-[#0AB1EA]'
            : 'text-cyan-500 font-semibold border-b-2 border-cyan-500';
        // Mobile: background + accent color (drawer is always light)
        $mobileActiveClasses = 'bg-blue-50 text-cyan-500 font-semibold';
    } else {
        // Inactive: white/gray with hover to accent
        $desktopActiveClasses = $dark
            ? 'text-white hover:text-[#0AB1EA]'
            : 'text-gray-700 hover:text-cyan-500';
        $mobileActiveClasses = 'text-gray-700 hover:bg-gray-50 hover:text-cyan-500';
    }

    // Combine classes for desktop
    $desktopFinalClasses = trim("{$baseClasses} {$desktopClasses} {$desktopActiveClasses}");

    // Combine classes for mobile (used in drawer context)
    $mobileFinalClasses = trim("{$baseClasses} {$mobileClasses} {$mobileActiveClasses}");

    // External link attributes
    $externalAttrs = $external ? 'target="_blank" rel="noopener noreferrer"' : '';

    // ARIA attributes
    $ariaAttrs = $isActive ? 'aria-current="page"' : '';
@endphp

{{-- Desktop version (inline in navbar) --}}
<a
    href="{{ $href }}"
    class="{{ $desktopFinalClasses }} hidden md:inline-flex"
    {!! $externalAttrs !!}
    {!! $ariaAttrs !!}
    {{ $attributes }}
>
    @if($icon)
        <x-dynamic-component :component="'heroicon-o-' . $icon" class="w-5 h-5" />
    @endif
    <span>{{ $label }}</span>
</a>

{{-- Mobile version (in drawer) --}}
<a
    href="{{ $href }}"
    class="{{ $mobileFinalClasses }} md:hidden"
    {!! $externalAttrs !!}
    {!! $ariaAttrs !!}
    {{ $attributes }}
>
    @if($icon)
        <x-dynamic-component :component="'heroicon-o-' . $icon" class="w-5 h-5" />
    @endif
    <span>{{ $label }}</span>
</a>

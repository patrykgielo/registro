@props([
    'href' => '#',           // Tab link URL
    'label' => '',           // Tab label text
    'icon' => null,          // Heroicon name (e.g., 'home', 'calendar', 'user')
    'active' => null,        // Force active state (optional)
    'routePattern' => null,  // Route name pattern for auto-active (e.g., 'home')
    'badge' => 0,            // Badge count (0 = hidden)
])

@php
    // Active state detection
    $currentRoute = request()->route()?->getName();
    $isActive = $active ?? (
        $routePattern
            ? request()->routeIs($routePattern . '*')
            : $href === url()->current()
    );

    // Icon variant (outline inactive, solid active)
    $iconVariant = $isActive ? 'heroicon-s-' : 'heroicon-o-';
    $iconComponent = $icon ? $iconVariant . $icon : null;

    // Active state colors
    $textColor = $isActive ? 'text-cyan-500' : 'text-gray-600';
    $iconColor = $isActive ? 'text-cyan-500' : 'text-gray-500';

    // Base classes for tab item (44x44px touch target)
    $baseClasses = 'flex flex-col items-center justify-center min-h-[44px] min-w-[44px] relative transition-colors duration-200';

    // ARIA attributes
    $ariaAttrs = $isActive ? 'aria-current="page"' : '';
@endphp

<a
    href="{{ $href }}"
    class="{{ $baseClasses }}"
    style="transition-timing-function: cubic-bezier(0.36, 0.66, 0.04, 1);"
    {!! $ariaAttrs !!}
    {{ $attributes }}
>
    {{-- Icon with badge --}}
    <div class="relative">
        @if($iconComponent)
            <x-dynamic-component
                :component="$iconComponent"
                class="w-6 h-6 {{ $iconColor }} transition-colors duration-200"
            />
        @endif

        {{-- Badge notification --}}
        @if($badge > 0)
            <x-ios.tab-badge :count="$badge" />
        @endif
    </div>

    {{-- Label --}}
    <span class="text-[10px] font-medium mt-1 {{ $textColor }} transition-colors duration-200">
        {{ $label }}
    </span>
</a>

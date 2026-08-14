@props([
    'src' => null,                   // Logo image path (uses SettingsManager if not provided)
    'alt' => null,                   // Alt text for accessibility (uses SettingsManager if not provided)
    'href' => null,                  // Logo link (defaults to home route)
    'class' => '',                   // Additional CSS classes
])

@php
    $settings = app(\App\Support\Settings\SettingsManager::class);

    // Default to home route if no href provided
    $logoHref = $href ?? route('home');

    // Use provided src or get header logo from settings (null when tenant hasn't configured one)
    $logoSrc = $src ?? $settings->headerLogo();

    // Alt text from prop or settings
    $logoAlt = $alt ?? $settings->logoAlt();

    // Build responsive logo classes
    $logoClasses = trim("h-8 lg:h-12 w-auto transition-transform hover:scale-105 {$class}");
@endphp

<a href="{{ $logoHref }}" class="flex items-center" aria-label="Go to homepage">
    @if($logoSrc)
        <img
            src="{{ $logoSrc }}"
            alt="{{ $logoAlt }}"
            class="{{ $logoClasses }}"
            loading="eager"
            onerror="this.style.display='none'; this.nextElementSibling.style.display='block';"
        >
        {{-- Fallback text logo (hidden by default, shown if the URL fails to load) --}}
        <span class="hidden text-xl lg:text-2xl font-bold text-[#0AB1EA] hover:text-white transition-colors">
            {{ $logoAlt }}
        </span>
    @else
        <span class="text-xl lg:text-2xl font-bold text-[#0AB1EA] hover:text-white transition-colors">
            {{ $logoAlt }}
        </span>
    @endif
</a>

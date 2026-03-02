{{--
    iOS-style bottom tab bar
    - Fixed bottom positioning with safe area support
    - Glassmorphism effect (backdrop-blur-xl)
    - Mobile-only (<768px)
    - 49px content + env(safe-area-inset-bottom) for iPhone X+ home indicator
    - Z-index 50 (same as header, but at bottom so no overlap)
--}}

<nav
    class="fixed bottom-0 left-0 right-0 bg-white/95 backdrop-blur-xl border-t border-gray-200 z-50 md:hidden"
    style="padding-bottom: max(env(safe-area-inset-bottom, 0px), 8px);"
    role="navigation"
    aria-label="Primary navigation"
>
    <div class="flex items-center justify-around h-[49px]">
        {{ $slot }}
    </div>
</nav>

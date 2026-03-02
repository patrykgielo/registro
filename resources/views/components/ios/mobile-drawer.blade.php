@props([
    'open' => false,                 // Controls visibility (Alpine.js binding)
    'title' => 'Menu',               // Drawer header title
])

{{--
    Mobile navigation drawer component
    - Slides in from right
    - Full height, 80% max width
    - Scrollable content area
    - Close on ESC key or close button click
    - WCAG AA compliant with proper ARIA attributes
--}}

<div
    x-show="open"
    x-transition:enter="transform transition ease-in-out duration-300"
    x-transition:enter-start="translate-x-full"
    x-transition:enter-end="translate-x-0"
    x-transition:leave="transform transition ease-in-out duration-300"
    x-transition:leave-start="translate-x-0"
    x-transition:leave-end="translate-x-full"
    @keydown.escape.window="mobileMenuOpen = false"
    role="dialog"
    aria-modal="true"
    aria-label="Mobile navigation menu"
    class="fixed top-0 right-0 bottom-0 w-80 max-w-[80vw] bg-white shadow-2xl z-50 flex flex-col"
    style="display: none;"
>
    {{-- Header with close button --}}
    <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100">
        <h2 class="text-lg font-semibold text-gray-900">{{ $title }}</h2>

        {{-- Close button --}}
        <button
            @click="mobileMenuOpen = false"
            type="button"
            class="flex items-center justify-center w-10 h-10 text-gray-500 hover:text-gray-700 hover:bg-gray-100 rounded-lg transition-colors"
            aria-label="Close menu"
        >
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
            </svg>
        </button>
    </div>

    {{-- Scrollable content area --}}
    <div class="flex-1 overflow-y-auto">
        {{ $slot }}
    </div>
</div>

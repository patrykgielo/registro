@props([
    'id' => 'bottom-sheet',
    'title' => '',
    'maxWidth' => '640px',
])

<div
    x-data="bottomSheetWidget('{{ $id }}')"
    x-show="isOpen"
    x-cloak
    @open-bottom-sheet.window="if ($event.detail.id === '{{ $id }}') open()"
    @close-bottom-sheet.window="if ($event.detail.id === '{{ $id }}') close()"
    @keydown.escape.window="close()"
    class="bottom-sheet fixed inset-0 z-50"
    role="dialog"
    aria-modal="true"
    :aria-labelledby="'{{ $id }}-title'"
>
    {{-- Backdrop (dim background, click to close) --}}
    <div
        class="bottom-sheet__backdrop fixed inset-0 bg-black transition-opacity duration-300"
        :class="isOpen ? 'opacity-50' : 'opacity-0'"
        @click="close()"
        aria-hidden="true"
    ></div>

    {{-- Bottom Sheet Content --}}
    <div class="bottom-sheet__wrapper fixed inset-0 flex items-center justify-center pointer-events-none p-4">
        <div
            class="bottom-sheet__content relative w-full bg-white rounded-3xl shadow-2xl pointer-events-auto transform transition-all duration-500"
            :class="isOpen ? 'translate-y-0 scale-100 opacity-100' : 'translate-y-8 scale-95 opacity-0'"
            :style="{ maxWidth: '{{ $maxWidth }}' }"
            style="transition-timing-function: cubic-bezier(0.68, -0.55, 0.265, 1.55);"
            @click.stop
        >
            {{-- Safe area padding for iOS notch (bottom) --}}
            <div class="pb-safe">
                {{-- Header with drag handle --}}
                <div class="bottom-sheet__header sticky top-0 bg-white rounded-t-3xl z-10 px-6 pt-4 pb-3 border-b border-gray-200">
                    {{-- Drag handle (visual indicator) --}}
                    <div class="bottom-sheet__handle w-12 h-1.5 bg-gray-300 rounded-full mx-auto mb-4"></div>

                    {{-- Title and Close Button --}}
                    <div class="flex items-center justify-between">
                        <h2
                            id="{{ $id }}-title"
                            class="bottom-sheet__title text-xl font-bold text-gray-900"
                        >
                            {{ $title }}
                        </h2>

                        <button
                            type="button"
                            @click="close()"
                            class="bottom-sheet__close w-10 h-10 rounded-full bg-gray-100 hover:bg-gray-200 active:scale-95 flex items-center justify-center transition-all duration-200"
                            aria-label="Zamknij"
                        >
                            <svg class="w-5 h-5 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>
                </div>

                {{-- Body (scrollable content) --}}
                <div class="bottom-sheet__body max-h-[70vh] overflow-y-auto px-6 py-6">
                    {{ $slot }}
                </div>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
function bottomSheetWidget(id) {
    return {
        isOpen: false,
        id: id,

        init() {
            // Prevent body scroll when bottom sheet is open
            this.$watch('isOpen', (value) => {
                if (value) {
                    document.body.style.overflow = 'hidden';
                } else {
                    document.body.style.overflow = '';
                }
            });
        },

        open() {
            this.isOpen = true;

            // Dispatch opened event for analytics or other listeners
            this.$dispatch('bottom-sheet-opened', { id: this.id });

            // Haptic feedback (iOS)
            if (window.navigator && window.navigator.vibrate) {
                window.navigator.vibrate(10);
            }

            // Focus trap: focus first focusable element
            this.$nextTick(() => {
                const firstFocusable = this.$el.querySelector('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
                if (firstFocusable && firstFocusable !== this.$el.querySelector('.bottom-sheet__close')) {
                    firstFocusable.focus();
                }
            });
        },

        close() {
            this.isOpen = false;

            // Dispatch closed event
            this.$dispatch('bottom-sheet-closed', { id: this.id });

            // Return focus to trigger element (if exists)
            const trigger = document.querySelector(`[data-bottom-sheet-trigger="${this.id}"]`);
            if (trigger) {
                trigger.focus();
            }
        }
    }
}
</script>
@endpush

@push('styles')
<style>
/* Bottom Sheet Component */
.bottom-sheet {
    /* Base container styles handled by Tailwind */
}

/* Backdrop fade transition */
.bottom-sheet__backdrop {
    backdrop-filter: blur(2px);
}

/* Bottom Sheet Content */
.bottom-sheet__content {
    /* iOS-style spring animation (cubic-bezier set inline) */
    max-height: 85vh;
}

/* Safe area padding for iOS devices with notch */
.pb-safe {
    padding-bottom: env(safe-area-inset-bottom, 0px);
}

/* Drag handle hover effect */
.bottom-sheet__handle {
    transition: background-color 0.2s ease;
}

.bottom-sheet__header:hover .bottom-sheet__handle {
    background-color: rgb(209, 213, 219); /* gray-400 */
}

/* Body scrollbar styling (WebKit browsers) */
.bottom-sheet__body::-webkit-scrollbar {
    width: 8px;
}

.bottom-sheet__body::-webkit-scrollbar-track {
    background: transparent;
}

.bottom-sheet__body::-webkit-scrollbar-thumb {
    background: rgb(209, 213, 219); /* gray-300 */
    border-radius: 4px;
}

.bottom-sheet__body::-webkit-scrollbar-thumb:hover {
    background: rgb(156, 163, 175); /* gray-400 */
}

/* Close button hover/active states */
.bottom-sheet__close {
    transition: all 0.2s cubic-bezier(0.68, -0.55, 0.265, 1.55);
}

.bottom-sheet__close:hover {
    background-color: rgb(229, 231, 235); /* gray-200 */
    transform: scale(1.05);
}

.bottom-sheet__close:active {
    transform: scale(0.95);
}

/* Alpine x-cloak (hide until initialized) */
[x-cloak] {
    display: none !important;
}

/* Prevent content shift when scrollbar appears */
.bottom-sheet__body {
    scrollbar-gutter: stable;
}

/* Smooth scroll behavior */
.bottom-sheet__body {
    scroll-behavior: smooth;
    overscroll-behavior: contain; /* Prevent scroll chaining on iOS */
}

/* Focus styles for accessibility */
.bottom-sheet__close:focus-visible {
    outline: 2px solid rgb(249, 115, 22); /* orange-500 */
    outline-offset: 2px;
}
</style>
@endpush

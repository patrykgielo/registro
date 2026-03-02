{{-- Back to Top - iOS-style floating action button --}}
<div
    x-data="{ visible: false }"
    x-init="window.addEventListener('scroll', () => { visible = window.scrollY > 300 })"
    x-show="visible"
    x-transition:enter="transition ease-out duration-300"
    x-transition:enter-start="opacity-0 scale-75"
    x-transition:enter-end="opacity-100 scale-100"
    x-transition:leave="transition ease-in duration-200"
    x-transition:leave-start="opacity-100 scale-100"
    x-transition:leave-end="opacity-0 scale-75"
    x-cloak
    class="fixed bottom-[72px] right-4 md:bottom-6 md:right-6 z-40"
>
    <button
        @click="window.scrollTo({ top: 0, behavior: 'smooth' })"
        type="button"
        aria-label="Przewiń do góry"
        class="
            w-6 h-6 md:w-12 md:h-12
            flex items-center justify-center
            rounded-full
            bg-[#0AB1EA] text-white
            shadow-[0_4px_14px_rgba(0,0,0,0.25)] hover:shadow-[0_6px_20px_rgba(0,0,0,0.3)]
            hover:scale-110
            active:scale-95
            transition-all duration-300
            focus:outline-none focus:ring-2 focus:ring-[#0AB1EA]/50 focus:ring-offset-2
        "
        style="transition-timing-function: cubic-bezier(0.36, 0.66, 0.04, 1);"
    >
        <svg class="w-3 h-3 md:w-5 md:h-5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 15.75l7.5-7.5 7.5 7.5" />
        </svg>
    </button>
</div>

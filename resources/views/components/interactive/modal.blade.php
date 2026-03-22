@props([
    'name' => 'modal',
    'maxWidth' => 'lg',
    'title' => null,
])

@php
$maxWidthClasses = match($maxWidth) {
    'sm' => 'max-w-sm',
    'md' => 'max-w-md',
    'lg' => 'max-w-lg',
    'xl' => 'max-w-xl',
    '2xl' => 'max-w-2xl',
    default => 'max-w-lg',
};
@endphp

<div
    x-data="{ open: false }"
    x-on:open-{{ $name }}.window="open = true"
    x-on:close-{{ $name }}.window="open = false"
    @keydown.escape.window="open = false"
    {{ $attributes }}
>
    {{-- Backdrop --}}
    <div
        x-show="open"
        x-transition:enter="duration-200 ease-out"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="duration-150 ease-in"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        class="fixed inset-0 z-[var(--z-modal-backdrop)] bg-surface-overlay"
        @click="open = false"
        x-cloak
    ></div>

    {{-- Panel --}}
    <div
        x-show="open"
        x-transition:enter="duration-200 ease-out"
        x-transition:enter-start="opacity-0 scale-95 translate-y-4"
        x-transition:enter-end="opacity-100 scale-100 translate-y-0"
        x-transition:leave="duration-150 ease-in"
        x-transition:leave-start="opacity-100 scale-100"
        x-transition:leave-end="opacity-0 scale-95"
        x-trap.inert.noscroll="open"
        class="fixed inset-0 z-[var(--z-modal)] flex items-center justify-center p-4"
        role="dialog"
        aria-modal="true"
        @if($title) aria-label="{{ $title }}" @endif
        x-cloak
    >
        <div class="w-full {{ $maxWidthClasses }} rounded-xl bg-surface-raised shadow-xl border border-border" @click.stop>
            @if($title)
                <div class="flex items-center justify-between border-b border-border px-6 py-4">
                    <h3 class="text-lg font-semibold text-text-primary">{{ $title }}</h3>
                    <button @click="open = false" class="text-text-muted hover:text-text-primary rounded-lg p-1">
                        <x-heroicon-m-x-mark class="h-5 w-5" />
                    </button>
                </div>
            @endif

            <div class="p-6">
                {{ $slot }}
            </div>
        </div>
    </div>
</div>

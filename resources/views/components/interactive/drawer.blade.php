@props([
    'name' => 'drawer',
    'side' => 'right',
    'title' => null,
    'width' => 'md',
])

@php
$widthClasses = match($width) {
    'sm' => 'max-w-sm',
    'md' => 'max-w-md',
    'lg' => 'max-w-lg',
    default => 'max-w-md',
};
$sideClasses = match($side) {
    'left' => 'left-0',
    default => 'right-0',
};
$enterStart = $side === 'left' ? '-translate-x-full' : 'translate-x-full';
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
        x-transition.opacity.duration.200ms
        class="fixed inset-0 z-[var(--z-modal-backdrop)] bg-surface-overlay"
        @click="open = false"
        x-cloak
    ></div>

    {{-- Panel --}}
    <div
        x-show="open"
        x-transition:enter="duration-300 ease-out"
        x-transition:enter-start="{{ $enterStart }}"
        x-transition:enter-end="translate-x-0"
        x-transition:leave="duration-200 ease-in"
        x-transition:leave-start="translate-x-0"
        x-transition:leave-end="{{ $enterStart }}"
        x-trap.inert.noscroll="open"
        class="fixed inset-y-0 {{ $sideClasses }} z-[var(--z-modal)] w-full {{ $widthClasses }} bg-surface-raised shadow-xl border-l border-border"
        role="dialog"
        aria-modal="true"
        @if($title) aria-label="{{ $title }}" @endif
        x-cloak
    >
        <div class="flex h-full flex-col">
            @if($title)
                <div class="flex items-center justify-between border-b border-border px-6 py-4 shrink-0">
                    <h3 class="text-lg font-semibold text-text-primary">{{ $title }}</h3>
                    <button @click="open = false" class="text-text-muted hover:text-text-primary rounded-lg p-1">
                        <x-heroicon-m-x-mark class="h-5 w-5" />
                    </button>
                </div>
            @endif

            <div class="flex-1 overflow-y-auto p-6">
                {{ $slot }}
            </div>
        </div>
    </div>
</div>

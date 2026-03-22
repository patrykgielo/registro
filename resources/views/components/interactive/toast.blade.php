{{-- Toast Container — place once in layout, listens for events --}}

<div
    x-data="{
        toasts: [],
        add(toast) {
            const id = Date.now();
            this.toasts.push({ id, ...toast });
            setTimeout(() => this.remove(id), toast.duration || 5000);
        },
        remove(id) {
            this.toasts = this.toasts.filter(t => t.id !== id);
        }
    }"
    @toast.window="add($event.detail)"
    class="fixed bottom-4 right-4 z-[var(--z-toast)] flex flex-col gap-2 pointer-events-none"
    aria-live="polite"
>
    <template x-for="toast in toasts" :key="toast.id">
        <div
            x-show="true"
            x-transition:enter="duration-300 ease-out"
            x-transition:enter-start="opacity-0 translate-y-4 scale-95"
            x-transition:enter-end="opacity-100 translate-y-0 scale-100"
            x-transition:leave="duration-200 ease-in"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0 translate-x-8"
            class="pointer-events-auto flex items-center gap-3 rounded-lg border border-border bg-surface-raised px-4 py-3 shadow-lg max-w-sm"
        >
            <template x-if="toast.variant === 'success'">
                <x-heroicon-m-check-circle class="h-5 w-5 text-success shrink-0" />
            </template>
            <template x-if="toast.variant === 'error'">
                <x-heroicon-m-x-circle class="h-5 w-5 text-error shrink-0" />
            </template>
            <template x-if="toast.variant === 'warning'">
                <x-heroicon-m-exclamation-triangle class="h-5 w-5 text-warning shrink-0" />
            </template>
            <template x-if="!toast.variant || toast.variant === 'info'">
                <x-heroicon-m-information-circle class="h-5 w-5 text-info shrink-0" />
            </template>

            <p class="text-sm text-text-primary" x-text="toast.message"></p>

            <button @click="remove(toast.id)" class="shrink-0 text-text-muted hover:text-text-primary ml-auto">
                <x-heroicon-m-x-mark class="h-4 w-4" />
            </button>
        </div>
    </template>
</div>

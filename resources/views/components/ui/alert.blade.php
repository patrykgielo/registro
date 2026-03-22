@props([
    'variant' => 'info',
    'title' => null,
    'dismissible' => false,
    'icon' => null,
])

@php
$variants = [
    'info' => ['bg' => 'bg-info/5 border-info/20', 'icon' => 'information-circle', 'iconColor' => 'text-info'],
    'success' => ['bg' => 'bg-success/5 border-success/20', 'icon' => 'check-circle', 'iconColor' => 'text-success'],
    'warning' => ['bg' => 'bg-warning/5 border-warning/20', 'icon' => 'exclamation-triangle', 'iconColor' => 'text-warning'],
    'error' => ['bg' => 'bg-error/5 border-error/20', 'icon' => 'x-circle', 'iconColor' => 'text-error'],
];
$config = $variants[$variant] ?? $variants['info'];
$iconName = $icon ?? $config['icon'];
@endphp

<div
    {{ $attributes->class([
        'rounded-lg border p-4',
        $config['bg'],
    ]) }}
    role="alert"
    @if($dismissible) x-data="{ open: true }" x-show="open" x-transition @endif
>
    <div class="flex gap-3">
        <x-dynamic-component :component="'heroicon-m-' . $iconName" class="h-5 w-5 shrink-0 {{ $config['iconColor'] }}" />

        <div class="flex-1 text-sm">
            @if($title)
                <p class="font-medium text-text-primary">{{ $title }}</p>
            @endif
            <div class="text-text-secondary {{ $title ? 'mt-1' : '' }}">{{ $slot }}</div>
        </div>

        @if($dismissible)
            <button @click="open = false" class="shrink-0 text-text-muted hover:text-text-primary">
                <x-heroicon-m-x-mark class="h-4 w-4" />
            </button>
        @endif
    </div>
</div>

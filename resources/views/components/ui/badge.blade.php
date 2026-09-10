@props([
    'variant' => 'default',
    'size' => 'md',
    'dot' => false,
    'icon' => null,
    'dark' => false,
])

@php
// Faza 5 contrast fix (123k99cu9u0) — the status variants below (success/
// warning/error/info) render `bg-{color}/10 text-{color}`, a semi-transparent
// pill. The --color-success/warning/error/info tokens only clear 4.5:1 WCAG
// contrast once flattened onto ONE of the two card surfaces this badge
// actually renders on (see design-tokens.css's "Status badge" comment for
// the numbers) — $dark switches to a second, badge-only token set tuned for
// --color-dark-bg-raised, same $isDark class-swap pattern as
// ios/service-card.blade.php. Only status variants are surface-aware:
// 'default'/'brand' are never rendered on a dark card by any caller today
// (grep `x-ui.badge` before adding one that is).
$statusVariants = $dark ? [
    'success' => 'bg-badge-success-dark/10 text-badge-success-dark',
    'warning' => 'bg-badge-warning-dark/10 text-badge-warning-dark',
    'error' => 'bg-badge-error-dark/10 text-badge-error-dark',
    'info' => 'bg-badge-info-dark/10 text-badge-info-dark',
] : [
    'success' => 'bg-badge-success/10 text-badge-success',
    'warning' => 'bg-badge-warning/10 text-badge-warning',
    'error' => 'bg-badge-error/10 text-badge-error',
    'info' => 'bg-badge-info/10 text-badge-info',
];

$variants = [
    'default' => 'bg-surface-sunken text-text-secondary',
    'brand' => 'bg-brand-subtle text-brand',
    ...$statusVariants,
];

$sizes = [
    'sm' => 'text-xs px-2 py-0.5',
    'md' => 'text-xs px-2.5 py-1',
    'lg' => 'text-sm px-3 py-1',
];

$statusDotColors = $dark ? [
    'success' => 'bg-badge-success-dark',
    'warning' => 'bg-badge-warning-dark',
    'error' => 'bg-badge-error-dark',
    'info' => 'bg-badge-info-dark',
] : [
    'success' => 'bg-badge-success',
    'warning' => 'bg-badge-warning',
    'error' => 'bg-badge-error',
    'info' => 'bg-badge-info',
];

$dotColors = [
    'default' => 'bg-text-muted',
    'brand' => 'bg-brand',
    ...$statusDotColors,
];
@endphp

<span {{ $attributes->class([
    'inline-flex items-center gap-1.5 rounded-full font-medium',
    $variants[$variant] ?? $variants['default'],
    $sizes[$size] ?? $sizes['md'],
]) }}>
    @if($dot)
        <span class="h-1.5 w-1.5 rounded-full {{ $dotColors[$variant] ?? $dotColors['default'] }}"></span>
    @elseif($icon)
        <x-dynamic-component :component="'heroicon-m-' . $icon" class="h-3.5 w-3.5 shrink-0" />
    @endif

    {{ $slot }}
</span>

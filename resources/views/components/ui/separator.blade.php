@props([
    'label' => null,
])

@if($label)
    <div {{ $attributes->class(['relative']) }}>
        <div class="absolute inset-0 flex items-center" aria-hidden="true">
            <div class="w-full border-t border-border"></div>
        </div>
        <div class="relative flex justify-center">
            <span class="bg-surface px-3 text-sm text-text-muted">{{ $label }}</span>
        </div>
    </div>
@else
    <hr {{ $attributes->class(['border-t border-border']) }} />
@endif

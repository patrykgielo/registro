@props([
    'label' => null,
    'hint' => null,
    'error' => null,
    'icon' => null,
    'type' => 'text',
])

<div {{ $attributes->only('class')->class(['space-y-1.5']) }}>
    @if($label)
        <label for="{{ $attributes->get('id', $attributes->get('name')) }}" class="block text-sm font-medium text-text-primary">
            {{ $label }}
        </label>
    @endif

    <div class="relative">
        @if($icon)
            <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
                <x-dynamic-component :component="'heroicon-m-' . $icon" class="h-4 w-4 text-text-muted" />
            </div>
        @endif

        <input
            type="{{ $type }}"
            {{ $attributes->except('class')->merge([
                'id' => $attributes->get('name'),
            ]) }}
            @class([
                'block w-full rounded-lg border bg-surface-raised px-3 py-2.5 text-sm text-text-primary placeholder:text-text-muted',
                'transition-colors duration-200 ease-out',
                'focus:border-brand focus:ring-2 focus:ring-brand/20 focus:outline-none',
                'border-error focus:border-error focus:ring-error/20' => $error,
                'border-border hover:border-border-strong' => !$error,
                'pl-9' => $icon,
            ])
        />
    </div>

    @if($error)
        <p class="text-sm text-error">{{ $error }}</p>
    @elseif($hint)
        <p class="text-sm text-text-muted">{{ $hint }}</p>
    @endif
</div>

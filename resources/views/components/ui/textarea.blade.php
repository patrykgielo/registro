@props([
    'label' => null,
    'hint' => null,
    'error' => null,
    'rows' => 4,
])

<div {{ $attributes->only('class')->class(['space-y-1.5']) }}>
    @if($label)
        <label for="{{ $attributes->get('id', $attributes->get('name')) }}" class="block text-sm font-medium text-text-primary">
            {{ $label }}
        </label>
    @endif

    <textarea
        rows="{{ $rows }}"
        {{ $attributes->except('class')->merge(['id' => $attributes->get('name')]) }}
        @class([
            'block w-full rounded-lg border bg-surface-raised px-3 py-2.5 text-sm text-text-primary placeholder:text-text-muted resize-y',
            'transition-colors duration-200 ease-out',
            'focus:border-brand focus:ring-2 focus:ring-brand/20 focus:outline-none',
            'border-error focus:border-error focus:ring-error/20' => $error,
            'border-border hover:border-border-strong' => !$error,
        ])
    >{{ $slot }}</textarea>

    @if($error)
        <p class="text-sm text-error">{{ $error }}</p>
    @elseif($hint)
        <p class="text-sm text-text-muted">{{ $hint }}</p>
    @endif
</div>

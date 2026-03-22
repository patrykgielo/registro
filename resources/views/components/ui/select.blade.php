@props([
    'label' => null,
    'hint' => null,
    'error' => null,
    'placeholder' => null,
    'options' => [],
])

<div {{ $attributes->only('class')->class(['space-y-1.5']) }}>
    @if($label)
        <label for="{{ $attributes->get('id', $attributes->get('name')) }}" class="block text-sm font-medium text-text-primary">
            {{ $label }}
        </label>
    @endif

    <select
        {{ $attributes->except('class')->merge(['id' => $attributes->get('name')]) }}
        @class([
            'block w-full rounded-lg border bg-surface-raised px-3 py-2.5 text-sm text-text-primary',
            'transition-colors duration-200 ease-out appearance-none',
            'bg-[url("data:image/svg+xml,%3csvg xmlns=%27http://www.w3.org/2000/svg%27 fill=%27none%27 viewBox=%270 0 20 20%27%3e%3cpath stroke=%27%236b7280%27 stroke-linecap=%27round%27 stroke-linejoin=%27round%27 stroke-width=%271.5%27 d=%27M6 8l4 4 4-4%27/%3e%3c/svg%3e")] bg-[length:1.25rem_1.25rem] bg-[right_0.5rem_center] bg-no-repeat pr-10',
            'focus:border-brand focus:ring-2 focus:ring-brand/20 focus:outline-none',
            'border-error focus:border-error focus:ring-error/20' => $error,
            'border-border hover:border-border-strong' => !$error,
        ])
    >
        @if($placeholder)
            <option value="" disabled {{ $attributes->get('value') ? '' : 'selected' }}>{{ $placeholder }}</option>
        @endif

        @if(is_array($options) && count($options) > 0)
            @foreach($options as $value => $optionLabel)
                <option value="{{ $value }}">{{ $optionLabel }}</option>
            @endforeach
        @else
            {{ $slot }}
        @endif
    </select>

    @if($error)
        <p class="text-sm text-error">{{ $error }}</p>
    @elseif($hint)
        <p class="text-sm text-text-muted">{{ $hint }}</p>
    @endif
</div>

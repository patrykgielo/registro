@props([
    'type' => 'text',
    'name' => '',
    'id' => null,
    'label' => '',
    'placeholder' => '',
    'value' => '',
    'required' => false,
    'autofocus' => false,
    'autocomplete' => null,
    'error' => null,
    'icon' => null,
    'helpText' => null,
])

@php
    $inputId = $id ?? $name;
    $hasError = $error || ($errors?->has($name) ?? false);
    $errorMessage = $error ?? $errors?->first($name);
@endphp

<div class="ios-input-group mb-6">
    {{-- Label --}}
    @if($label)
    <label for="{{ $inputId }}" class="block text-sm font-semibold text-gray-900 mb-2">
        {{ $label }}
        @if($required)
            <span class="text-red-500 ml-1">*</span>
        @endif
    </label>
    @endif

    {{-- Input Container --}}
    <div class="relative">
        {{-- Icon (if provided) --}}
        @if($icon)
        <div class="absolute left-4 top-1/2 transform -translate-y-1/2 pointer-events-none">
            @switch($icon)
                @case('email')
                    <x-heroicon-o-envelope class="w-5 h-5 text-gray-400" />
                    @break
                @case('password')
                    <x-heroicon-o-lock-closed class="w-5 h-5 text-gray-400" />
                    @break
                @case('user')
                    <x-heroicon-o-user class="w-5 h-5 text-gray-400" />
                    @break
                @case('phone')
                    <x-heroicon-o-phone class="w-5 h-5 text-gray-400" />
                    @break
                @case('map-pin')
                    <x-heroicon-o-map-pin class="w-5 h-5 text-gray-400" />
                    @break
                @case('map')
                    <x-heroicon-o-map class="w-5 h-5 text-gray-400" />
                    @break
                @case('building-office')
                    <x-heroicon-o-building-office class="w-5 h-5 text-gray-400" />
                    @break
                @case('globe-alt')
                    <x-heroicon-o-globe-alt class="w-5 h-5 text-gray-400" />
                    @break
                @default
                    <x-heroicon-o-information-circle class="w-5 h-5 text-gray-400" />
            @endswitch
        </div>
        @endif

        {{-- Input Field --}}
        <input
            type="{{ $type }}"
            id="{{ $inputId }}"
            name="{{ $name }}"
            value="{{ old($name, $value) }}"
            placeholder="{{ $placeholder }}"
            {{ $required ? 'required' : '' }}
            {{ $autofocus ? 'autofocus' : '' }}
            {{ $autocomplete ? 'autocomplete=' . $autocomplete : '' }}
            {{ $attributes->class([
                'ios-input w-full px-4 py-3.5 rounded-xl border-2 transition-all duration-200 ios-spring text-gray-900 text-base placeholder:text-gray-400',
                'pl-12' => $icon,
                'border-gray-200 focus:border-brand focus:ring-4 focus:ring-brand/10' => !$hasError,
                'border-red-500 focus:border-red-500 focus:ring-4 focus:ring-red-500/10 bg-red-50' => $hasError,
            ]) }}
        >

        {{-- Password Toggle Button (for password inputs) --}}
        @if($type === 'password')
        <button
            type="button"
            onclick="togglePasswordVisibility('{{ $inputId }}')"
            class="absolute right-4 top-1/2 transform -translate-y-1/2 text-gray-400 hover:text-gray-600 transition-colors ios-spring focus:outline-none focus:ring-2 focus:ring-brand rounded-lg p-1"
            aria-label="Toggle password visibility">
            <x-heroicon-o-eye class="w-5 h-5 eye-icon" />
            <x-heroicon-o-eye-slash class="w-5 h-5 eye-slash-icon hidden" />
        </button>
        @endif
    </div>

    {{-- Error Message --}}
    @if($hasError)
    <p class="mt-2 text-sm text-red-600 flex items-start gap-1 animate-fade-in-up">
        <x-heroicon-m-exclamation-circle class="w-4 h-4 flex-shrink-0 mt-0.5" />
        <span>{{ $errorMessage }}</span>
    </p>
    @endif

    {{-- Help Text --}}
    @if($helpText && !$hasError)
    <p class="mt-2 text-sm text-gray-600">
        {{ $helpText }}
    </p>
    @endif
</div>

<script>
    function togglePasswordVisibility(inputId) {
        const input = document.getElementById(inputId);
        const container = input.closest('.ios-input-group');
        const eyeIcon = container.querySelector('.eye-icon');
        const eyeSlashIcon = container.querySelector('.eye-slash-icon');

        if (input.type === 'password') {
            input.type = 'text';
            eyeIcon.classList.add('hidden');
            eyeSlashIcon.classList.remove('hidden');
        } else {
            input.type = 'password';
            eyeIcon.classList.remove('hidden');
            eyeSlashIcon.classList.add('hidden');
        }
    }
</script>

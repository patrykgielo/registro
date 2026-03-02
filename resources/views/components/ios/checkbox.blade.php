@props([
    'name' => '',
    'id' => null,
    'label' => '',
    'checked' => false,
    'value' => '1',
    'style' => 'toggle', // 'toggle' or 'checkbox'
])

@php
    $inputId = $id ?? $name;
    $isChecked = old($name, $checked);
@endphp

<div class="ios-checkbox-group">
    @if($style === 'toggle')
        {{-- iOS Toggle Switch --}}
        <label for="{{ $inputId }}" class="flex items-center justify-between cursor-pointer group">
            <span class="text-base text-gray-900 font-medium group-hover:text-primary transition-colors ios-spring">
                {{ $label }}
            </span>

            <div class="relative">
                <input
                    type="checkbox"
                    id="{{ $inputId }}"
                    name="{{ $name }}"
                    value="{{ $value }}"
                    {{ $isChecked ? 'checked' : '' }}
                    class="sr-only peer"
                    {{ $attributes }}
                >
                <div class="ios-toggle w-[51px] h-[31px] bg-gray-300 rounded-full peer peer-checked:bg-green-500 peer-focus:ring-4 peer-focus:ring-green-500/20 transition-all duration-300 ios-spring">
                    <div class="ios-toggle-thumb absolute top-[2px] left-[2px] w-[27px] h-[27px] bg-white rounded-full shadow-md transition-transform duration-300 peer-checked:translate-x-[20px]"></div>
                </div>
            </div>
        </label>
    @else
        {{-- iOS Checkbox (Square) --}}
        <label for="{{ $inputId }}" class="flex items-center cursor-pointer group">
            <div class="relative flex items-center">
                <input
                    type="checkbox"
                    id="{{ $inputId }}"
                    name="{{ $name }}"
                    value="{{ $value }}"
                    {{ $isChecked ? 'checked' : '' }}
                    class="sr-only peer"
                    {{ $attributes }}
                >
                <div class="ios-checkbox w-6 h-6 border-2 border-gray-300 rounded-lg peer-checked:bg-primary peer-checked:border-primary peer-focus:ring-4 peer-focus:ring-primary/20 transition-all duration-200 ios-spring flex items-center justify-center">
                    <x-heroicon-m-check class="w-4 h-4 text-white opacity-0 peer-checked:opacity-100 transition-opacity duration-200" />
                </div>
            </div>

            @if($label)
            <span class="ml-3 text-base text-gray-900 group-hover:text-primary transition-colors ios-spring">
                {{ $label }}
            </span>
            @endif
        </label>
    @endif
</div>

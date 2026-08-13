@php
    $settings = app(\App\Support\Settings\SettingsManager::class);
@endphp
<img
    src="{{ $logo }}"
    alt="{{ $settings->logoAlt() }}"
    class="h-10"
/>

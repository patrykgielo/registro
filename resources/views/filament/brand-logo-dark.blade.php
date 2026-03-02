@php
    $settings = app(\App\Support\Settings\SettingsManager::class);
@endphp
<img
    src="{{ $settings->headerLogo() }}"
    alt="{{ $settings->logoAlt() }}"
    class="h-10"
/>

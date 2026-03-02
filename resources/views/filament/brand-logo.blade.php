@php
    $settings = app(\App\Support\Settings\SettingsManager::class);
@endphp
<img
    src="{{ $settings->footerLogo() }}"
    alt="{{ $settings->logoAlt() }}"
    class="h-10"
/>

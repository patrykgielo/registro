@php
    $settings = app(\App\Support\Settings\SettingsManager::class);
    $gtmEnabled = $settings->get('integrations.gtm_enabled', false);
    $gtmId = $settings->get('integrations.gtm_container_id');
@endphp

@if($gtmEnabled && $gtmId)
<!-- Google Tag Manager (noscript) -->
<noscript><iframe src="https://www.googletagmanager.com/ns.html?id={{ $gtmId }}"
height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>
<!-- End Google Tag Manager (noscript) -->
@endif

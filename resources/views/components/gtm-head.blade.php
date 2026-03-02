@php
    $settings = app(\App\Support\Settings\SettingsManager::class);
    $gtmEnabled = $settings->get('integrations.gtm_enabled', false);
    $gtmId = $settings->get('integrations.gtm_container_id');
    // Validate GTM ID format (GTM-XXXXXXX)
    $isValidGtmId = $gtmId && preg_match('/^GTM-[A-Z0-9]{6,8}$/', $gtmId);
@endphp

@if($gtmEnabled && $isValidGtmId)
<!-- Google Tag Manager -->
<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':
new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],
j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=
'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);
})(window,document,'script','dataLayer','{{ $gtmId }}');</script>
<!-- End Google Tag Manager -->
@endif

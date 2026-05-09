<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? config('app.name') }}</title>

    <x-gtm-head />
    @vite(['resources/css/app.css', 'resources/js/app.js'])

    {{-- ─── Brand CSS Variables ─── --}}
    @php
        $__sm = app(\App\Support\Settings\SettingsManager::class);
        $__brandColor = $__sm->brandColor();
        $__fontFamily = $__sm->fontFamily();
        $__palette = \App\Support\ColorScaleGenerator::generate($__brandColor);
        $__cssVars = \App\Support\ColorScaleGenerator::toCssVariables($__brandColor);
        $__fontStack = match ($__fontFamily) {
            'inter'      => "'Inter', sans-serif",
            'roboto'     => "'Roboto', sans-serif",
            'poppins'    => "'Poppins', sans-serif",
            'montserrat' => "'Montserrat', sans-serif",
            default      => "system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif",
        };
    @endphp

    @if ($__fontFamily !== 'system')
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family={{ $__fontFamily }}:400,500,600,700&display=swap" rel="stylesheet">
    @endif

    <style>
        :root {
            {!! $__cssVars !!}
            --color-brand: {{ $__palette['500'] }};
            --color-brand-hover: {{ $__palette['600'] }};
            --color-brand-subtle: {{ $__palette['50'] }};
            --color-border-focus: {{ $__palette['500'] }}80;
            --font-brand: {{ $__fontStack }};
        }
    </style>

    @stack('head')
</head>
<body class="bg-surface min-h-screen flex flex-col antialiased">
    <x-gtm-body />

    {{-- Skip Link (WCAG 2.2 AA) --}}
    <a href="#main-content"
       class="sr-only focus:not-sr-only focus:fixed focus:top-4 focus:left-4 focus:z-[999] focus:px-4 focus:py-2 focus:bg-brand focus:text-text-inverse focus:rounded-lg">
        Przejdź do treści głównej
    </a>

    @php
        $contact = app(\App\Support\Settings\SettingsManager::class)->contactInformation();
        $contactPhone = $contact['phone'] ?? null;
        $bookingEnabled = app(\App\Support\Settings\SettingsManager::class)->isBookingEnabled();
        $registrationEnabled = app(\App\Support\Settings\SettingsManager::class)->isRegistrationEnabled();
    @endphp

    {{-- ─── Header ─── --}}
    <x-nav.header
        :contact-phone="$contactPhone"
        :booking-enabled="$bookingEnabled"
        :registration-enabled="$registrationEnabled"
    />

    {{-- ─── Main Content ─── --}}
    <main id="main-content" class="flex-1">
        {{-- Flash Messages --}}
        @if (session('success') || session('error') || session('info') || $errors->any())
            <x-layout.container class="pt-6">
                @if (session('success'))
                    <x-ui.alert variant="success" dismissible>{{ session('success') }}</x-ui.alert>
                @endif
                @if (session('info'))
                    <x-ui.alert variant="info" dismissible>{{ session('info') }}</x-ui.alert>
                @endif
                @if (session('error'))
                    <x-ui.alert variant="error" dismissible>{{ session('error') }}</x-ui.alert>
                @endif
                @if ($errors->any())
                    <x-ui.alert variant="error" title="Wystąpiły błędy" dismissible>
                        <ul class="list-disc list-inside mt-1">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </x-ui.alert>
                @endif
            </x-layout.container>
        @endif

        @yield('content')
    </main>

    {{-- ─── Footer ─── --}}
    <x-nav.footer :contact="$contact" />

    {{-- ─── Toast Container ─── --}}
    <x-interactive.toast />

    @stack('scripts')
</body>
</html>

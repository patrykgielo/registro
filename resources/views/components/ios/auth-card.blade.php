@props([
    'title' => '',
    'subtitle' => null,
    'showLogo' => true,
])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $title }} - {{ config('app.name', 'Registro') }}</title>

    {{-- Vite Assets --}}
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="antialiased">
    <div class="min-h-screen flex flex-col justify-center py-12 sm:px-6 lg:px-8 relative overflow-hidden bg-primary-600">
    {{-- Noise Texture Overlay (App Store style) --}}
    <div class="absolute inset-0 opacity-5 mix-blend-overlay" style="background-image: url('data:image/svg+xml,%3Csvg viewBox=\'0 0 400 400\' xmlns=\'http://www.w3.org/2000/svg\'%3E%3Cfilter id=\'noiseFilter\'%3E%3CfeTurbulence type=\'fractalNoise\' baseFrequency=\'0.9\' numOctaves=\'4\' stitchTiles=\'stitch\'/%3E%3C/filter%3E%3Crect width=\'100%25\' height=\'100%25\' filter=\'url(%23noiseFilter)\'/%3E%3C/svg%3E');"></div>

    {{-- Monochrome Gradient Orbs --}}
    <div class="absolute inset-0 overflow-hidden pointer-events-none">
        <div class="absolute top-0 -left-4 w-96 h-96 rounded-full bg-gradient-radial from-primary-500/15 to-transparent blur-3xl animate-blob"></div>
        <div class="absolute top-0 -right-4 w-96 h-96 rounded-full bg-gradient-radial from-primary-400/12 to-transparent blur-3xl animate-blob animation-delay-2000"></div>
        <div class="absolute -bottom-8 left-20 w-96 h-96 rounded-full bg-gradient-radial from-primary-300/10 to-transparent blur-3xl animate-blob animation-delay-4000"></div>
    </div>

    <div class="relative sm:mx-auto sm:w-full sm:max-w-md">
        {{-- Logo Section --}}
        @if($showLogo)
        <div class="flex justify-center mb-8 animate-fade-in-up">
            <div class="bg-white/10 backdrop-blur-xl rounded-lg p-4 shadow-2xl border border-white/20">
                {{-- Logo - You can customize this --}}
                <div class="w-16 h-16 bg-white rounded-lg flex items-center justify-center">
                    <svg class="w-10 h-10 text-primary" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                    </svg>
                </div>
            </div>
        </div>
        @endif

        {{-- Title Section --}}
        @if($title)
        <div class="text-center mb-8 animate-fade-in-up animation-delay-200">
            <h2 class="text-3xl md:text-4xl font-bold text-white drop-shadow-lg">
                {{ $title }}
            </h2>
            @if($subtitle)
            <p class="mt-2 text-lg text-white/90 drop-shadow">
                {{ $subtitle }}
            </p>
            @endif
        </div>
        @endif
    </div>

    {{-- Glass Card Container --}}
    <div class="relative mt-8 sm:mx-auto sm:w-full sm:max-w-md animate-fade-in-up animation-delay-400">
        <div class="bg-white/95 backdrop-blur-xl py-10 px-6 shadow-2xl rounded-lg border border-white/50 sm:px-12">
            {{ $slot }}
        </div>

        {{-- Footer Links --}}
        @if(isset($footer))
        <div class="mt-6 text-center animate-fade-in-up animation-delay-600">
            <div class="bg-white/10 backdrop-blur-xl rounded-lg py-4 px-6 border border-white/20">
                {{ $footer }}
            </div>
        </div>
        @endif
    </div>
</div>
</body>
</html>

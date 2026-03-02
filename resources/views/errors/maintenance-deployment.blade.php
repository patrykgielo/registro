<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Trwa konserwacja - {{ config('app.name') }}</title>
    @vite(['resources/css/app.css'])
    <style>
        @keyframes pulse-slow {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
        .animate-pulse-slow {
            animation: pulse-slow 3s ease-in-out infinite;
        }
    </style>
</head>

@php
// Contact information
$contact = app(\App\Support\Settings\SettingsManager::class)->contactInformation();
@endphp

<body>
    <!-- Full-Screen Background Container -->
    <div class="relative min-h-screen bg-cover bg-center bg-fixed" style="background: linear-gradient(135deg, #3b82f6 0%, #1e40af 100%)">

        <!-- 75% Black Overlay -->
        <div class="absolute inset-0 bg-black/75"></div>

        <!-- Content (z-index above overlay) -->
        <div class="relative z-10 min-h-screen flex items-center justify-center p-4 sm:p-6">

            <!-- Glassmorphism Content Card -->
            <div class="w-full max-w-lg md:max-w-xl bg-white/10 backdrop-blur-lg rounded-2xl shadow-2xl border border-white/20 p-6 sm:p-8 md:p-10">

                <!-- Icon -->
                <div class="flex justify-center mb-6">
                    <div class="relative">
                        <svg class="w-20 h-20 text-white animate-pulse-slow" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                        </svg>
                        <div class="absolute -bottom-1 -right-1 bg-white text-blue-600 rounded-full w-8 h-8 flex items-center justify-center">
                            <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-12a1 1 0 10-2 0v4a1 1 0 00.293.707l2.828 2.829a1 1 0 101.415-1.415L11 9.586V6z" clip-rule="evenodd"/>
                            </svg>
                        </div>
                    </div>
                </div>

                <!-- Heading -->
                <h1 class="text-2xl sm:text-3xl md:text-4xl font-bold text-white text-center mb-4">
                    Trwa konserwacja systemu
                </h1>

                <!-- Type Badge -->
                <div class="flex justify-center mb-6">
                    <div class="inline-flex items-center px-4 py-2 rounded-full bg-white/20 backdrop-blur-sm text-white text-sm font-semibold">
                        <svg class="w-4 h-4 mr-2" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/>
                        </svg>
                        {{ $type->label() }}
                    </div>
                </div>

                <!-- Message -->
                @if(isset($config['message']) && $config['message'])
                    <p class="text-base text-white/90 text-center mb-6 leading-relaxed">
                        {{ $config['message'] }}
                    </p>
                @else
                    <p class="text-base text-white/90 text-center mb-6 leading-relaxed">
                        Przepraszamy za niedogodności. Aktualnie wykonujemy prace konserwacyjne w celu poprawy jakości naszych usług.
                        Postaramy się wrócić tak szybko, jak to możliwe.
                    </p>
                @endif

                <!-- Estimated Duration -->
                @if(isset($config['estimated_duration']) && $config['estimated_duration'])
                    <div class="bg-white/20 backdrop-blur-sm border border-white/30 rounded-xl p-4 mb-6">
                        <div class="flex items-center justify-center text-white">
                            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                            <span class="font-semibold">Szacowany czas: {{ $config['estimated_duration'] }}</span>
                        </div>
                    </div>
                @endif

                <!-- Contact Info -->
                <div class="border-t border-white/20 pt-6 mb-6">
                    <p class="text-sm text-white/70 text-center mb-4">W razie pilnych spraw, skontaktuj się z nami:</p>
                    <div class="flex flex-col sm:flex-row items-center justify-center gap-3">
                        @if(!empty($contact['email']))
                            <a href="mailto:{{ $contact['email'] }}" class="flex items-center px-4 py-2 bg-white/20 hover:bg-white/30 rounded-lg text-white transition-all duration-200">
                                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                                </svg>
                                <span class="text-sm font-medium">{{ $contact['email'] }}</span>
                            </a>
                        @endif

                        @if(!empty($contact['phone']))
                            <a href="tel:{{ $contact['phone'] }}" class="flex items-center px-4 py-2 bg-white/20 hover:bg-white/30 rounded-lg text-white transition-all duration-200">
                                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/>
                                </svg>
                                <span class="text-sm font-medium">{{ $contact['phone'] }}</span>
                            </a>
                        @endif
                    </div>
                </div>

                <!-- Retry Info -->
                <div class="text-center mb-4">
                    <p class="text-xs text-white/60">Strona automatycznie spróbuje ponownie za {{ $retry_after }} sekund</p>
                </div>

                <!-- Retry Button -->
                <div class="text-center">
                    <button onclick="location.reload()" class="inline-flex items-center px-6 py-3 bg-white/20 hover:bg-white/30 backdrop-blur-sm text-white font-semibold rounded-lg shadow-lg transition-all duration-200 hover:shadow-xl border border-white/30">
                        <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                        </svg>
                        Odśwież stronę
                    </button>
                </div>

            </div>

        </div>

        <!-- Footer -->
        <div class="absolute bottom-0 left-0 right-0 z-10 p-6 text-center">
            <p class="text-sm text-white/60">
                &copy; {{ date('Y') }} {{ config('app.name') }}. Wszelkie prawa zastrzeżone.
            </p>
        </div>

    </div>

    <!-- Auto-refresh script -->
    <script>
        // Auto-refresh after retry_after seconds
        setTimeout(() => {
            location.reload();
        }, {{ $retry_after * 1000 }});
    </script>
</body>
</html>

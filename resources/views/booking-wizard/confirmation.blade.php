<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Rezerwacja Potwierdzona - {{ config('app.name') }}</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-gray-50 min-h-screen">
    <div class="confirmation-screen py-8 px-4">
        <div class="confirmation-screen__container max-w-3xl mx-auto">
            {{-- Success Header --}}
            <div class="confirmation-screen__header text-center mb-8 animate-fade-in">
                {{-- Success Icon (animated) --}}
                <div class="confirmation-screen__icon w-24 h-24 mx-auto mb-6 relative">
                    <div class="absolute inset-0 bg-green-100 rounded-full animate-pulse-slow"></div>
                    <div class="absolute inset-0 flex items-center justify-center">
                        <svg class="w-16 h-16 text-green-600 animate-check-draw" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                        </svg>
                    </div>
                </div>

                <h1 class="confirmation-screen__title text-4xl sm:text-5xl font-bold text-gray-900 mb-3">
                    Rezerwacja Potwierdzona!
                </h1>
                <p class="confirmation-screen__subtitle text-lg text-gray-600">
                    Email z potwierdzeniem został wysłany na <strong>{{ $appointment->email }}</strong>
                </p>
            </div>

            {{-- Appointment Details Card --}}
            <div class="confirmation-screen__details bg-white rounded-2xl p-6 shadow-lg border border-gray-200 mb-6 animate-slide-up">
                <h2 class="text-xl font-bold text-gray-900 mb-6">Szczegóły Rezerwacji</h2>

                <div class="space-y-6">
                    {{-- Date & Time --}}
                    <div class="confirmation-screen__detail-row flex items-start gap-4 pb-6 border-b border-gray-200">
                        <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-blue-500 to-blue-600 flex items-center justify-center flex-shrink-0">
                            <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                            </svg>
                        </div>
                        <div class="flex-1">
                            <div class="text-sm text-gray-600 font-medium mb-1">Kiedy</div>
                            <div class="text-xl font-bold text-gray-900">
                                {{ \Carbon\Carbon::parse($appointment->appointment_date)->locale('pl')->isoFormat('dddd, D MMMM YYYY') }}
                            </div>
                            <div class="text-lg text-gray-700 mt-1">
                                {{ \Carbon\Carbon::parse($appointment->start_time)->format('H:i') }}
                            </div>
                        </div>
                    </div>

                    {{-- Service --}}
                    <div class="confirmation-screen__detail-row flex items-start gap-4 pb-6 border-b border-gray-200">
                        <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-orange-500 to-orange-600 flex items-center justify-center flex-shrink-0">
                            <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                            </svg>
                        </div>
                        <div class="flex-1">
                            <div class="text-sm text-gray-600 font-medium mb-1">Usługa</div>
                            <div class="text-lg font-bold text-gray-900">{{ $appointment->service->name }}</div>
                            <div class="text-sm text-gray-600 mt-1">
                                Czas trwania: {{ $appointment->service->duration_minutes }} minut
                            </div>
                        </div>
                    </div>

                    {{-- Location (only when mobile_service feature active and data exists) --}}
                    @if(($showMobileService ?? true) && $appointment->location_address)
                    <div class="confirmation-screen__detail-row flex items-start gap-4 pb-6 border-b border-gray-200">
                        <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-purple-500 to-purple-600 flex items-center justify-center flex-shrink-0">
                            <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" />
                            </svg>
                        </div>
                        <div class="flex-1">
                            <div class="text-sm text-gray-600 font-medium mb-1">Miejsce</div>
                            <div class="text-lg font-bold text-gray-900">{{ $appointment->location_address }}</div>
                            @if($appointment->service_location_type)
                                <div class="text-sm text-gray-600 mt-1">
                                    <span class="inline-flex items-center gap-1">
                                        <x-heroicon-m-building-office class="w-4 h-4" />
                                        {{ $appointment->service_location_type }}
                                    </span>
                                </div>
                            @endif
                            @if($appointment->location_latitude && $appointment->location_longitude)
                                <a
                                    href="https://www.google.com/maps/dir/?api=1&destination={{ $appointment->location_latitude }},{{ $appointment->location_longitude }}"
                                    target="_blank"
                                    class="inline-flex items-center gap-2 mt-2 text-sm font-medium text-blue-600 hover:text-blue-700"
                                >
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7" />
                                    </svg>
                                    Nawiguj w Google Maps
                                </a>
                            @endif
                        </div>
                    </div>
                    @endif

                    {{-- Vehicle (only when vehicles feature active and data exists) --}}
                    @if(($showVehicle ?? true) && ($appointment->vehicleType || $appointment->vehicle_custom_brand))
                    <div class="confirmation-screen__detail-row flex items-start gap-4 pb-6 border-b border-gray-200">
                        <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-indigo-500 to-indigo-600 flex items-center justify-center flex-shrink-0">
                            <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
                            </svg>
                        </div>
                        <div class="flex-1">
                            <div class="text-sm text-gray-600 font-medium mb-1">Pojazd</div>
                            <div class="text-lg font-bold text-gray-900">
                                @if($appointment->vehicle_custom_brand || $appointment->vehicle_custom_model)
                                    {{ $appointment->vehicle_custom_brand }} {{ $appointment->vehicle_custom_model }}
                                    @if($appointment->vehicle_year)
                                        ({{ $appointment->vehicle_year }})
                                    @endif
                                @elseif($appointment->vehicleType)
                                    {{ $appointment->vehicleType->name }}
                                @else
                                    Nie określono
                                @endif
                            </div>
                            <div class="text-sm text-gray-600 mt-1 space-y-1">
                                @if($appointment->vehicleType)
                                    <div>Typ: {{ $appointment->vehicleType->name }}</div>
                                @endif
                                @if($appointment->registration_number)
                                    <div class="inline-flex items-center gap-1">
                                        <span class="font-medium">Nr rej.:</span>
                                        <span class="font-mono bg-gray-100 px-2 py-0.5 rounded">{{ $appointment->registration_number }}</span>
                                    </div>
                                @endif
                            </div>
                        </div>
                    </div>
                    @endif

                    {{-- Contact --}}
                    <div class="confirmation-screen__detail-row flex items-start gap-4 {{ $appointment->invoice_requested ? 'pb-6 border-b border-gray-200' : '' }}">
                        <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-green-500 to-green-600 flex items-center justify-center flex-shrink-0">
                            <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                            </svg>
                        </div>
                        <div class="flex-1">
                            <div class="text-sm text-gray-600 font-medium mb-1">Twoje Dane</div>
                            <div class="text-lg font-bold text-gray-900">{{ $appointment->first_name }} {{ $appointment->last_name }}</div>
                            <div class="text-sm text-gray-600 mt-1">
                                {{ $appointment->phone }} · {{ $appointment->email }}
                            </div>
                        </div>
                    </div>

                    {{-- Invoice (if requested) --}}
                    @if($appointment->invoice_requested)
                    <div class="confirmation-screen__detail-row flex items-start gap-4">
                        <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-sky-500 to-sky-600 flex items-center justify-center flex-shrink-0">
                            <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                            </svg>
                        </div>
                        <div class="flex-1">
                            <div class="text-sm text-gray-600 font-medium mb-1">Dane do Faktury VAT</div>
                            <div class="text-lg font-bold text-gray-900">{{ $appointment->invoice_company_name }}</div>
                            <div class="text-sm text-gray-600 mt-1 space-y-0.5">
                                @if($appointment->invoice_nip)
                                    <div>NIP: <span class="font-mono">{{ $appointment->invoice_nip }}</span></div>
                                @endif
                                <div>
                                    {{ $appointment->invoice_street }} {{ $appointment->invoice_street_number }}
                                </div>
                                <div>
                                    {{ $appointment->invoice_postal_code }} {{ $appointment->invoice_city }}
                                </div>
                            </div>
                        </div>
                    </div>
                    @endif
                </div>
            </div>

            {{-- Add to Calendar --}}
            <div class="confirmation-screen__calendar bg-white rounded-2xl p-6 shadow-lg border border-gray-200 mb-6 animate-slide-up" style="animation-delay: 0.1s;">
                <h3 class="text-lg font-bold text-gray-900 mb-4 flex items-center gap-2">
                    <svg class="w-5 h-5 text-orange-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                    </svg>
                    Dodaj do Kalendarza
                </h3>

                <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                    {{-- Google Calendar --}}
                    <a
                        href="{{ $googleCalendarUrl }}"
                        target="_blank"
                        class="confirmation-screen__calendar-btn flex flex-col items-center justify-center gap-2 p-4 bg-gray-50 hover:bg-blue-50 hover:border-blue-500 border-2 border-gray-200 rounded-xl transition-all duration-200"
                    >
                        <svg class="w-8 h-8 text-blue-600" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M19 3h-1V1h-2v2H8V1H6v2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm0 16H5V8h14v11z"/>
                        </svg>
                        <span class="text-xs font-medium text-gray-900">Google</span>
                    </a>

                    {{-- Apple Calendar --}}
                    <a
                        href="{{ $appleCalendarUrl }}"
                        download="appointment.ics"
                        class="confirmation-screen__calendar-btn flex flex-col items-center justify-center gap-2 p-4 bg-gray-50 hover:bg-gray-100 hover:border-gray-500 border-2 border-gray-200 rounded-xl transition-all duration-200"
                    >
                        <svg class="w-8 h-8 text-gray-700" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M17.05 20.28c-.98.95-2.05.8-3.08.35-1.09-.46-2.09-.48-3.24 0-1.44.62-2.2.44-3.06-.35C2.79 15.25 3.51 7.59 9.05 7.31c1.35.07 2.29.74 3.08.8 1.18-.24 2.31-.93 3.57-.84 1.51.12 2.65.72 3.4 1.8-3.12 1.87-2.38 5.98.48 7.13-.57 1.5-1.31 2.99-2.54 4.09l.01-.01zM12.03 7.25c-.15-2.23 1.66-4.07 3.74-4.25.29 2.58-2.34 4.5-3.74 4.25z"/>
                        </svg>
                        <span class="text-xs font-medium text-gray-900">Apple</span>
                    </a>

                    {{-- Outlook --}}
                    <a
                        href="{{ $outlookCalendarUrl }}"
                        target="_blank"
                        class="confirmation-screen__calendar-btn flex flex-col items-center justify-center gap-2 p-4 bg-gray-50 hover:bg-blue-50 hover:border-blue-500 border-2 border-gray-200 rounded-xl transition-all duration-200"
                    >
                        <svg class="w-8 h-8 text-blue-600" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M7 2h10c1.1 0 2 .9 2 2v16c0 1.1-.9 2-2 2H7c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2zm5 2c-3.31 0-6 2.69-6 6s2.69 6 6 6 6-2.69 6-6-2.69-6-6-6z"/>
                        </svg>
                        <span class="text-xs font-medium text-gray-900">Outlook</span>
                    </a>

                    {{-- iCal Download --}}
                    <a
                        href="{{ route('booking.ical', $appointment) }}"
                        download="appointment.ics"
                        class="confirmation-screen__calendar-btn flex flex-col items-center justify-center gap-2 p-4 bg-gray-50 hover:bg-orange-50 hover:border-orange-500 border-2 border-gray-200 rounded-xl transition-all duration-200"
                    >
                        <svg class="w-8 h-8 text-orange-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                        </svg>
                        <span class="text-xs font-medium text-gray-900">Pobierz</span>
                    </a>
                </div>
            </div>

            {{-- Preparation Checklist — tenant-configured only, no hardcoded
                 fallback. A hardcoded checklist here would assert something
                 about how THIS tenant's service works (e.g. "make sure your
                 car is accessible") regardless of what tenant it actually
                 is; absent means "don't show a checklist", not "guess one".
                 See app/docs/features/tenant-branding.md. --}}
            @php
                $beforeVisitItems = app(\App\Support\Settings\SettingsManager::class)->get('booking_wizard.before_visit_items');
                if (!is_array($beforeVisitItems)) {
                    $beforeVisitItems = [];
                }
            @endphp

            @if(!empty($beforeVisitItems))
            <div class="confirmation-screen__checklist bg-blue-50 rounded-2xl p-6 border border-blue-200 mb-6 animate-slide-up" style="animation-delay: 0.2s;">
                <h3 class="text-lg font-bold text-gray-900 mb-4 flex items-center gap-2">
                    <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4" />
                    </svg>
                    Przed Wizytą
                </h3>

                <ul class="space-y-3">
                    @foreach($beforeVisitItems as $item)
                        <li class="flex items-start gap-3 text-gray-700">
                            <svg class="w-5 h-5 text-blue-600 flex-shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                            </svg>
                            <span>{{ $item }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
            @endif

            {{-- Action Buttons --}}
            <div class="confirmation-screen__actions grid grid-cols-1 gap-4 mb-8 animate-slide-up" style="animation-delay: 0.3s;">
                {{-- Primary Action: View My Appointments --}}
                <a
                    href="{{ route('appointments.index') }}"
                    class="confirmation-screen__btn confirmation-screen__btn--primary flex items-center justify-center gap-2 px-6 py-4 bg-orange-500 hover:bg-orange-600 text-white font-bold rounded-xl shadow-lg hover:shadow-xl transition-all duration-200 active:scale-98"
                >
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
                    </svg>
                    Zobacz Moje Wizyty
                </a>

                {{-- Secondary Actions Grid --}}
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <a
                        href="{{ route('services.index') }}"
                        class="confirmation-screen__btn confirmation-screen__btn--secondary flex items-center justify-center gap-2 px-6 py-3 bg-white hover:bg-gray-50 text-gray-900 font-medium border-2 border-gray-300 rounded-xl shadow-md hover:shadow-lg transition-all duration-200 active:scale-98"
                    >
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
                        </svg>
                        Przeglądaj Usługi
                    </a>

                    <a
                        href="{{ route('profile.index') }}"
                        class="confirmation-screen__btn confirmation-screen__btn--secondary flex items-center justify-center gap-2 px-6 py-3 bg-white hover:bg-gray-50 text-gray-900 font-medium border-2 border-gray-300 rounded-xl shadow-md hover:shadow-lg transition-all duration-200 active:scale-98"
                    >
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                        </svg>
                        Mój Profil
                    </a>
                </div>

            </div>

            {{-- Contact Us Section --}}
            @php
                $settings = app(\App\Support\Settings\SettingsManager::class);
                $contactPhone = $settings->get('contact.phone', '');
                $contactEmail = $settings->get('contact.email', config('mail.from.address', ''));
            @endphp

            <div class="confirmation-screen__contact bg-white rounded-2xl p-6 shadow-lg border border-gray-200 animate-slide-up" style="animation-delay: 0.4s;">
                <h3 class="text-lg font-bold text-gray-900 mb-4 flex items-center gap-2">
                    <svg class="w-5 h-5 text-primary-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
                    </svg>
                    Masz pytania?
                </h3>

                <p class="text-gray-600 mb-6">
                    Skontaktuj się z nami - chętnie odpowiemy na wszystkie pytania dotyczące rezerwacji.
                </p>

                <div class="flex flex-col sm:flex-row gap-3">
                    @if($contactPhone)
                        <a href="tel:{{ preg_replace('/[^0-9+]/', '', $contactPhone) }}"
                           class="flex-1 flex items-center justify-center gap-3 px-6 py-4 bg-orange-500 hover:bg-orange-600 text-white font-bold rounded-xl shadow-lg hover:shadow-xl transition-all duration-200 active:scale-98">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
                            </svg>
                            <span class="flex flex-col items-start sm:items-center">
                                <span class="text-xs text-orange-200 font-normal">Zadzwoń teraz</span>
                                <span>{{ $contactPhone }}</span>
                            </span>
                        </a>
                    @endif

                    @if($contactEmail)
                        <a href="mailto:{{ $contactEmail }}"
                           class="flex-1 flex items-center justify-center gap-3 px-6 py-4 bg-white hover:bg-gray-50 text-gray-900 font-medium border-2 border-gray-300 rounded-xl shadow-md hover:shadow-lg transition-all duration-200 active:scale-98">
                            <svg class="w-5 h-5 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                            </svg>
                            <span class="flex flex-col items-start sm:items-center">
                                <span class="text-xs text-gray-500 font-normal">Napisz do nas</span>
                                <span class="text-sm truncate max-w-[180px]">{{ $contactEmail }}</span>
                            </span>
                        </a>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <style>
        /* Animations */
        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes pulseShow {
            0%, 100% {
                opacity: 1;
            }
            50% {
                opacity: 0.7;
            }
        }

        @keyframes checkDraw {
            0% {
                stroke-dasharray: 0 50;
                stroke-dashoffset: 0;
            }
            100% {
                stroke-dasharray: 50 0;
                stroke-dashoffset: 0;
            }
        }

        .animate-fade-in {
            animation: fadeIn 0.6s ease-out;
        }

        .animate-slide-up {
            animation: slideUp 0.6s ease-out;
        }

        .animate-pulse-slow {
            animation: pulseShow 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
        }

        .animate-check-draw {
            stroke-dasharray: 50;
            animation: checkDraw 0.5s ease-out forwards;
        }

        /* Button active state */
        .active\:scale-98:active {
            transform: scale(0.98);
        }

        /* Calendar button hover effects */
        .confirmation-screen__calendar-btn {
            transition: all 0.2s cubic-bezier(0.68, -0.55, 0.265, 1.55);
        }

        .confirmation-screen__calendar-btn:hover {
            transform: translateY(-4px);
        }

        .confirmation-screen__calendar-btn:active {
            transform: translateY(-2px);
        }

        /* Button hover effects */
        .confirmation-screen__btn {
            transition: all 0.3s cubic-bezier(0.68, -0.55, 0.265, 1.55);
        }

        .confirmation-screen__btn:hover {
            transform: translateY(-2px);
        }
    </style>
</body>
</html>

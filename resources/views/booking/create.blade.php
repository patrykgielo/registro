@extends('layouts.app')

@section('content')
@php($marketing = $marketingContent ?? app(\App\Support\Settings\SettingsManager::class)->marketingContent())
<div class="container-custom max-w-6xl">
    <!-- Multi-Step Booking Wizard - Pure Vanilla JavaScript -->
    <div data-wizard
         data-map-id="{{ $mapConfig['map_id'] ?? config('services.google_maps.map_id') }}"
         data-map-lat="{{ $mapConfig['default_latitude'] ?? 0 }}"
         data-map-lng="{{ $mapConfig['default_longitude'] ?? 0 }}"
         data-map-zoom="{{ $mapConfig['default_zoom'] ?? 15 }}"
         data-map-country="{{ $mapConfig['country_code'] ?? 'pl' }}"
         data-map-debug="{{ ($mapConfig['debug_panel_enabled'] ?? false) ? 'true' : 'false' }}"
         data-advance-hours="{{ $bookingConfig['advance_booking_hours'] ?? 24 }}"
         data-business-hours-start="{{ $bookingConfig['business_hours_start'] ?? '09:00' }}"
         data-business-hours-end="{{ $bookingConfig['business_hours_end'] ?? '18:00' }}"
         data-slot-interval="{{ $bookingConfig['slot_interval_minutes'] ?? 15 }}"
         data-cancellation-hours="{{ $bookingConfig['cancellation_hours'] ?? 24 }}"
         data-service='{{ json_encode([
             'id' => $service->id,
             'name' => $service->name,
             'description' => $service->description,
             'duration_minutes' => $service->duration_minutes,
             'price' => (float) $service->price
         ]) }}'
         @auth
         data-customer='{{ json_encode([
             'first_name' => $user->first_name ?? '',
             'last_name' => $user->last_name ?? '',
             'phone_e164' => $user->phone_e164 ?? '',
             'street_name' => $user->street_name ?? '',
             'street_number' => $user->street_number ?? '',
             'city' => $user->city ?? '',
             'postal_code' => $user->postal_code ?? '',
             'access_notes' => $user->access_notes ?? ''
         ]) }}'
         @if($savedVehicle)
         data-saved-vehicle='{{ json_encode([
             'vehicle_type_id' => $savedVehicle->vehicle_type_id,
             'car_brand_id' => $savedVehicle->car_brand_id,
             'car_model_id' => $savedVehicle->car_model_id,
             'custom_brand' => $savedVehicle->custom_brand,
             'custom_model' => $savedVehicle->custom_model,
             'year' => $savedVehicle->year,
             'brand_name' => $savedVehicle->brand_name,
             'model_name' => $savedVehicle->model_name,
         ]) }}'
         @endif
         @if($savedAddress)
         data-saved-address='{{ json_encode([
             'address' => $savedAddress->address,
             'latitude' => $savedAddress->latitude,
             'longitude' => $savedAddress->longitude,
             'place_id' => $savedAddress->place_id,
             'address_components' => $savedAddress->address_components,
             'nickname' => $savedAddress->nickname,
         ]) }}'
         @endif
         @endauth
         class="mb-12">

        <!-- Progress Header -->
        <div class="bg-white rounded-lg shadow-lg p-6 md:p-8 mb-8">
            <h1 class="text-2xl md:text-3xl font-bold text-gray-900 mb-6 text-center">
                Rezerwacja: <span class="text-primary-600" data-service-name>{{ $service->name }}</span>
            </h1>

            <!-- Progress Bar -->
            <div class="progress-bar mb-6">
                <div class="progress-bar-fill" style="width: 25%"></div>
            </div>

            <!-- Step Indicators -->
            <nav aria-label="Kroki rezerwacji" class="flex justify-between items-center max-w-3xl mx-auto">
                @foreach(['Usługa', 'Termin', 'Dane', 'Podsumowanie'] as $index => $stepName)
                    <button data-go-to-step="{{ $index + 1 }}"
                            class="flex flex-col items-center flex-1"
                            aria-label="Krok {{ $index + 1 }}: {{ $stepName }}"
                            type="button">
                        <div class="step-indicator {{ $index === 0 ? 'step-indicator-active' : '' }}">
                            <span>{{ $index + 1 }}</span>
                        </div>
                        <span class="mt-2 text-xs md:text-sm font-medium text-gray-600 hidden sm:block" data-step-label>{{ $stepName }}</span>
                    </button>
                @endforeach
            </nav>
        </div>

        <!-- Main Content Area -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
            <!-- Step Content -->
            <div class="lg:col-span-2">
                <div class="bg-white rounded-lg shadow-lg p-6 md:p-8">

                    <!-- Step 1: Service Confirmation -->
                    <div data-step="1" style="display: block;">
                        <h2 class="text-2xl font-bold text-gray-900 mb-6">Potwierdź Wybraną Usługę</h2>

                        <div class="service-card service-card-selected">
                            <div class="p-6">
                                <h3 class="text-xl font-bold text-gray-900 mb-3" data-service-name>{{ $service->name }}</h3>
                                <p class="text-gray-600 mb-4">{{ $service->description ?? 'Profesjonalna usługa detailingowa' }}</p>

                                <div class="flex items-center justify-between text-gray-700">
                                    <div class="flex items-center">
                                        <svg class="w-5 h-5 mr-2 text-primary-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                        </svg>
                                        <span class="font-medium" data-service-duration>{{ $service->duration_minutes }} min</span>
                                    </div>
                                    <div class="text-2xl font-bold text-primary-600" data-service-price>{{ (int) $service->price }} zł</div>
                                </div>
                            </div>
                        </div>

                        <button data-next-step class="btn btn-primary w-full mt-6">
                            Przejdź Do Wyboru Terminu
                            <svg class="w-5 h-5 ml-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"/>
                            </svg>
                        </button>
                    </div>

                    <!-- Step 2: Date & Time Selection -->
                    <div data-step="2" style="display: none;">
                        <h2 class="text-2xl font-bold text-gray-900 mb-6">Wybierz Termin Wizyty</h2>

                        <!-- Date Availability Warning -->
                        <div class="alert alert-warning mb-6">
                            <div class="flex items-start">
                                <svg class="w-6 h-6 mr-3 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-12a1 1 0 10-2 0v4a1 1 0 00.293.707l2.828 2.829a1 1 0 101.415-1.415L11 9.586V6z" clip-rule="evenodd"/>
                                </svg>
                                <div>
                                    <p class="font-bold">Minimalne wyprzedzenie: {{ $bookingConfig['advance_booking_hours'] ?? 24 }} godzin</p>
                                    <p class="mt-1">Rezerwacje można składać z co najmniej {{ $bookingConfig['advance_booking_hours'] ?? 24 }}-godzinnym wyprzedzeniem. Najbliższy dostępny termin: <span id="earliest-date" class="font-semibold"></span></p>
                                </div>
                            </div>
                        </div>

                        <!-- Info Box: Automatic Staff Assignment -->
                        <div class="alert alert-info mb-6">
                            <div class="flex items-start">
                                <svg class="w-6 h-6 mr-3 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/></svg>
                                <div>
                                    <p class="font-bold">Automatyczne przypisanie specjalisty</p>
                                    <p class="mt-1">Nasz system automatycznie przypisze dostępnego specjalistę do wybranego terminu.</p>
                                </div>
                            </div>
                        </div>

                        <!-- Date Selection -->
                        <div class="mb-6">
                            <label for="date-input" class="form-label">
                                Wybierz Datę
                                <span class="text-red-500">*</span>
                            </label>
                            <input type="date"
                                   id="date-input"
                                   class="form-input"
                                   required
                                   aria-required="true"
                                   aria-describedby="date-error date-help">
                            <p class="form-help" id="date-help">
                                Minimalna rezerwacja: {{ $bookingConfig['advance_booking_hours'] ?? 24 }} godzin przed wizytą
                            </p>
                            <p class="form-error" id="date-error" style="display: none;"></p>
                        </div>

                        <!-- Available Time Slots -->
                        <div id="time-slots-section" style="display: none;">
                            <label class="form-label">
                                Dostępne Godziny
                                <span class="text-red-500">*</span>
                            </label>

                            <!-- Loading State -->
                            <div id="time-slots-loading" class="flex flex-col items-center justify-center py-12" style="display: none;">
                                <div class="spinner"></div>
                                <p class="mt-4 text-gray-600">Ładowanie dostępnych terminów...</p>
                            </div>

                            <!-- Time Slots Grid -->
                            <div id="time-slots-container"
                                 class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-3"
                                 role="radiogroup"
                                 aria-label="Dostępne godziny"
                                 style="display: none;">
                                <!-- Slots will be dynamically inserted by JavaScript -->
                            </div>

                            <!-- No Slots Available -->
                            <div id="time-slots-empty" class="alert alert-warning" style="display: none;">
                                <p>Brak dostępnych terminów w tym dniu. Wybierz inną datę.</p>
                            </div>

                            <!-- Error Message -->
                            <div id="time-slots-error" class="alert alert-error mt-4" style="display: none;">
                                <p></p>
                            </div>

                            <p class="form-error mt-2" id="time-slot-error" style="display: none;"></p>
                        </div>

                        <!-- Navigation Buttons -->
                        <div class="flex gap-4 mt-8">
                            <button data-prev-step type="button" class="btn btn-ghost flex-1">
                                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 17l-5-5m0 0l5-5m-5 5h12"/>
                                </svg>
                                Wstecz
                            </button>
                            <button data-next-step
                                    type="button"
                                    id="step2-next-btn"
                                    class="btn btn-primary flex-1"
                                    disabled>
                                Dalej
                                <svg class="w-5 h-5 ml-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"/>
                                </svg>
                            </button>
                        </div>
                    </div>

                    <!-- Step 3: Customer Details -->
                    <div data-step="3" style="display: none;">
                        <h2 class="text-2xl font-bold text-gray-900 mb-6">Twoje Dane Kontaktowe</h2>

                        <!-- Vehicle Selection Section -->
                        <div class="mb-6">
                            <h3 class="text-lg font-semibold text-gray-900 mb-4">Informacje o Pojeździe</h3>

                            <!-- 1. Vehicle Type Selection (Cards with Icons) -->
                            <div class="mb-4">
                                <label class="form-label">
                                    Typ pojazdu
                                    <span class="text-red-500">*</span>
                                </label>
                                <div id="vehicle-types-container" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3 mt-2">
                                    <!-- Vehicle type cards will be inserted by JavaScript -->
                                </div>
                                <p class="form-error mt-2" id="vehicle-type-error" style="display: none;"></p>
                            </div>

                            <!-- 2. Vehicle Details (shown after type selection) -->
                            <div id="vehicle-details-section" style="display: none;">
                                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                    <!-- Brand Select -->
                                    <div>
                                        <label for="car_brand" class="form-label">
                                            Marka
                                            <span class="text-red-500">*</span>
                                        </label>
                                        <select id="car_brand" class="form-input">
                                            <option value="">Wybierz markę</option>
                                        </select>
                                        <p class="form-error mt-1" id="brand-error" style="display: none;"></p>
                                    </div>

                                    <!-- Model Select -->
                                    <div>
                                        <label for="car_model" class="form-label">
                                            Model
                                            <span class="text-red-500">*</span>
                                        </label>
                                        <select id="car_model" class="form-input" disabled>
                                            <option value="">Najpierw wybierz markę</option>
                                        </select>
                                        <p class="form-error mt-1" id="model-error" style="display: none;"></p>
                                    </div>

                                    <!-- Year Select -->
                                    <div>
                                        <label for="vehicle_year" class="form-label">
                                            Rocznik
                                            <span class="text-red-500">*</span>
                                        </label>
                                        <select id="vehicle_year" class="form-input">
                                            <option value="">Wybierz rok</option>
                                        </select>
                                        <p class="form-error mt-1" id="year-error" style="display: none;"></p>
                                    </div>
                                </div>

                                <p class="form-help text-sm text-gray-600 mt-3">
                                    <svg class="w-4 h-4 inline mr-1" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/>
                                    </svg>
                                    Jeśli nie znajdziesz swojej marki/modelu, wybierz najbliższy odpowiednik lub skontaktuj się z nami.
                                </p>
                            </div>
                        </div>

                        <!-- Personal Data Section -->
                        <div class="mb-6">
                            <h3 class="text-lg font-semibold text-gray-900 mb-4">Dane Osobowe</h3>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label for="first_name" class="form-label">
                                        Imię
                                        <span class="text-red-500">*</span>
                                    </label>
                                    <input type="text"
                                           id="first_name"
                                           class="form-input"
                                           placeholder="Jan"
                                           required
                                           maxlength="255">
                                    <p class="form-error" id="first-name-error" style="display: none;"></p>
                                </div>
                                <div>
                                    <label for="last_name" class="form-label">
                                        Nazwisko
                                        <span class="text-red-500">*</span>
                                    </label>
                                    <input type="text"
                                           id="last_name"
                                           class="form-input"
                                           placeholder="Kowalski"
                                           required
                                           maxlength="255">
                                    <p class="form-error" id="last-name-error" style="display: none;"></p>
                                </div>
                            </div>
                        </div>

                        <!-- Contact Section -->
                        <div class="mb-6">
                            <h3 class="text-lg font-semibold text-gray-900 mb-4">Kontakt</h3>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label for="phone_e164" class="form-label">
                                        Telefon
                                        <span class="text-red-500">*</span>
                                    </label>
                                    <input type="tel"
                                           id="phone_e164"
                                           class="form-input"
                                           placeholder="+48501234567"
                                           required
                                           maxlength="20">
                                    <p class="form-help">Format międzynarodowy, np. +48501234567</p>
                                    <p class="form-error" id="phone-error" style="display: none;"></p>
                                </div>
                            </div>
                        </div>

                        <!-- Google Maps Location Section -->
                        <div class="mb-6">
                            <h3 class="text-lg font-semibold text-gray-900 mb-4">Lokalizacja Usługi</h3>
                            <div>
                                <label for="place-autocomplete" class="form-label">
                                    Wyszukaj adres
                                    <span class="text-red-500">*</span>
                                </label>
                                <input
                                    type="text"
                                    id="place-autocomplete"
                                    class="form-input"
                                    placeholder="Zacznij wpisywać adres..."
                                    autocomplete="off">
                                <p class="form-help" id="location-help">
                                    Użyj autouzupełniania Google Maps aby wybrać dokładny adres. System automatycznie zapisze współrzędne lokalizacji.
                                </p>
                                <p class="form-error" id="location-error" style="display: none;"></p>
                            </div>

                            <!-- Map always visible on step 3 -->
                            <div class="mt-6 space-y-4">

                                <!-- Selected address info - only show when address selected -->
                                <div id="selected-address-info"
                                     class="p-4 bg-primary-50 border border-primary-200 rounded-lg"
                                     style="display: none;">
                                    <div class="flex items-start">
                                        <svg class="w-5 h-5 text-primary-600 mr-3 flex-shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                                            <path fill-rule="evenodd" d="M5.05 4.05a7 7 0 119.9 9.9L10 18.9l-4.95-4.95a7 7 0 010-9.9zM10 11a2 2 0 100-4 2 2 0 000 4z" clip-rule="evenodd"/>
                                        </svg>
                                        <div class="flex-1">
                                            <p class="font-semibold text-gray-900">Wybrana lokalizacja:</p>
                                            <p class="text-gray-700 mt-1" id="selected-address-text">-</p>
                                        </div>
                                    </div>
                                </div>

                                <!-- Google Map with Loading Indicator -->
                                <div class="map-container relative">
                                    <div id="location-map" class="w-full h-96 rounded-lg shadow-md border border-gray-200" style="min-height: 384px;"></div>

                                    <!-- Loading Overlay -->
                                    <div id="map-loading-overlay"
                                         class="absolute inset-0 flex items-center justify-center bg-gray-50 bg-opacity-95 rounded-lg"
                                         style="z-index: 10; display: none;">
                                        <div class="text-center">
                                            <svg class="animate-spin h-10 w-10 text-primary-600 mx-auto mb-3" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                            </svg>
                                            <p class="text-sm font-medium text-gray-700">Ładowanie mapy...</p>
                                            <p class="text-xs text-gray-500 mt-1">Proszę czekać</p>
                                        </div>
                                    </div>
                                </div>

                                @if($mapConfig['debug_panel_enabled'] ?? false)
                                    <!-- Debug Info Panel (remove in production) -->
                                    <div class="text-xs bg-gray-50 border border-gray-200 rounded-lg p-3 space-y-1">
                                        <p class="font-semibold text-gray-700 mb-2">🔧 Informacje debugowania:</p>
                                        <div class="grid grid-cols-2 gap-2">
                                            <div>
                                                <span class="text-gray-500">Adres:</span>
                                                <span class="font-medium text-gray-900 ml-1" id="debug-address">-</span>
                                            </div>
                                            <div>
                                                <span class="text-gray-500">Place ID:</span>
                                                <span class="font-mono text-gray-900 ml-1 text-xs" id="debug-place-id">-</span>
                                            </div>
                                            <div>
                                                <span class="text-gray-500">Szerokość:</span>
                                                <span class="font-mono text-gray-900 ml-1" id="debug-latitude">-</span>
                                            </div>
                                            <div>
                                                <span class="text-gray-500">Długość:</span>
                                                <span class="font-mono text-gray-900 ml-1" id="debug-longitude">-</span>
                                            </div>
                                            <div>
                                                <span class="text-gray-500">Mapa init:</span>
                                                <span class="font-medium ml-1" id="debug-map-init">✗ NIE</span>
                                            </div>
                                            <div>
                                                <span class="text-gray-500">Marker:</span>
                                                <span class="font-medium ml-1 text-gray-400" id="debug-marker">✗ NIE</span>
                                            </div>
                                        </div>
                                        <p class="text-xs text-gray-400 mt-2 pt-2 border-t border-gray-200">
                                            💡 Sprawdź konsolę przeglądarki (F12) aby zobaczyć szczegółowe logi
                                        </p>
                                    </div>
                                @endif
                            </div>
                        </div>

                        <!-- Address Section -->
                        <div class="mb-6">
                            <h3 class="text-lg font-semibold text-gray-900 mb-4">Adres (wypełniane automatycznie)</h3>
                            <p class="text-sm text-gray-600 mb-4">Pola poniżej są wypełniane automatycznie na podstawie wybranej lokalizacji. Możesz je edytować jeśli potrzebujesz.</p>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label for="street_name" class="form-label">Ulica</label>
                                    <input type="text"
                                           id="street_name"
                                           class="form-input"
                                           placeholder="Marszałkowska"
                                           maxlength="255">
                                </div>
                                <div>
                                    <label for="street_number" class="form-label">Numer</label>
                                    <input type="text"
                                           id="street_number"
                                           class="form-input"
                                           placeholder="12/34"
                                           maxlength="20">
                                </div>
                                <div>
                                    <label for="city" class="form-label">Miasto</label>
                                    <input type="text"
                                           id="city"
                                           class="form-input"
                                           placeholder="Warszawa"
                                           maxlength="255">
                                </div>
                                <div>
                                    <label for="postal_code" class="form-label">Kod pocztowy</label>
                                    <input type="text"
                                           id="postal_code"
                                           class="form-input"
                                           placeholder="00-000"
                                           maxlength="10">
                                    <p class="form-error" id="postal-code-error" style="display: none;"></p>
                                </div>
                            </div>
                            <div class="mt-4">
                                <label for="access_notes" class="form-label">Informacje o dostępie</label>
                                <textarea id="access_notes"
                                          rows="3"
                                          class="form-input"
                                          maxlength="1000"
                                          placeholder="Dodatkowe informacje o adresie, np. kod do bramy, piętro..."></textarea>
                            </div>
                        </div>

                        <!-- Additional Notes Section -->
                        <div class="mb-6">
                            <h3 class="text-lg font-semibold text-gray-900 mb-4">Dodatkowe Uwagi</h3>
                            <div>
                                <label for="notes-input" class="form-label">Uwagi do wizyty (opcjonalnie)</label>
                                <textarea id="notes-input"
                                          rows="4"
                                          class="form-input"
                                          maxlength="1000"
                                          placeholder="Wpisz dodatkowe informacje, np. specjalne życzenia, informacje o pojeździe..."
                                          aria-describedby="notes-help"></textarea>
                                <p class="form-help" id="notes-help">
                                    Możesz dodać informacje, które pomogą nam lepiej przygotować się do usługi.
                                </p>
                            </div>
                        </div>

                        <!-- Important Information Box -->
                        <div class="alert alert-info">
                            <div class="flex items-start">
                                <svg class="w-6 h-6 mr-3 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/></svg>
                                <div>
                                    <p class="font-bold mb-1">{{ $marketing['important_info_heading'] ?? 'Ważne Informacje' }}</p>
                                    <ul class="list-disc list-inside space-y-1 text-sm">
                                        @foreach(($marketing['important_info_points'] ?? []) as $point)
                                            @php($infoText = str_contains($point, ':hours') ? str_replace(':hours', $bookingConfig['cancellation_hours'] ?? 24, $point) : $point)
                                            <li>{{ $infoText }}</li>
                                        @endforeach
                                    </ul>
                                </div>
                            </div>
                        </div>

                        <!-- Navigation Buttons -->
                        <div class="flex gap-4 mt-8">
                            <button data-prev-step type="button" class="btn btn-ghost flex-1">
                                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 17l-5-5m0 0l5-5m-5 5h12"/>
                                </svg>
                                Wstecz
                            </button>
                            <button data-next-step type="button" id="step3-next-btn" class="btn btn-primary flex-1 disabled:opacity-50 disabled:cursor-not-allowed">
                                Podsumowanie
                                <svg class="w-5 h-5 ml-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                                </svg>
                            </button>
                        </div>
                    </div>

                    <!-- Step 4: Summary & Confirmation -->
                    <div data-step="4" style="display: none;">
                        <h2 class="text-2xl font-bold text-gray-900 mb-6">Podsumowanie Rezerwacji</h2>

                        <div class="space-y-6">
                            <!-- Service Summary -->
                            <div class="border-2 border-primary-100 rounded-lg p-4 bg-primary-50/50">
                                <h3 class="font-bold text-gray-900 mb-2">Usługa</h3>
                                <p class="text-lg text-gray-700" id="summary-service-name">-</p>
                            </div>

                            <!-- Appointment Details -->
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div class="border-2 border-gray-200 rounded-lg p-4">
                                    <h3 class="font-bold text-gray-900 mb-2">Data</h3>
                                    <p class="text-gray-700" id="summary-date">-</p>
                                </div>
                                <div class="border-2 border-gray-200 rounded-lg p-4">
                                    <h3 class="font-bold text-gray-900 mb-2">Godzina</h3>
                                    <p class="text-gray-700" id="summary-time">-</p>
                                </div>
                                <div class="border-2 border-gray-200 rounded-lg p-4 md:col-span-2">
                                    <h3 class="font-bold text-gray-900 mb-2">Czas trwania</h3>
                                    <p class="text-gray-700" id="summary-duration">-</p>
                                </div>
                            </div>

                            <!-- Customer Details Summary -->
                            <div class="border-2 border-gray-200 rounded-lg p-4">
                                <h3 class="font-bold text-gray-900 mb-3">Twoje Dane</h3>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-3 text-sm">
                                    <div>
                                        <span class="text-gray-500">Imię i nazwisko:</span>
                                        <span class="font-medium ml-2" id="summary-customer-name">-</span>
                                    </div>
                                    <div>
                                        <span class="text-gray-500">Telefon:</span>
                                        <span class="font-medium ml-2" id="summary-customer-phone">-</span>
                                    </div>
                                    <div id="summary-customer-address-row" class="md:col-span-2" style="display: none;">
                                        <span class="text-gray-500">Adres:</span>
                                        <span class="font-medium ml-2" id="summary-customer-address">-</span>
                                    </div>
                                </div>
                            </div>

                            <!-- Vehicle Summary -->
                            <div class="border-2 border-gray-200 rounded-lg p-4">
                                <h3 class="font-bold text-gray-900 mb-3">Pojazd</h3>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-3 text-sm">
                                    <div>
                                        <span class="text-gray-500">Typ:</span>
                                        <span class="font-medium ml-2" id="summary-vehicle-type">-</span>
                                    </div>
                                    <div>
                                        <span class="text-gray-500">Marka:</span>
                                        <span class="font-medium ml-2" id="summary-vehicle-brand">-</span>
                                    </div>
                                    <div>
                                        <span class="text-gray-500">Model:</span>
                                        <span class="font-medium ml-2" id="summary-vehicle-model">-</span>
                                    </div>
                                    <div>
                                        <span class="text-gray-500">Rocznik:</span>
                                        <span class="font-medium ml-2" id="summary-vehicle-year">-</span>
                                    </div>
                                </div>
                            </div>

                            <!-- Notes Summary -->
                            <div id="summary-notes-section" class="border-2 border-gray-200 rounded-lg p-4" style="display: none;">
                                <h3 class="font-bold text-gray-900 mb-2">Uwagi</h3>
                                <p class="text-gray-700" id="summary-notes">-</p>
                            </div>

                            <!-- Price Summary -->
                            <div class="bg-primary-50 rounded-lg p-6 border-2 border-primary-200">
                                <div class="flex items-center justify-between">
                                    <span class="text-lg font-semibold text-gray-900">Całkowity Koszt:</span>
                                    <span class="text-3xl font-bold text-primary-600" id="summary-price">0 zł</span>
                                </div>
                            </div>
                        </div>

                        <!-- Final Form Submission -->
                        <form method="POST" action="{{ route('appointments.store') }}" class="mt-8" id="booking-form">
                            @csrf
                            <input type="hidden" name="service_id" id="form-service-id">
                            <!-- staff_id is auto-assigned by backend -->
                            <input type="hidden" name="appointment_date" id="form-appointment-date">
                            <input type="hidden" name="start_time" id="form-start-time">
                            <input type="hidden" name="end_time" id="form-end-time">
                            <input type="hidden" name="notes" id="form-notes">
                            <!-- New profile fields -->
                            <input type="hidden" name="first_name" id="form-first-name">
                            <input type="hidden" name="last_name" id="form-last-name">
                            <input type="hidden" name="phone_e164" id="form-phone-e164">
                            <!-- Google Maps location data -->
                            <input type="hidden" name="location_address" id="form-location-address">
                            <input type="hidden" name="location_latitude" id="form-location-latitude">
                            <input type="hidden" name="location_longitude" id="form-location-longitude">
                            <input type="hidden" name="location_place_id" id="form-location-place-id">
                            <input type="hidden" name="location_components" id="form-location-components">
                            <!-- Legacy address fields -->
                            <input type="hidden" name="street_name" id="form-street-name">
                            <input type="hidden" name="street_number" id="form-street-number">
                            <input type="hidden" name="city" id="form-city">
                            <input type="hidden" name="postal_code" id="form-postal-code">
                            <input type="hidden" name="access_notes" id="form-access-notes">
                            <!-- Vehicle fields -->
                            <input type="hidden" name="vehicle_type_id" id="form-vehicle-type-id">
                            <input type="hidden" name="car_brand_id" id="form-car-brand-id">
                            <input type="hidden" name="car_brand_name" id="form-car-brand-name">
                            <input type="hidden" name="car_model_id" id="form-car-model-id">
                            <input type="hidden" name="car_model_name" id="form-car-model-name">
                            <input type="hidden" name="vehicle_year" id="form-vehicle-year">

                            <!-- Navigation Buttons -->
                            <div class="flex gap-4">
                                <button data-prev-step type="button" class="btn btn-ghost flex-1">
                                    <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 17l-5-5m0 0l5-5m-5 5h12"/>
                                    </svg>
                                    Wstecz
                                </button>
                                <button type="submit" class="btn btn-primary flex-1 text-lg">
                                    <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                                    </svg>
                                    Potwierdź Rezerwację
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Sidebar Summary (Sticky) -->
            <div class="lg:col-span-1">
                <div class="bg-white rounded-lg shadow-lg p-6 sticky top-8">
                    <h3 class="text-lg font-bold text-gray-900 mb-4">Twoja Rezerwacja</h3>

                    <div class="space-y-4">
                        <!-- Service Info -->
                        <div class="pb-4 border-b border-gray-200">
                            <p class="text-sm text-gray-500 mb-1">Usługa</p>
                            <p class="font-semibold text-gray-900" id="sidebar-service-name">{{ $service->name }}</p>
                        </div>

                        <!-- Selected Details -->
                        <div class="space-y-3 text-sm">
                            <div class="flex items-start">
                                <svg class="w-5 h-5 text-gray-400 mr-3 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                                </svg>
                                <div>
                                    <p class="text-gray-500">Data</p>
                                    <p class="font-medium text-gray-900" id="sidebar-date">Nie wybrano</p>
                                </div>
                            </div>

                            <div class="flex items-start">
                                <svg class="w-5 h-5 text-gray-400 mr-3 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                </svg>
                                <div>
                                    <p class="text-gray-500">Godzina</p>
                                    <p class="font-medium text-gray-900" id="sidebar-time">Nie wybrano</p>
                                </div>
                            </div>
                        </div>

                        <!-- Price -->
                        <div class="pt-4 border-t border-gray-200">
                            <div class="flex items-center justify-between">
                                <span class="font-semibold text-gray-900">Cena:</span>
                                <span class="text-2xl font-bold text-primary-600" id="sidebar-price">{{ (int) $service->price }} zł</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    /* Vehicle Type Cards */
    .vehicle-type-card {
        cursor: pointer;
        padding: 1rem;
        border: 2px solid #e5e7eb;
        border-radius: 0.75rem;
        background: white;
        transition: all 0.2s ease;
        display: flex;
        flex-direction: column;
        align-items: center;
        text-align: center;
        min-height: 120px;
    }

    .vehicle-type-card:hover {
        border-color: #3b82f6;
        background: #eff6ff;
        transform: translateY(-2px);
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
    }

    .vehicle-type-card.selected {
        border-color: #2563eb;
        background: #dbeafe;
        box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
    }

    .vehicle-type-card .icon {
        width: 40px;
        height: 40px;
        margin-bottom: 0.5rem;
        color: #6b7280;
    }

    .vehicle-type-card.selected .icon {
        color: #2563eb;
    }

    .vehicle-type-card .name {
        font-weight: 600;
        color: #1f2937;
        font-size: 0.875rem;
    }

    /* Google Map Container */
    #location-map {
        min-height: 384px; /* h-96 = 24rem = 384px */
    }

    .map-container {
        position: relative;
        overflow: hidden;
        border-radius: 0.5rem;
    }

    /* Responsive map height */
    @media (max-width: 768px) {
        #location-map {
            min-height: 300px;
        }
    }

    /* Smooth transitions for map display */
    .map-container {
        transition: all 0.3s ease-in-out;
    }
</style>

<script>
    // Calculate and display earliest available date
    document.addEventListener('DOMContentLoaded', function() {
        const wizard = document.querySelector('[data-wizard]');
        const advanceHours = wizard ? parseInt(wizard.dataset.advanceHours || '24', 10) : 24;

        const minDateTime = new Date();
        minDateTime.setHours(minDateTime.getHours() + advanceHours);
        minDateTime.setMinutes(0, 0, 0);

        const formatted = minDateTime.toLocaleDateString('pl-PL', {
            weekday: 'long',
            year: 'numeric',
            month: 'long',
            day: 'numeric'
        });

        const el = document.getElementById('earliest-date');
        if (el) el.textContent = formatted;
    });

    // Global initialization callback for Google Maps API
    window.initGoogleMaps = function() {
        console.log('Google Maps API loaded successfully');
        window.dispatchEvent(new CustomEvent('google-maps-loaded'));
    };
</script>

@push('scripts')
<script src="https://maps.googleapis.com/maps/api/js?key={{ config('services.google_maps.api_key') }}&libraries=places&v=weekly&callback=initGoogleMaps" async defer></script>
@endpush

@endsection

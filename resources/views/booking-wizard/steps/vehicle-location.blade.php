@extends('booking-wizard.layout', [
    'currentStep' => 3,
    'nextButtonText' => 'Dalej',
    'formId' => 'vehicle-location-form',
    'backUrl' => route('booking.step', ['step' => 2]),
])

@section('step-content')
<div class="vehicle-location fade-in">
    {{-- Step Title --}}
    <div class="vehicle-location__header text-center mb-8">
        <h2 class="vehicle-location__title text-3xl sm:text-4xl font-bold text-gray-900 mb-3">
            Pojazd i lokalizacja
        </h2>
        <p class="vehicle-location__subtitle text-lg text-gray-600">
            Podaj dane pojazdu i miejsce, gdzie mamy przyjechać
        </p>
    </div>

    {{-- Validation Error Toast (Fixed position, shown when validation fails) --}}
    <div
        x-data="{ show: false, message: '' }"
        x-show="show"
        x-transition:enter="transition ease-out duration-300"
        x-transition:enter-start="opacity-0 transform -translate-y-4"
        x-transition:enter-end="opacity-100 transform translate-y-0"
        x-transition:leave="transition ease-in duration-200"
        x-transition:leave-start="opacity-100 transform translate-y-0"
        x-transition:leave-end="opacity-0 transform -translate-y-4"
        @validation-error.window="show = true; message = $event.detail.message; setTimeout(() => show = false, 5000)"
        x-cloak
        class="fixed top-20 left-4 right-4 z-50 mx-auto max-w-md"
    >
        <div class="bg-red-600 text-white px-4 py-3 rounded-xl shadow-lg flex items-start gap-3">
            <div class="flex-shrink-0 mt-0.5">
                <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" />
                </svg>
            </div>
            <div class="flex-1">
                <p class="text-sm font-medium" x-text="message"></p>
            </div>
            <button @click="show = false" class="flex-shrink-0 text-white/80 hover:text-white">
                <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd" />
                </svg>
            </button>
        </div>
    </div>

    {{-- Form --}}
    <form
        id="vehicle-location-form"
        method="POST"
        action="{{ route('booking.step.store', ['step' => 3]) }}"
        class="vehicle-location__form max-w-2xl mx-auto"
        novalidate
        x-data="vehicleLocationForm(
            @js(session('booking.vehicle_type_id')),
            @js(session('booking.location_address')),
            @js(session('booking.location_latitude')),
            @js(session('booking.location_longitude')),
            @js(session('booking.location_place_id')),
            @js(session('booking.location_components'))
        )"
        x-init="$watch('canSubmit()', val => window.dispatchEvent(new CustomEvent('booking-validation-changed', { detail: { canSubmit: val, step: 3 } })))"
        @validate-step3.window="triggerFullValidation()"
    >
        @csrf

        {{-- Section 1: Vehicle Type (Required) --}}
        <div class="vehicle-location__section mb-8">
            <div class="bg-white rounded-2xl p-6 shadow-md border border-gray-200">
                <div class="flex items-center justify-between mb-4">
                    <div>
                        <h3 class="text-lg font-bold text-gray-900 mb-1">Typ Pojazdu</h3>
                        <p class="text-sm text-gray-600">Wybierz rozmiar swojego auta</p>
                    </div>
                    <span class="text-xs font-semibold text-primary-600 bg-primary-100 px-3 py-1 rounded-full">
                        Wymagane
                    </span>
                </div>

                {{-- Vehicle Type Selection Button --}}
                <button
                    type="button"
                    @click="$dispatch('open-bottom-sheet', { id: 'vehicle-type-selector' })"
                    class="vehicle-location__type-selector w-full flex items-center justify-between px-6 py-4 bg-gray-50 hover:bg-gray-100 active:scale-98 border-2 border-gray-300 rounded-xl transition-all duration-200 text-left"
                    :class="selectedVehicleType ? 'border-primary-400 bg-primary-50' : ''"
                >
                    <div class="flex items-center gap-4">
                        <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-primary-500 to-primary-600 flex items-center justify-center flex-shrink-0">
                            <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
                            </svg>
                        </div>
                        <div>
                            <div class="text-sm font-medium text-gray-600 mb-0.5">Wybrany typ pojazdu</div>
                            <div class="text-base font-bold text-gray-900" x-text="selectedVehicleTypeName || 'Wybierz typ pojazdu'"></div>
                        </div>
                    </div>
                    <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                    </svg>
                </button>

                {{-- Hidden input for form submission --}}
                <input
                    type="hidden"
                    name="vehicle_type_id"
                    x-model="selectedVehicleType"
                    required
                >

                @error('vehicle_type_id')
                    <p class="mt-2 text-sm text-red-600 flex items-center gap-2">
                        <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
                        </svg>
                        {{ $message }}
                    </p>
                @enderror
            </div>
        </div>

        {{-- Section 2: Vehicle Details (Required) --}}
        <div class="vehicle-location__section mb-8">
            <div class="bg-white rounded-2xl p-6 shadow-md border border-gray-200">
                <div class="flex items-center justify-between mb-4">
                    <div>
                        <h3 class="text-lg font-bold text-gray-900 mb-1">Szczegóły Pojazdu</h3>
                        <p class="text-sm text-gray-600">Dane pojazdu do rezerwacji</p>
                    </div>
                    <span class="text-xs font-semibold text-primary-600 bg-primary-100 px-3 py-1 rounded-full">
                        Wymagane
                    </span>
                </div>

                <div class="space-y-4">
                    {{-- Brand --}}
                    <div class="vehicle-location__field">
                        <label for="vehicle-brand" class="block text-sm font-medium text-gray-700 mb-2">
                            Marka <span class="text-red-500">*</span>
                        </label>
                        <input
                            type="text"
                            id="vehicle-brand"
                            name="vehicle_brand"
                            value="{{ old('vehicle_brand', session('booking.vehicle_brand')) }}"
                            placeholder="np. BMW, Toyota, Volkswagen"
                            class="vehicle-location__input w-full px-4 py-3 border-2 rounded-xl focus:ring-2 transition-all duration-200"
                            :class="{
                                'border-green-400 focus:border-green-400 focus:ring-green-200': validFields.vehicleBrand,
                                'border-red-400 focus:border-red-400 focus:ring-red-200': errors.vehicleBrand,
                                'border-gray-300 focus:border-primary-400 focus:ring-primary-200': !validFields.vehicleBrand && !errors.vehicleBrand
                            }"
                            x-model="vehicleBrand"
                            @blur="validateField('vehicleBrand')"
                        >
                        <p x-show="errors.vehicleBrand" x-text="errors.vehicleBrand" class="mt-2 text-sm text-red-600"></p>
                    </div>

                    {{-- Model --}}
                    <div class="vehicle-location__field">
                        <label for="vehicle-model" class="block text-sm font-medium text-gray-700 mb-2">
                            Model <span class="text-red-500">*</span>
                        </label>
                        <input
                            type="text"
                            id="vehicle-model"
                            name="vehicle_model"
                            value="{{ old('vehicle_model', session('booking.vehicle_model')) }}"
                            placeholder="np. 320d, Corolla, Golf"
                            class="vehicle-location__input w-full px-4 py-3 border-2 rounded-xl focus:ring-2 transition-all duration-200"
                            :class="{
                                'border-green-400 focus:border-green-400 focus:ring-green-200': validFields.vehicleModel,
                                'border-red-400 focus:border-red-400 focus:ring-red-200': errors.vehicleModel,
                                'border-gray-300 focus:border-primary-400 focus:ring-primary-200': !validFields.vehicleModel && !errors.vehicleModel
                            }"
                            x-model="vehicleModel"
                            @blur="validateField('vehicleModel')"
                        >
                        <p x-show="errors.vehicleModel" x-text="errors.vehicleModel" class="mt-2 text-sm text-red-600"></p>
                    </div>

                    {{-- Year --}}
                    <div class="vehicle-location__field">
                        <label for="vehicle-year" class="block text-sm font-medium text-gray-700 mb-2">
                            Rok produkcji <span class="text-red-500">*</span>
                        </label>
                        <input
                            type="number"
                            id="vehicle-year"
                            name="vehicle_year"
                            value="{{ old('vehicle_year', session('booking.vehicle_year')) }}"
                            placeholder="np. 2020"
                            min="1900"
                            max="{{ date('Y') + 1 }}"
                            class="vehicle-location__input w-full px-4 py-3 border-2 rounded-xl focus:ring-2 transition-all duration-200"
                            :class="{
                                'border-green-400 focus:border-green-400 focus:ring-green-200': validFields.vehicleYear,
                                'border-red-400 focus:border-red-400 focus:ring-red-200': errors.vehicleYear,
                                'border-gray-300 focus:border-primary-400 focus:ring-primary-200': !validFields.vehicleYear && !errors.vehicleYear
                            }"
                            x-model="vehicleYear"
                            @blur="validateField('vehicleYear')"
                        >
                        <p x-show="errors.vehicleYear" x-text="errors.vehicleYear" class="mt-2 text-sm text-red-600"></p>
                    </div>

                    {{-- Registration Number --}}
                    <div class="vehicle-location__field">
                        <label for="registration-number" class="block text-sm font-medium text-gray-700 mb-2">
                            Numer rejestracyjny <span class="text-red-500">*</span>
                        </label>
                        <input
                            type="text"
                            id="registration-number"
                            name="registration_number"
                            value="{{ old('registration_number', session('booking.registration_number')) }}"
                            placeholder="np. WE 12345"
                            maxlength="15"
                            class="vehicle-location__input w-full px-4 py-3 border-2 rounded-xl focus:ring-2 transition-all duration-200 uppercase"
                            :class="{
                                'border-green-400 focus:border-green-400 focus:ring-green-200': validFields.registrationNumber,
                                'border-red-400 focus:border-red-400 focus:ring-red-200': errors.registrationNumber,
                                'border-gray-300 focus:border-primary-400 focus:ring-primary-200': !validFields.registrationNumber && !errors.registrationNumber
                            }"
                            x-model="registrationNumber"
                            @blur="validateField('registrationNumber')"
                        >
                        <p class="mt-1 text-xs text-gray-500">Format: np. WA 12345, KR 1234A</p>
                        <p x-show="errors.registrationNumber" x-text="errors.registrationNumber" class="mt-2 text-sm text-red-600"></p>
                    </div>
                </div>
            </div>
        </div>

        {{-- Section 3: Service Location (Required) --}}
        <div class="vehicle-location__section mb-8">
            <div class="bg-white rounded-2xl p-6 shadow-md border border-gray-200">
                <div class="flex items-center justify-between mb-4">
                    <div>
                        <h3 class="text-lg font-bold text-gray-900 mb-1">Miejsce Serwisu</h3>
                        <p class="text-sm text-gray-600">Gdzie możemy przyjechać z samochodem?</p>
                    </div>
                    <span class="text-xs font-semibold text-primary-600 bg-primary-100 px-3 py-1 rounded-full">
                        Wymagane
                    </span>
                </div>

                {{-- Address Input with Google Maps Autocomplete --}}
                <div class="vehicle-location__field">
                    <label for="location-address" class="block text-sm font-medium text-gray-700 mb-2">
                        Adres
                    </label>
                    <div class="relative">
                        <div class="absolute left-4 top-1/2 -translate-y-1/2 pointer-events-none">
                            <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" />
                            </svg>
                        </div>
                        <input
                            type="text"
                            id="location-address"
                            name="location_address"
                            x-model="locationAddress"
                            placeholder="Zacznij wpisywać adres..."
                            required
                            class="vehicle-location__input w-full pl-12 py-3 border-2 border-gray-300 rounded-xl focus:border-primary-400 focus:ring-2 focus:ring-primary-200 transition-all duration-200"
                            :class="{
                                'pr-32': addressValidationStatus === 'selected' || addressValidationStatus === 'validating',
                                'pr-4': !addressValidationStatus || addressValidationStatus === 'error',
                                'border-green-400 focus:border-green-400 focus:ring-green-200': addressValidationStatus === 'selected',
                                'border-red-400 focus:border-red-400 focus:ring-red-200': addressValidationStatus === 'error'
                            }"
                        >

                        {{-- Status Badge --}}
                        <div class="absolute right-3 top-1/2 -translate-y-1/2 flex items-center gap-2">
                            {{-- Loading Spinner --}}
                            <div x-show="addressValidationStatus === 'validating'" x-cloak class="flex items-center gap-2 px-3 py-1.5 bg-blue-100 text-blue-700 rounded-full text-xs font-medium">
                                <svg class="animate-spin h-3.5 w-3.5" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                                <span>Sprawdzam...</span>
                            </div>

                            {{-- Success Badge --}}
                            <div x-show="addressValidationStatus === 'selected'" x-cloak class="flex items-center gap-1.5 px-3 py-1.5 bg-green-100 text-green-700 rounded-full text-xs font-medium">
                                <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                                </svg>
                                <span>Zweryfikowano</span>
                            </div>
                        </div>
                    </div>

                    {{-- Help Text --}}
                    <p class="mt-2 text-xs text-gray-500 flex items-start gap-1.5">
                        <svg class="w-4 h-4 text-gray-400 flex-shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd" />
                        </svg>
                        <span>Wybierz adres z listy podpowiedzi lub wpisz pełny adres - automatycznie go zweryfikujemy</span>
                    </p>

                    {{-- Hidden fields for location data --}}
                    <input type="hidden" id="location-latitude" name="location_latitude" x-model="locationLat">
                    <input type="hidden" id="location-longitude" name="location_longitude" x-model="locationLng">
                    <input type="hidden" id="location-place-id" name="location_place_id" x-model="locationPlaceId">
                    <input type="hidden" id="location-components" name="location_components" x-model="locationComponents">

                    @error('location_address')
                        <p class="mt-2 text-sm text-red-600 flex items-center gap-2">
                            <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
                            </svg>
                            {{ $message }}
                        </p>
                    @enderror

                    @error('location_latitude')
                        <p class="mt-2 text-sm text-red-600 flex items-center gap-2">
                            <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
                            </svg>
                            Wybierz adres z listy podpowiedzi Google Maps
                        </p>
                    @enderror

                    @error('location_longitude')
                        <p class="mt-2 text-sm text-red-600 flex items-center gap-2">
                            <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
                            </svg>
                            Wybierz adres z listy podpowiedzi Google Maps
                        </p>
                    @enderror

                    {{-- Service Area Validation Feedback --}}
                    <div class="mt-4 space-y-4">
                        {{-- Checking Status --}}
                        <div x-show="serviceAreaValidation.isChecking" x-cloak class="p-4 bg-blue-50 border border-blue-200 rounded-xl">
                            <div class="flex items-center gap-3">
                                <svg class="animate-spin h-5 w-5 text-blue-500 flex-shrink-0" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                                <span class="text-sm font-medium text-blue-700">Sprawdzanie dostępności obszaru obsługi...</span>
                            </div>
                        </div>

                        {{-- Success - Location Within Service Area --}}
                        <div x-show="serviceAreaValidation.isValid === true" x-cloak class="p-4 bg-green-50 border border-green-200 rounded-xl">
                            <div class="flex items-start gap-3">
                                <div class="w-10 h-10 rounded-full bg-green-100 flex items-center justify-center flex-shrink-0">
                                    <svg class="w-5 h-5 text-green-600" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                                    </svg>
                                </div>
                                <div>
                                    <div class="text-sm font-bold text-green-900 mb-1">Świetnie! Obsługujemy Twoją lokalizację</div>
                                    <div class="text-xs text-green-700">Możesz kontynuować rezerwację</div>
                                </div>
                            </div>
                        </div>

                        {{-- Error - Location Outside Service Area + Available Areas List --}}
                        <div x-show="serviceAreaValidation.isValid === false" x-cloak class="p-6 bg-red-50 border border-red-200 rounded-xl">
                            <div class="flex items-start gap-3 mb-4">
                                <div class="w-10 h-10 rounded-full bg-red-100 flex items-center justify-center flex-shrink-0">
                                    <svg class="w-5 h-5 text-red-600" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" />
                                    </svg>
                                </div>
                                <div class="flex-1">
                                    <div class="text-sm font-bold text-red-900 mb-1">Przepraszamy, nie obsługujemy tej lokalizacji</div>
                                    <div class="text-xs text-red-700" x-text="serviceAreaValidation.message"></div>
                                </div>
                            </div>

                            {{-- Available Service Areas Info --}}
                            <div class="mt-4 p-4 bg-white rounded-lg border border-gray-200">
                                <div class="flex items-start gap-2 mb-3">
                                    <svg class="w-5 h-5 text-blue-600 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                    </svg>
                                    <div>
                                        <h5 class="text-sm font-bold text-gray-900 mb-1">Obecnie obsługujemy następujące obszary:</h5>
                                        <div class="space-y-2 mt-2">
                                            <template x-for="area in serviceAreaValidation.availableAreas" :key="area.city">
                                                <div class="flex items-center gap-2 text-xs text-gray-700">
                                                    <svg class="w-4 h-4 text-primary-600" fill="currentColor" viewBox="0 0 20 20">
                                                        <path fill-rule="evenodd" d="M5.05 4.05a7 7 0 119.9 9.9L10 18.9l-4.95-4.95a7 7 0 010-9.9zM10 11a2 2 0 100-4 2 2 0 000 4z" clip-rule="evenodd" />
                                                    </svg>
                                                    <span class="font-semibold" x-text="area.city"></span>
                                                    <span class="text-gray-500">•</span>
                                                    <span x-text="area.radius_km + ' km zasięgu'"></span>
                                                </div>
                                            </template>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Map Preview (if location selected) --}}
                <div class="vehicle-location__map-preview mt-4" x-show="locationLat && locationLng" x-cloak>
                    <div id="location-map" class="w-full h-64 rounded-xl overflow-hidden border-2 border-gray-200"></div>
                </div>
            </div>
        </div>

        {{-- Section 4: Service Location Type (appears after service area validation) --}}
        @if(count($serviceLocationTypes ?? []) > 0)
        <div
            class="vehicle-location__section mb-8"
            x-show="serviceAreaValidation.isValid === true"
            x-transition:enter="transition ease-out duration-300"
            x-transition:enter-start="opacity-0 transform translate-y-4"
            x-transition:enter-end="opacity-100 transform translate-y-0"
            x-cloak
        >
            <div class="bg-white rounded-2xl p-6 shadow-md border border-gray-200">
                <div class="flex items-center justify-between mb-4">
                    <div>
                        <h3 class="text-lg font-bold text-gray-900 mb-1">Typ Lokalizacji</h3>
                        <p class="text-sm text-gray-600">Gdzie będzie zaparkowany pojazd?</p>
                    </div>
                    <span class="text-xs font-semibold text-primary-600 bg-primary-100 px-3 py-1 rounded-full">
                        Wymagane
                    </span>
                </div>

                <div class="space-y-3">
                    @foreach($serviceLocationTypes as $index => $locationType)
                    <label class="service-location-card flex items-start gap-4 p-4 bg-gray-50 hover:bg-primary-50
                                  border-2 border-gray-200 hover:border-primary-400 rounded-xl
                                  cursor-pointer transition-all duration-200"
                           :class="{ 'border-primary-500 bg-primary-50 ring-2 ring-primary-200': selectedLocationType === '{{ $locationType['name'] }}' }">
                        <input type="radio" name="service_location_type" value="{{ $locationType['name'] }}"
                               x-model="selectedLocationType" class="sr-only">

                        <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-primary-500 to-primary-600
                                    flex items-center justify-center flex-shrink-0">
                            @if(!empty($locationType['icon']))
                                <x-dynamic-component :component="'heroicon-o-' . $locationType['icon']" class="w-6 h-6 text-white" />
                            @else
                                <x-heroicon-o-map-pin class="w-6 h-6 text-white" />
                            @endif
                        </div>

                        <div class="flex-1">
                            <div class="text-base font-bold text-gray-900">{{ $locationType['name'] }}</div>
                            @if(!empty($locationType['description']))
                                <p class="text-sm text-gray-600 mt-1">{{ $locationType['description'] }}</p>
                            @endif
                        </div>

                        <div class="w-6 h-6 rounded-full border-2 flex items-center justify-center flex-shrink-0"
                             :class="selectedLocationType === '{{ $locationType['name'] }}' ? 'border-primary-500 bg-primary-500' : 'border-gray-300'">
                            <svg x-show="selectedLocationType === '{{ $locationType['name'] }}'" class="w-4 h-4 text-white" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
                            </svg>
                        </div>
                    </label>
                    @endforeach
                </div>

                <p x-show="errors.serviceLocationType" x-text="errors.serviceLocationType" class="mt-2 text-sm text-red-600"></p>

                @error('service_location_type')
                    <p class="mt-2 text-sm text-red-600 flex items-center gap-2">
                        <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
                        </svg>
                        {{ $message }}
                    </p>
                @enderror
            </div>
        </div>
        @endif

        {{-- Trust Signal --}}
        <div class="vehicle-location__trust-signal bg-blue-50 rounded-xl p-4 border border-blue-200 flex items-center gap-3">
            <div class="w-12 h-12 rounded-full bg-blue-100 flex items-center justify-center flex-shrink-0">
                <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                </svg>
            </div>
            <div>
                <div class="text-sm font-bold text-gray-900">Bezpieczna Lokalizacja</div>
                <div class="text-xs text-gray-600">Twój adres nie jest udostępniany publicznie</div>
            </div>
        </div>

        {{-- Navigation Buttons --}}
        <div class="booking-wizard__form-actions mt-8 space-y-4">
            <button
                type="submit"
                class="booking-wizard__next w-full min-h-14 px-6 py-4 bg-primary-500 hover:bg-primary-600
                       text-white font-semibold text-lg rounded-xl
                       transition-all duration-200 ease-out
                       active:scale-[0.98]
                       focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-500
                       flex items-center justify-center gap-2"
            >
                <span>Dalej</span>
                <x-heroicon-m-arrow-right class="w-5 h-5" />
            </button>

            <a href="{{ route('booking.step', ['step' => 2]) }}"
               class="w-full min-h-11 px-6 py-3 bg-gray-100 hover:bg-gray-200
                      text-gray-700 font-medium rounded-xl
                      transition-all duration-200 ease-out
                      flex items-center justify-center gap-2">
                <x-heroicon-m-arrow-left class="w-5 h-5" />
                <span>Wróć</span>
            </a>
        </div>
    </form>
</div>

{{-- Bottom Sheet: Vehicle Type Selector --}}
<x-booking-wizard.bottom-sheet id="vehicle-type-selector" title="Wybierz Typ Pojazdu">
    <div class="vehicle-type-cards grid grid-cols-1 gap-4">
        @foreach($vehicleTypes as $type)
            <button
                type="button"
                @click="$dispatch('select-vehicle-type', { id: {{ $type->id }}, name: '{{ $type->name }}' })"
                class="vehicle-type-card text-left p-4 bg-white hover:bg-primary-50 active:scale-98 border-2 border-gray-200 hover:border-primary-400 rounded-xl transition-all duration-200"
                :class="$root.selectedVehicleType === {{ $type->id }} ? 'border-primary-400 bg-primary-50 ring-4 ring-primary-200' : ''"
            >
                <div class="flex items-start gap-4">
                    {{-- Icon --}}
                    <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-primary-500 to-primary-600 flex items-center justify-center flex-shrink-0">
                        <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
                        </svg>
                    </div>

                    {{-- Content --}}
                    <div class="flex-1">
                        <h4 class="text-base font-bold text-gray-900 mb-1">{{ $type->name }}</h4>
                        <p class="text-sm text-gray-600 mb-2">{{ $type->description }}</p>
                        <div class="text-xs text-gray-500 italic">
                            Przykłady: {{ $type->examples }}
                        </div>
                    </div>

                    {{-- Selection Indicator --}}
                    <div x-show="$root.selectedVehicleType === {{ $type->id }}" x-cloak class="flex-shrink-0">
                        <div class="w-6 h-6 rounded-full bg-primary-500 flex items-center justify-center">
                            <svg class="w-4 h-4 text-white" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
                            </svg>
                        </div>
                    </div>
                </div>
            </button>
        @endforeach
    </div>
</x-booking-wizard.bottom-sheet>
@endsection

@push('scripts')
{{-- Google Maps API (Modern Loading Pattern with Places Library Wait) --}}
@if($googleMapsApiKey)
<script>
    window.GOOGLE_MAPS_CONFIG = {
        apiKey: '{{ $googleMapsApiKey }}',
        mapId: '{{ $googleMapsMapId }}'
    };
</script>

{{-- Import Service Area Map Handler --}}
<script type="module">
    import { ServiceAreaMapHandler } from '@/serviceAreaMap.js';
    window.ServiceAreaMapHandler = ServiceAreaMapHandler;
</script>

<script>
    // Modern Google Maps loading pattern with proper places library initialization
    (function() {
        const script = document.createElement('script');
        script.src = 'https://maps.googleapis.com/maps/api/js?key={{ $googleMapsApiKey }}&libraries=places,geometry,marker&loading=async';
        script.async = true;
        script.defer = true;

        script.onload = function() {
            // Wait for google.maps.places to be fully initialized
            // When using loading=async, places library may take extra time
            const checkPlacesReady = setInterval(function() {
                if (window.google && window.google.maps && window.google.maps.places && window.google.maps.places.Autocomplete) {
                    clearInterval(checkPlacesReady);
                    console.log('Google Maps Places library ready');

                    // Initialize autocomplete now that places is confirmed ready
                    if (typeof initGoogleMaps === 'function') {
                        initGoogleMaps();
                    }
                }
            }, 50); // Check every 50ms

            // Timeout after 10 seconds
            setTimeout(function() {
                clearInterval(checkPlacesReady);
                if (!window.google?.maps?.places?.Autocomplete) {
                    console.error('Google Maps Places library failed to load');
                }
            }, 10000);
        };

        script.onerror = function() {
            console.error('Failed to load Google Maps API script');
        };

        document.head.appendChild(script);
    })();
</script>
@endif

<script>
let mapInstance = null;
let markerInstance = null;
let serviceAreaHandler = null;

function vehicleLocationForm(initialVehicleType, initialAddress, initialLat, initialLng, initialPlaceId, initialComponents) {
    return {
        selectedVehicleType: initialVehicleType,
        selectedVehicleTypeName: '',
        selectedLocationType: '{{ session('booking.service_location_type', '') }}',
        vehicleBrand: document.getElementById('vehicle-brand')?.value || '',
        vehicleModel: document.getElementById('vehicle-model')?.value || '',
        vehicleYear: document.getElementById('vehicle-year')?.value || '',
        registrationNumber: document.getElementById('registration-number')?.value || '',
        locationAddress: initialAddress || '',
        locationLat: initialLat || '',
        locationLng: initialLng || '',
        locationPlaceId: initialPlaceId || '',
        locationComponents: initialComponents || '',
        addressValidationStatus: '', // '', 'validating', 'selected', 'error'
        isGeocodingInProgress: false,

        // Field validation state
        validFields: {
            vehicleBrand: false,
            vehicleModel: false,
            vehicleYear: false,
            registrationNumber: false,
        },
        errors: {
            serviceLocationType: '',
        },

        // Service Area Validation
        serviceAreaValidation: {
            isValid: null,              // null, true, false
            isChecking: false,
            message: '',
            nearestArea: null,
            availableAreas: [],         // List of available service areas
        },

        // Validation UI state
        validationErrorMessage: '',
        showValidationToast: false,

        init() {
            // Load vehicle type name if pre-selected
            if (this.selectedVehicleType) {
                this.loadVehicleTypeName(this.selectedVehicleType);
            }

            // If coordinates exist from session, mark as validated, show map, and validate service area
            if (this.locationLat && this.locationLng) {
                this.addressValidationStatus = 'selected';

                // Initialize map after DOM is ready
                this.$nextTick(() => {
                    if (typeof initMap === 'function') {
                        setTimeout(() => initMap(parseFloat(this.locationLat), parseFloat(this.locationLng)), 100);
                    }
                });

                // Check if we have cached service area validation (avoids 429 Too Many Requests)
                const cachedServiceAreaValid = {{ session('booking.service_area_valid') ? 'true' : 'false' }};
                const cachedLat = {{ session('booking.service_area_coords.lat', 0) }};
                const cachedLng = {{ session('booking.service_area_coords.lng', 0) }};

                // Use cached result if coordinates match (within ~11 meters tolerance)
                if (cachedServiceAreaValid &&
                    Math.abs(cachedLat - parseFloat(this.locationLat)) < 0.0001 &&
                    Math.abs(cachedLng - parseFloat(this.locationLng)) < 0.0001) {
                    console.log('Using cached service area validation');
                    this.serviceAreaValidation.isValid = true;
                } else {
                    // Re-validate if no cache or coordinates changed
                    this.$nextTick(() => {
                        this.validateServiceArea(
                            parseFloat(this.locationLat),
                            parseFloat(this.locationLng),
                            this.locationAddress
                        );
                    });
                }
            }

            // Listen for vehicle type selection from bottom sheet
            this.$watch('selectedVehicleType', (value) => {
                if (value) {
                    this.loadVehicleTypeName(value);
                }
            });

            // Event listener for vehicle type selection
            window.addEventListener('select-vehicle-type', (event) => {
                this.selectVehicleType(event.detail.id, event.detail.name);
            });

            // Watch address changes - reset validation when user types
            this.$watch('locationAddress', (newValue, oldValue) => {
                if (newValue !== oldValue && this.addressValidationStatus === 'selected') {
                    this.addressValidationStatus = '';
                }
            });

            // Setup blur event listener for address input
            this.$nextTick(() => {
                const addressInput = document.getElementById('location-address');
                if (addressInput) {
                    addressInput.addEventListener('blur', () => {
                        this.handleAddressBlur();
                    });
                }
            });

            // Add form submit listener to ensure coordinates exist
            const form = document.getElementById('vehicle-location-form');
            if (form) {
                form.addEventListener('submit', (e) => {
                    if (!this.validateLocationBeforeSubmit()) {
                        e.preventDefault();
                        return false;
                    }
                });
            }

            // Mark fields as valid if they have pre-filled values from session
            if (this.vehicleBrand?.trim()) this.validFields.vehicleBrand = true;
            if (this.vehicleModel?.trim()) this.validFields.vehicleModel = true;
            if (this.vehicleYear) this.validFields.vehicleYear = true;
            if (this.registrationNumber?.trim()) this.validFields.registrationNumber = true;
        },

        /**
         * Validate a single field and set error/valid state
         */
        validateField(fieldName) {
            delete this.errors[fieldName];
            this.validFields[fieldName] = false;

            const currentYear = new Date().getFullYear();

            switch (fieldName) {
                case 'vehicleBrand':
                    if (!this.vehicleBrand?.trim()) {
                        this.errors[fieldName] = 'Podaj markę pojazdu.';
                    } else if (this.vehicleBrand.trim().length < 2) {
                        this.errors[fieldName] = 'Marka musi mieć co najmniej 2 znaki.';
                    } else {
                        this.validFields[fieldName] = true;
                    }
                    break;

                case 'vehicleModel':
                    if (!this.vehicleModel?.trim()) {
                        this.errors[fieldName] = 'Podaj model pojazdu.';
                    } else if (this.vehicleModel.trim().length < 1) {
                        this.errors[fieldName] = 'Model musi mieć co najmniej 1 znak.';
                    } else {
                        this.validFields[fieldName] = true;
                    }
                    break;

                case 'vehicleYear':
                    const year = parseInt(this.vehicleYear);
                    if (!this.vehicleYear) {
                        this.errors[fieldName] = 'Podaj rok produkcji.';
                    } else if (isNaN(year)) {
                        this.errors[fieldName] = 'Rok produkcji musi być liczbą.';
                    } else if (year < 1900) {
                        this.errors[fieldName] = 'Rok produkcji musi być większy niż 1900.';
                    } else if (year > currentYear + 1) {
                        this.errors[fieldName] = 'Rok produkcji nie może być z przyszłości.';
                    } else {
                        this.validFields[fieldName] = true;
                    }
                    break;

                case 'registrationNumber':
                    const plateRegex = /^[A-Z]{2,3}[\s-]?[A-Z0-9]{4,5}$/i;
                    if (!this.registrationNumber?.trim()) {
                        this.errors[fieldName] = 'Podaj numer rejestracyjny.';
                    } else if (!plateRegex.test(this.registrationNumber.trim())) {
                        this.errors[fieldName] = 'Nieprawidłowy format (np. WA 12345).';
                    } else {
                        this.validFields[fieldName] = true;
                    }
                    break;
            }
        },

        /**
         * Trigger full form validation (called by layout via custom event)
         */
        triggerFullValidation() {
            // Validate all vehicle fields
            this.validateField('vehicleBrand');
            this.validateField('vehicleModel');
            this.validateField('vehicleYear');
            this.validateField('registrationNumber');

            // Check if any errors exist (check values, not just keys - some keys may have empty string values)
            const hasErrorValues = Object.values(this.errors).some(v => v);
            const hasErrors = hasErrorValues ||
                              !this.selectedVehicleType ||
                              this.serviceAreaValidation.isValid === false;

            if (hasErrors) {
                this.$el.dataset.validationFailed = 'true';
                this.scrollToFirstError();
            } else {
                this.$el.dataset.validationFailed = '';
            }
        },

        /**
         * Scroll to the first field with an error
         */
        scrollToFirstError() {
            this.$nextTick(() => {
                // Priority: vehicle type → brand → model → year → registration → location
                const fieldOrder = [
                    { check: !this.selectedVehicleType, selector: '.vehicle-location__type-selector' },
                    { check: this.errors.vehicleBrand, selector: '#vehicle-brand' },
                    { check: this.errors.vehicleModel, selector: '#vehicle-model' },
                    { check: this.errors.vehicleYear, selector: '#vehicle-year' },
                    { check: this.errors.registrationNumber, selector: '#registration-number' },
                    { check: this.serviceAreaValidation.isValid === false, selector: '#location-address' },
                ];

                for (const field of fieldOrder) {
                    if (field.check) {
                        const element = document.querySelector(field.selector);
                        if (element) {
                            element.scrollIntoView({ behavior: 'smooth', block: 'center' });
                            element.focus();
                            // Visual feedback
                            element.classList.add('ring-4', 'ring-red-400');
                            setTimeout(() => {
                                element.classList.remove('ring-4', 'ring-red-400');
                            }, 2000);
                            break;
                        }
                    }
                }
            });
        },

        async handleAddressBlur() {
            const address = this.locationAddress?.trim();

            // If address is filled but no coordinates, try to geocode
            if (address && !this.locationLat && !this.isGeocodingInProgress) {
                await this.geocodeAddress(address);
            }
        },

        async geocodeAddress(address) {
            if (!address || !window.google?.maps?.Geocoder) {
                console.warn('Geocoder not available');
                return;
            }

            this.isGeocodingInProgress = true;
            this.addressValidationStatus = 'validating';

            console.log('Geocoding address:', address);

            try {
                const geocoder = new google.maps.Geocoder();
                const result = await new Promise((resolve, reject) => {
                    geocoder.geocode(
                        {
                            address: address,
                            componentRestrictions: { country: 'pl' }
                        },
                        (results, status) => {
                            if (status === 'OK' && results?.[0]) {
                                resolve(results[0]);
                            } else {
                                reject(new Error(`Geocoding failed: ${status}`));
                            }
                        }
                    );
                });

                // Successfully geocoded
                const location = result.geometry.location;
                const lat = location.lat();
                const lng = location.lng();

                console.log('Geocoding successful:', { lat, lng, address: result.formatted_address });

                // Update Alpine.js data
                this.locationLat = lat;
                this.locationLng = lng;
                this.locationPlaceId = result.place_id || '';
                this.locationComponents = JSON.stringify(result.address_components || []);
                this.locationAddress = result.formatted_address || address;
                this.addressValidationStatus = 'selected';

                // Update hidden fields directly (fallback for form submission)
                document.getElementById('location-latitude').value = lat;
                document.getElementById('location-longitude').value = lng;
                document.getElementById('location-place-id').value = result.place_id || '';
                document.getElementById('location-components').value = JSON.stringify(result.address_components || []);

                // Validate service area (CRITICAL: Check if location is within service coverage)
                await this.validateServiceArea(lat, lng, result.formatted_address || address);

                // Initialize or update map
                if (typeof initMap === 'function') {
                    setTimeout(() => initMap(lat, lng), 100);
                }

            } catch (error) {
                console.error('Geocoding error:', error);
                this.addressValidationStatus = 'error';

                // Show user-friendly error
                setTimeout(() => {
                    alert('Nie mogliśmy zweryfikować tego adresu. Spróbuj wybrać adres z listy podpowiedzi Google Maps.');
                }, 100);
            } finally {
                this.isGeocodingInProgress = false;
            }
        },

        /**
         * Validate location before form submission
         * Returns true if valid, false if should block submission
         *
         * SECURITY: This is frontend UX validation only.
         * Backend re-validates in BookingController::confirm() for security.
         */
        validateLocationBeforeSubmit() {
            console.log('validateLocationBeforeSubmit called', {
                selectedVehicleType: this.selectedVehicleType,
                vehicleBrand: this.vehicleBrand,
                vehicleModel: this.vehicleModel,
                vehicleYear: this.vehicleYear,
                registrationNumber: this.registrationNumber,
                locationAddress: this.locationAddress,
                locationLat: this.locationLat,
                serviceAreaIsValid: this.serviceAreaValidation.isValid,
                serviceAreaIsValidType: typeof this.serviceAreaValidation.isValid,
                serviceAreaIsChecking: this.serviceAreaValidation.isChecking,
                selectedLocationType: this.selectedLocationType,
            });

            // Trigger full validation first
            this.triggerFullValidation();

            // CRITICAL: Vehicle type is required
            if (!this.selectedVehicleType) {
                this.showValidationError('Proszę wybrać typ pojazdu.');
                return false;
            }

            // CRITICAL: Brand is required
            if (!this.vehicleBrand?.trim() || this.errors.vehicleBrand) {
                this.showValidationError(this.errors.vehicleBrand || 'Proszę podać markę pojazdu.');
                return false;
            }

            // CRITICAL: Model is required
            if (!this.vehicleModel?.trim() || this.errors.vehicleModel) {
                this.showValidationError(this.errors.vehicleModel || 'Proszę podać model pojazdu.');
                return false;
            }

            // CRITICAL: Year is required
            if (!this.vehicleYear || this.errors.vehicleYear) {
                this.showValidationError(this.errors.vehicleYear || 'Proszę podać rok produkcji.');
                return false;
            }

            // CRITICAL: Registration number is required
            if (!this.registrationNumber?.trim() || this.errors.registrationNumber) {
                this.showValidationError(this.errors.registrationNumber || 'Proszę podać numer rejestracyjny.');
                return false;
            }

            const address = this.locationAddress?.trim();

            // If no address at all, let browser validation handle it
            if (!address) {
                return true;
            }

            // If address exists but no coordinates, block submission
            if (address && !this.locationLat) {
                this.showValidationError('Proszę wybrać adres z listy podpowiedzi lub poczekać na weryfikację adresu.');
                return false;
            }

            // Block submission if service area validation failed
            if (this.serviceAreaValidation.isValid === false) {
                this.showValidationError('Przepraszamy, nie obsługujemy tej lokalizacji. Prosimy wybrać adres z jednego z dostępnych obszarów obsługi.');
                return false;
            }

            // Block submission if service area is still being validated
            if (this.serviceAreaValidation.isChecking) {
                this.showValidationError('Proszę poczekać na sprawdzenie dostępności obszaru obsługi.');
                return false;
            }

            // Validate service location type if configured and location is valid
            if (!this.validateServiceLocationType()) {
                this.showValidationError('Wybierz typ lokalizacji.');
                return false;
            }

            console.log('validateLocationBeforeSubmit PASSED - form should submit');
            return true;
        },

        /**
         * Show inline validation error with toast notification
         */
        showValidationError(message) {
            // Dispatch event for the toast component
            window.dispatchEvent(new CustomEvent('validation-error', {
                detail: { message: message }
            }));

            // Scroll to the service area error section if visible
            this.$nextTick(() => {
                const errorSection = document.querySelector('[x-show="serviceAreaValidation.isValid === false"]');
                if (errorSection && this.serviceAreaValidation.isValid === false) {
                    errorSection.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
            });
        },

        /**
         * Validate service location type selection
         */
        validateServiceLocationType() {
            this.errors.serviceLocationType = '';
            const hasLocationTypes = {{ count($serviceLocationTypes ?? []) > 0 ? 'true' : 'false' }};

            // Use truthy check instead of strict equality (API might return string or boolean)
            const isServiceAreaValid = this.serviceAreaValidation.isValid === true || this.serviceAreaValidation.isValid === 'true';

            if (hasLocationTypes && isServiceAreaValid && !this.selectedLocationType) {
                this.errors.serviceLocationType = 'Wybierz typ lokalizacji.';
                return false;
            }
            return true;
        },

        /**
         * Check if form can be submitted (used by layout for button state)
         */
        canSubmit() {
            // CRITICAL: Vehicle type, brand, model, year and registration are required
            if (!this.selectedVehicleType) return false;
            if (!this.vehicleBrand?.trim()) return false;
            if (!this.vehicleModel?.trim()) return false;
            if (!this.vehicleYear) return false;
            if (!this.registrationNumber?.trim()) return false;

            // Check for validation errors (excluding serviceLocationType which is checked separately)
            const errorKeys = Object.keys(this.errors).filter(k => k !== 'serviceLocationType' || this.errors[k]);
            if (errorKeys.some(k => this.errors[k])) return false;

            // Service area must be valid (not false, not still checking)
            if (this.serviceAreaValidation.isValid === false) return false;
            if (this.serviceAreaValidation.isChecking) return false;

            // Must have coordinates if address is filled
            const address = this.locationAddress?.trim();
            if (address && !this.locationLat) return false;

            // Service location type required if options are configured and location is valid
            const hasLocationTypes = {{ count($serviceLocationTypes ?? []) > 0 ? 'true' : 'false' }};
            const isServiceAreaValid = this.serviceAreaValidation.isValid === true || this.serviceAreaValidation.isValid === 'true';
            if (hasLocationTypes && isServiceAreaValid && !this.selectedLocationType) {
                return false;
            }

            return true;
        },

        /**
         * Highlight a field with error styling and scroll into view
         */
        highlightField(selector) {
            this.$nextTick(() => {
                const field = document.querySelector(selector);
                if (field) {
                    field.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    field.classList.add('ring-4', 'ring-red-400', 'animate-pulse');
                    setTimeout(() => {
                        field.classList.remove('ring-4', 'ring-red-400', 'animate-pulse');
                    }, 2000);
                }
            });
        },

        loadVehicleTypeName(typeId) {
            // Try to find the vehicle type name from the bottom sheet buttons
            const buttons = document.querySelectorAll('.vehicle-type-card h4');
            buttons.forEach(button => {
                const parentButton = button.closest('button');
                const onClick = parentButton?.getAttribute('@click');
                if (onClick && onClick.includes(`id: ${typeId}`)) {
                    this.selectedVehicleTypeName = button.textContent.trim();
                }
            });
        },

        async validateServiceArea(lat, lng, address) {
            this.serviceAreaValidation.isChecking = true;
            this.serviceAreaValidation.isValid = null;
            this.serviceAreaValidation.message = '';
            this.serviceAreaValidation.nearestArea = null;
            this.serviceAreaValidation.availableAreas = [];

            try {
                // Direct AJAX request to validation endpoint (independent of ServiceAreaMapHandler)
                const response = await fetch('/api/service-area/validate', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
                    },
                    body: JSON.stringify({
                        latitude: lat,
                        longitude: lng
                    })
                });

                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                }

                const result = await response.json();

                this.serviceAreaValidation.isValid = result.valid;
                this.serviceAreaValidation.message = result.message || '';
                this.serviceAreaValidation.nearestArea = result.nearest_area || null;
                this.serviceAreaValidation.availableAreas = result.available_areas || [];

                // Show visual feedback on map if handler is available
                if (serviceAreaHandler) {
                    serviceAreaHandler.showUserLocation(lat, lng, result.valid);
                }

                console.log('Service area validation result:', result);
            } catch (error) {
                console.error('Service area validation failed:', error);
                this.serviceAreaValidation.isValid = false;
                this.serviceAreaValidation.message = 'Nie udało się sprawdzić obszaru obsługi. Spróbuj ponownie.';
            } finally {
                this.serviceAreaValidation.isChecking = false;
            }
        },

        selectVehicleType(typeId, typeName) {
            this.selectedVehicleType = typeId;
            this.selectedVehicleTypeName = typeName;

            // Close bottom sheet
            this.$dispatch('close-bottom-sheet', { id: 'vehicle-type-selector' });

            // Haptic feedback
            if (window.navigator && window.navigator.vibrate) {
                window.navigator.vibrate(10);
            }
        }
    }
}

/**
 * Initialize Google Maps Places Autocomplete
 *
 * NOTE: Using google.maps.places.Autocomplete (deprecated as of March 2025)
 * Reason: Project explicitly uses "Modern JS API, NOT Web Components"
 * Status: Not scheduled for discontinuation, will continue to receive bug fixes
 * Migration: Will migrate to PlaceAutocompleteElement when project requirements allow
 *
 * @see https://developers.google.com/maps/documentation/javascript/places-migration-overview
 */
async function initGoogleMaps() {
    const addressInput = document.getElementById('location-address');
    if (!addressInput) {
        console.warn('Google Maps: Address input not found');
        return;
    }

    // Defensive check: Ensure google.maps.places.Autocomplete exists
    if (!window.google || !window.google.maps || !window.google.maps.places || !window.google.maps.places.Autocomplete) {
        console.error('Google Maps: Places library not loaded. Cannot initialize autocomplete.');
        return;
    }

    console.log('Google Maps: Initializing Places Autocomplete');

    // Initialize service area handler
    if (window.ServiceAreaMapHandler && !serviceAreaHandler) {
        serviceAreaHandler = new window.ServiceAreaMapHandler('location-map', {
            validationEndpoint: '/api/service-area/validate',
            areasEndpoint: '/api/service-area/areas',
        });

        console.log('ServiceAreaMapHandler initialized');
    }

    // Initialize autocomplete
    const autocomplete = new google.maps.places.Autocomplete(addressInput, {
        types: ['address'],
        componentRestrictions: { country: 'pl' }
    });

    autocomplete.addListener('place_changed', function() {
        const place = autocomplete.getPlace();

        if (!place.geometry) {
            console.warn('Google Maps: No geometry found for selected place');
            return;
        }

        const lat = place.geometry.location.lat();
        const lng = place.geometry.location.lng();

        console.log('Google Maps: Place selected from dropdown', {
            lat,
            lng,
            address: place.formatted_address,
            placeId: place.place_id
        });

        // Update Alpine.js data via the form element that has x-data
        const formElement = addressInput.closest('form');

        // Update hidden fields directly (primary method - ensures form submission works)
        document.getElementById('location-latitude').value = lat;
        document.getElementById('location-longitude').value = lng;
        document.getElementById('location-place-id').value = place.place_id || '';
        document.getElementById('location-components').value = JSON.stringify(place.address_components || []);

        // Also update Alpine.js reactive data if available (for map preview and validation status)
        if (formElement && formElement._x_dataStack && formElement._x_dataStack.length > 0) {
            const alpineData = formElement._x_dataStack[0];
            alpineData.locationLat = lat;
            alpineData.locationLng = lng;
            alpineData.locationPlaceId = place.place_id || '';
            alpineData.locationComponents = JSON.stringify(place.address_components || []);
            alpineData.locationAddress = place.formatted_address || addressInput.value;
            alpineData.addressValidationStatus = 'selected'; // Mark as validated

            console.log('Google Maps: Alpine.js data updated successfully with validation status');

            // Validate service area
            if (typeof alpineData.validateServiceArea === 'function') {
                alpineData.validateServiceArea(lat, lng, place.formatted_address);
            }
        } else {
            console.warn('Google Maps: Alpine.js component not found, hidden fields updated directly');
        }

        // Verify hidden fields are populated
        console.log('Hidden fields verification:', {
            latitude: document.getElementById('location-latitude').value,
            longitude: document.getElementById('location-longitude').value
        });

        // Initialize or update map
        setTimeout(() => initMap(lat, lng), 100);
    });
}

async function initMap(lat, lng) {
    const mapContainer = document.getElementById('location-map');
    if (!mapContainer) return;

    const position = { lat, lng };

    if (!mapInstance) {
        mapInstance = new google.maps.Map(mapContainer, {
            center: position,
            zoom: 16,
            mapId: window.GOOGLE_MAPS_CONFIG.mapId,
            disableDefaultUI: true,
            zoomControl: true,
        });

        // Initialize service area visualization
        if (serviceAreaHandler) {
            serviceAreaHandler.setMap(mapInstance);
            await serviceAreaHandler.initialize();
            console.log('Service area circles loaded on map');
        }
    } else {
        mapInstance.setCenter(position);
    }

    // Add or update marker
    if (markerInstance) {
        markerInstance.setMap(null);
    }

    markerInstance = new google.maps.marker.AdvancedMarkerElement({
        map: mapInstance,
        position: position,
        title: 'Lokalizacja serwisu',
    });
}
</script>
@endpush

@push('styles')
<style>
/* Vehicle Location Step */
.vehicle-location {
    animation: fadeIn 0.5s ease-out;
}

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

/* Vehicle Type Selector Button */
.vehicle-location__type-selector {
    transition: all 0.3s cubic-bezier(0.68, -0.55, 0.265, 1.55);
}

.vehicle-location__type-selector:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
}

.vehicle-location__type-selector:active {
    transform: scale(0.98);
}

/* Input Fields */
.vehicle-location__input {
    transition: all 0.2s ease;
}

.vehicle-location__input:focus {
    transform: translateY(-1px);
    box-shadow: 0 4px 8px rgba(74, 165, 176, 0.1);
}

/* Vehicle Type Cards in Bottom Sheet */
.vehicle-type-card {
    transition: all 0.2s cubic-bezier(0.68, -0.55, 0.265, 1.55);
}

.vehicle-type-card:hover {
    transform: translateX(4px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
}

.vehicle-type-card:active {
    transform: scale(0.98);
}

/* Alpine x-cloak */
[x-cloak] {
    display: none !important;
}

/* Active state scale */
.active\:scale-98:active {
    transform: scale(0.98);
}

/* Spin animation for loading spinner */
@keyframes spin {
    from {
        transform: rotate(0deg);
    }
    to {
        transform: rotate(360deg);
    }
}

.animate-spin {
    animation: spin 1s linear infinite;
}

/* Validation status transitions */
.vehicle-location__input {
    transition: border-color 0.3s ease, box-shadow 0.3s ease;
}

/* Service Location Type Cards */
.service-location-card {
    transition: all 0.2s cubic-bezier(0.68, -0.55, 0.265, 1.55);
}

.service-location-card:hover {
    transform: translateX(4px);
}

.service-location-card:active {
    transform: scale(0.98);
}
</style>
@endpush

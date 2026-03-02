@extends('booking-wizard.layout', [
    'currentStep' => 4,
    'nextButtonText' => 'Podsumowanie',
    'formId' => 'contact-info-form',
    'backUrl' => route('booking.step', ['step' => 3]),
])

@section('step-content')
<div class="contact-info fade-in">
    {{-- Step Title --}}
    <div class="contact-info__header text-center mb-8">
        <h2 class="contact-info__title text-3xl sm:text-4xl font-bold text-gray-900 mb-3">
            Dane kontaktowe
        </h2>
        <p class="contact-info__subtitle text-lg text-gray-600">
            Użyjemy tych danych do potwierdzenia wizyty i wysyłania przypomnień
        </p>
    </div>

    {{-- Form --}}
    <form
        id="contact-info-form"
        method="POST"
        action="{{ route('booking.step.store', ['step' => 4]) }}"
        class="contact-info__form max-w-2xl mx-auto"
        x-data="contactInfoForm()"
        @submit="validateForm"
        novalidate
    >
        @csrf

        {{-- Section 1: Personal Information --}}
        <div class="contact-info__section mb-8">
            <div class="bg-white rounded-2xl p-6 shadow-md border border-gray-200">
                <h3 class="text-lg font-bold text-gray-900 mb-6">Dane Osobowe</h3>

                <div class="space-y-5">
                    {{-- First Name --}}
                    <div class="contact-info__field">
                        <label for="first-name" class="block text-sm font-medium text-gray-700 mb-2">
                            Imię <span class="text-red-500">*</span>
                        </label>
                        <div class="relative">
                            <input
                                type="text"
                                id="first-name"
                                name="first_name"
                                value="{{ old('first_name', $bookingData['first_name'] ?? '') }}"
                                required
                                autocomplete="given-name"
                                placeholder="Jan"
                                x-model="firstName"
                                @blur="validateField('firstName')"
                                class="contact-info__input w-full px-4 py-3 pr-12 border-2 border-gray-300 rounded-xl focus:border-orange-500 focus:ring-2 focus:ring-orange-200 transition-all duration-200"
                                :class="{'border-green-500': validFields.firstName, 'border-red-500': errors.firstName}"
                            >
                            {{-- Validation checkmark --}}
                            <div x-show="validFields.firstName" x-cloak class="absolute right-4 top-1/2 -translate-y-1/2">
                                <svg class="w-5 h-5 text-green-500" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                                </svg>
                            </div>
                        </div>
                        <p x-show="errors.firstName" x-text="errors.firstName" class="mt-2 text-sm text-red-600"></p>
                    </div>

                    {{-- Last Name --}}
                    <div class="contact-info__field">
                        <label for="last-name" class="block text-sm font-medium text-gray-700 mb-2">
                            Nazwisko <span class="text-red-500">*</span>
                        </label>
                        <div class="relative">
                            <input
                                type="text"
                                id="last-name"
                                name="last_name"
                                value="{{ old('last_name', $bookingData['last_name'] ?? '') }}"
                                required
                                autocomplete="family-name"
                                placeholder="Kowalski"
                                x-model="lastName"
                                @blur="validateField('lastName')"
                                class="contact-info__input w-full px-4 py-3 pr-12 border-2 border-gray-300 rounded-xl focus:border-orange-500 focus:ring-2 focus:ring-orange-200 transition-all duration-200"
                                :class="{'border-green-500': validFields.lastName, 'border-red-500': errors.lastName}"
                            >
                            <div x-show="validFields.lastName" x-cloak class="absolute right-4 top-1/2 -translate-y-1/2">
                                <svg class="w-5 h-5 text-green-500" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                                </svg>
                            </div>
                        </div>
                        <p x-show="errors.lastName" x-text="errors.lastName" class="mt-2 text-sm text-red-600"></p>
                    </div>

                    {{-- Email --}}
                    <div class="contact-info__field">
                        <label for="email" class="block text-sm font-medium text-gray-700 mb-2">
                            Adres Email <span class="text-red-500">*</span>
                        </label>
                        <div class="relative">
                            <div class="absolute left-4 top-1/2 -translate-y-1/2 pointer-events-none">
                                <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                                </svg>
                            </div>
                            <input
                                type="email"
                                id="email"
                                name="email"
                                value="{{ old('email', $bookingData['email'] ?? '') }}"
                                required
                                autocomplete="email"
                                placeholder="jan.kowalski@example.com"
                                @if(auth()->check())
                                    readonly
                                @endif
                                x-model="email"
                                @blur="validateField('email')"
                                class="contact-info__input w-full pl-12 pr-12 py-3 border-2 border-gray-300 rounded-xl focus:border-orange-500 focus:ring-2 focus:ring-orange-200 transition-all duration-200 @if(auth()->check()) bg-gray-50 cursor-not-allowed @endif"
                                :class="{'border-green-500': validFields.email, 'border-red-500': errors.email}"
                            >
                            <div x-show="validFields.email" x-cloak class="absolute right-4 top-1/2 -translate-y-1/2">
                                <svg class="w-5 h-5 text-green-500" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                                </svg>
                            </div>
                        </div>
                        @if(auth()->check())
                            <p class="mt-2 text-xs text-gray-500 flex items-center gap-1">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                                </svg>
                                Email z Twojego konta nie może być zmieniony podczas rezerwacji
                            </p>
                        @endif
                        <p x-show="errors.email" x-text="errors.email" class="mt-2 text-sm text-red-600"></p>
                    </div>

                    {{-- Phone --}}
                    <div class="contact-info__field">
                        <label for="phone" class="block text-sm font-medium text-gray-700 mb-2">
                            Numer Telefonu <span class="text-red-500">*</span>
                        </label>
                        <div class="relative">
                            <div class="absolute left-4 top-1/2 -translate-y-1/2 pointer-events-none">
                                <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
                                </svg>
                            </div>
                            <input
                                type="tel"
                                id="phone"
                                name="phone"
                                value="{{ old('phone', $bookingData['phone'] ?? '') }}"
                                required
                                autocomplete="tel"
                                placeholder="+48 123 456 789"
                                x-model="phone"
                                @blur="validateField('phone')"
                                class="contact-info__input w-full pl-12 pr-12 py-3 border-2 border-gray-300 rounded-xl focus:border-orange-500 focus:ring-2 focus:ring-orange-200 transition-all duration-200"
                                :class="{'border-green-500': validFields.phone, 'border-red-500': errors.phone}"
                            >
                            <div x-show="validFields.phone" x-cloak class="absolute right-4 top-1/2 -translate-y-1/2">
                                <svg class="w-5 h-5 text-green-500" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                                </svg>
                            </div>
                        </div>
                        <p class="mt-2 text-xs text-gray-500">Format: +48 lub zwykły numer</p>
                        <p x-show="errors.phone" x-text="errors.phone" class="mt-2 text-sm text-red-600"></p>
                    </div>
                </div>
            </div>
        </div>

        {{-- Section 2: Invoice --}}
        <div class="contact-info__section mb-8">
            <div class="bg-white rounded-2xl p-6 shadow-md border border-gray-200">
                <h3 class="text-lg font-bold text-gray-900 mb-6">Faktura</h3>

                {{-- Invoice Checkbox --}}
                <label class="contact-info__checkbox-label flex items-start gap-3 p-4 bg-gray-50 hover:bg-gray-100 rounded-xl cursor-pointer transition-colors duration-200">
                    <input
                        type="checkbox"
                        name="invoice_requested"
                        value="1"
                        x-model="showInvoice"
                        class="contact-info__checkbox mt-1 w-5 h-5 text-orange-500 border-2 border-gray-300 rounded focus:ring-2 focus:ring-orange-200"
                    >
                    <div class="flex-1">
                        <span class="font-medium text-gray-900">Chcę otrzymać fakturę VAT</span>
                        <p class="text-sm text-gray-600 mt-1">Podaj dane do faktury</p>
                    </div>
                </label>

                {{-- Invoice Fields (visible when checkbox checked) --}}
                <div x-show="showInvoice" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 -translate-y-2" x-transition:enter-end="opacity-100 translate-y-0" x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100 translate-y-0" x-transition:leave-end="opacity-0 -translate-y-2" class="mt-6 space-y-5">
                    {{-- Company Name --}}
                    <div class="contact-info__field">
                        <label for="invoice_company_name" class="block text-sm font-medium text-gray-700 mb-2">
                            Nazwa firmy / Imię i nazwisko <span class="text-red-500">*</span>
                        </label>
                        <input
                            type="text"
                            id="invoice_company_name"
                            name="invoice_company_name"
                            x-model="invoiceCompanyName"
                            @blur="validateInvoiceField('companyName')"
                            placeholder="Firma Sp. z o.o. lub Jan Kowalski"
                            :class="{'border-red-500': errors.invoice_companyName, 'border-green-500': validInvoiceFields.companyName}"
                            class="contact-info__input w-full px-4 py-3 border-2 border-gray-300 rounded-xl focus:border-orange-500 focus:ring-2 focus:ring-orange-200 transition-all duration-200"
                        >
                        <p x-show="errors.invoice_companyName" x-text="errors.invoice_companyName" class="mt-2 text-sm text-red-600"></p>
                    </div>

                    {{-- NIP --}}
                    <div class="contact-info__field">
                        <label for="invoice_nip" class="block text-sm font-medium text-gray-700 mb-2">
                            NIP <span class="text-red-500">*</span>
                        </label>
                        <input
                            type="text"
                            id="invoice_nip"
                            name="invoice_nip"
                            x-model="invoiceNip"
                            @blur="validateInvoiceField('nip')"
                            placeholder="1234567890"
                            maxlength="13"
                            :class="{'border-red-500': errors.invoice_nip, 'border-green-500': validInvoiceFields.nip}"
                            class="contact-info__input w-full px-4 py-3 border-2 border-gray-300 rounded-xl focus:border-orange-500 focus:ring-2 focus:ring-orange-200 transition-all duration-200"
                        >
                        <p class="mt-1 text-xs text-gray-500">10 cyfr, można używać myślników lub spacji</p>
                        <p x-show="errors.invoice_nip" x-text="errors.invoice_nip" class="mt-2 text-sm text-red-600"></p>
                    </div>

                    {{-- Street and Number --}}
                    <div class="grid grid-cols-3 gap-4">
                        <div class="col-span-2 contact-info__field">
                            <label for="invoice_street" class="block text-sm font-medium text-gray-700 mb-2">
                                Ulica <span class="text-red-500">*</span>
                            </label>
                            <input
                                type="text"
                                id="invoice_street"
                                name="invoice_street"
                                x-model="invoiceStreet"
                                @blur="validateInvoiceField('street')"
                                placeholder="ul. Przykładowa"
                                :class="{'border-red-500': errors.invoice_street, 'border-green-500': validInvoiceFields.street}"
                                class="contact-info__input w-full px-4 py-3 border-2 border-gray-300 rounded-xl focus:border-orange-500 focus:ring-2 focus:ring-orange-200 transition-all duration-200"
                            >
                            <p x-show="errors.invoice_street" x-text="errors.invoice_street" class="mt-2 text-sm text-red-600"></p>
                        </div>
                        <div class="contact-info__field">
                            <label for="invoice_street_number" class="block text-sm font-medium text-gray-700 mb-2">
                                Numer <span class="text-red-500">*</span>
                            </label>
                            <input
                                type="text"
                                id="invoice_street_number"
                                name="invoice_street_number"
                                x-model="invoiceStreetNumber"
                                @blur="validateInvoiceField('streetNumber')"
                                placeholder="12/4"
                                :class="{'border-red-500': errors.invoice_streetNumber, 'border-green-500': validInvoiceFields.streetNumber}"
                                class="contact-info__input w-full px-4 py-3 border-2 border-gray-300 rounded-xl focus:border-orange-500 focus:ring-2 focus:ring-orange-200 transition-all duration-200"
                            >
                            <p x-show="errors.invoice_streetNumber" x-text="errors.invoice_streetNumber" class="mt-2 text-sm text-red-600"></p>
                        </div>
                    </div>

                    {{-- Postal Code and City --}}
                    <div class="grid grid-cols-3 gap-4">
                        <div class="contact-info__field">
                            <label for="invoice_postal_code" class="block text-sm font-medium text-gray-700 mb-2">
                                Kod pocztowy <span class="text-red-500">*</span>
                            </label>
                            <input
                                type="text"
                                id="invoice_postal_code"
                                name="invoice_postal_code"
                                x-model="invoicePostalCode"
                                @blur="validateInvoiceField('postalCode')"
                                placeholder="00-000"
                                maxlength="6"
                                :class="{'border-red-500': errors.invoice_postalCode, 'border-green-500': validInvoiceFields.postalCode}"
                                class="contact-info__input w-full px-4 py-3 border-2 border-gray-300 rounded-xl focus:border-orange-500 focus:ring-2 focus:ring-orange-200 transition-all duration-200"
                            >
                            <p x-show="errors.invoice_postalCode" x-text="errors.invoice_postalCode" class="mt-2 text-sm text-red-600"></p>
                        </div>
                        <div class="col-span-2 contact-info__field">
                            <label for="invoice_city" class="block text-sm font-medium text-gray-700 mb-2">
                                Miasto <span class="text-red-500">*</span>
                            </label>
                            <input
                                type="text"
                                id="invoice_city"
                                name="invoice_city"
                                x-model="invoiceCity"
                                @blur="validateInvoiceField('city')"
                                placeholder="Warszawa"
                                :class="{'border-red-500': errors.invoice_city, 'border-green-500': validInvoiceFields.city}"
                                class="contact-info__input w-full px-4 py-3 border-2 border-gray-300 rounded-xl focus:border-orange-500 focus:ring-2 focus:ring-orange-200 transition-all duration-200"
                            >
                            <p x-show="errors.invoice_city" x-text="errors.invoice_city" class="mt-2 text-sm text-red-600"></p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Section 3: Notification Preferences (only if any channel has active reminders) --}}
        @if($emailReminders->isNotEmpty() || $smsReminders->isNotEmpty())
        <div class="contact-info__section mb-8">
            <div class="bg-white rounded-2xl p-6 shadow-md border border-gray-200">
                <h3 class="text-lg font-bold text-gray-900 mb-2">Powiadomienia</h3>
                <p class="text-sm text-gray-600 mb-6">Jak chcesz otrzymywać przypomnienia o rezerwacji?</p>

                <div class="space-y-4">
                    {{-- Email Notifications --}}
                    @if($emailReminders->isNotEmpty())
                    <label class="contact-info__checkbox-label flex items-start gap-3 p-4 bg-gray-50 hover:bg-gray-100 rounded-xl cursor-pointer transition-colors duration-200">
                        <input
                            type="checkbox"
                            name="notify_email"
                            value="1"
                            {{ old('notify_email', session('booking.notify_email', false)) ? 'checked' : '' }}
                            class="contact-info__checkbox mt-1 w-5 h-5 text-orange-500 border-2 border-gray-300 rounded focus:ring-2 focus:ring-orange-200"
                        >
                        <div class="flex-1">
                            <div class="flex items-center gap-2">
                                <svg class="w-5 h-5 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                                </svg>
                                <span class="font-medium text-gray-900">Powiadomienia Email</span>
                            </div>
                            <p class="text-sm text-gray-600 mt-1">Potwierdzenie + przypomnienie {{ $emailReminders->map(fn ($r) => $r->getTimingDescription())->join(', ') }}</p>
                        </div>
                    </label>
                    @endif

                    {{-- SMS Notifications --}}
                    @if($smsReminders->isNotEmpty())
                    <label class="contact-info__checkbox-label flex items-start gap-3 p-4 bg-gray-50 hover:bg-gray-100 rounded-xl cursor-pointer transition-colors duration-200">
                        <input
                            type="checkbox"
                            name="notify_sms"
                            value="1"
                            {{ old('notify_sms', session('booking.notify_sms', false)) ? 'checked' : '' }}
                            class="contact-info__checkbox mt-1 w-5 h-5 text-orange-500 border-2 border-gray-300 rounded focus:ring-2 focus:ring-orange-200"
                        >
                        <div class="flex-1">
                            <div class="flex items-center gap-2">
                                <svg class="w-5 h-5 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z" />
                                </svg>
                                <span class="font-medium text-gray-900">Powiadomienia SMS</span>
                            </div>
                            <p class="text-sm text-gray-600 mt-1">Przypomnienie {{ $smsReminders->map(fn ($r) => $r->getTimingDescription())->join(', ') }}</p>
                        </div>
                    </label>
                    @endif
                </div>
            </div>
        </div>
        @endif

        {{-- Section 4: Terms & Conditions --}}
        <div class="contact-info__section mb-8">
            <div class="bg-white rounded-2xl p-6 shadow-md border border-gray-200">
                <label class="contact-info__checkbox-label flex items-start gap-3 cursor-pointer">
                    <input
                        type="checkbox"
                        id="terms-accepted"
                        name="terms_accepted"
                        value="1"
                        x-model="termsAccepted"
                        @change="validateField('termsAccepted')"
                        :class="{'ring-2 ring-red-500': errors.termsAccepted}"
                        class="contact-info__checkbox mt-1 w-5 h-5 text-orange-500 border-2 border-gray-300 rounded focus:ring-2 focus:ring-orange-200"
                    >
                    <div class="flex-1">
                        <p class="text-sm text-gray-900">
                            Akceptuję
                            <a href="/regulamin" target="_blank" class="text-orange-600 hover:text-orange-700 font-medium underline">Regulamin</a>
                            oraz
                            <a href="/polityka-prywatnosci" target="_blank" class="text-orange-600 hover:text-orange-700 font-medium underline">Politykę Prywatności</a>
                            <span class="text-red-500">*</span>
                        </p>
                    </div>
                </label>
                <p x-show="errors.termsAccepted" x-text="errors.termsAccepted" class="mt-2 text-sm text-red-600"></p>
            </div>
        </div>

        {{-- Trust Signal --}}
        <div class="contact-info__trust-signal bg-green-50 rounded-xl p-4 border border-green-200 flex items-center gap-3">
            <div class="w-12 h-12 rounded-full bg-green-100 flex items-center justify-center flex-shrink-0">
                <svg class="w-6 h-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                </svg>
            </div>
            <div>
                <div class="text-sm font-bold text-gray-900">Twoje Dane Są Bezpieczne</div>
                <div class="text-xs text-gray-600">Szyfrowanie SSL · RODO · Nie udostępniamy danych</div>
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
                <span>Podsumowanie</span>
                <x-heroicon-m-arrow-right class="w-5 h-5" />
            </button>

            <a href="{{ route('booking.step', ['step' => 3]) }}"
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
@endsection

@push('scripts')
<script>
function contactInfoForm() {
    return {
        // Personal info fields
        firstName: document.getElementById('first-name')?.value || '',
        lastName: document.getElementById('last-name')?.value || '',
        email: document.getElementById('email')?.value || '',
        phone: document.getElementById('phone')?.value || '',

        // Invoice fields
        showInvoice: {{ old('invoice_requested', session('booking.invoice_requested', false)) ? 'true' : 'false' }},
        invoiceCompanyName: document.getElementById('invoice_company_name')?.value || '',
        invoiceStreet: document.getElementById('invoice_street')?.value || '',
        invoiceStreetNumber: document.getElementById('invoice_street_number')?.value || '',
        invoicePostalCode: document.getElementById('invoice_postal_code')?.value || '',
        invoiceCity: document.getElementById('invoice_city')?.value || '',
        invoiceNip: document.getElementById('invoice_nip')?.value || '',

        // Terms checkbox
        termsAccepted: document.querySelector('input[name="terms_accepted"]')?.checked || false,

        // Validation state
        validFields: {
            firstName: false,
            lastName: false,
            email: false,
            phone: false,
            termsAccepted: false,
        },
        validInvoiceFields: {
            companyName: false,
            street: false,
            streetNumber: false,
            postalCode: false,
            city: false,
            nip: false,
        },

        // Error messages for inline display
        errors: {},

        // Field ID mapping for scroll-to-error
        fieldIdMap: {
            firstName: 'first-name',
            lastName: 'last-name',
            email: 'email',
            phone: 'phone',
            termsAccepted: 'terms-accepted',
            companyName: 'invoice_company_name',
            street: 'invoice_street',
            streetNumber: 'invoice_street_number',
            postalCode: 'invoice_postal_code',
            city: 'invoice_city',
            nip: 'invoice_nip',
        },

        init() {
            // Pre-validate filled fields on page load
            this.$nextTick(() => {
                if (this.firstName) this.validateField('firstName');
                if (this.lastName) this.validateField('lastName');
                if (this.email) this.validateField('email');
                if (this.phone) this.validateField('phone');

                // Invoice fields (if invoice requested and filled)
                if (this.showInvoice) {
                    if (this.invoiceCompanyName) this.validateInvoiceField('companyName');
                    if (this.invoiceStreet) this.validateInvoiceField('street');
                    if (this.invoiceStreetNumber) this.validateInvoiceField('streetNumber');
                    if (this.invoicePostalCode) this.validateInvoiceField('postalCode');
                    if (this.invoiceCity) this.validateInvoiceField('city');
                    if (this.invoiceNip) this.validateInvoiceField('nip');
                }
            });

            // Listen for validation trigger from layout.blade.php
            this.$el.addEventListener('validate-step4', (e) => {
                this.triggerFullValidation();
            });
        },

        triggerFullValidation() {
            // Validate all personal info fields
            this.validateField('firstName');
            this.validateField('lastName');
            this.validateField('email');
            this.validateField('phone');

            // Validate invoice fields if requested
            if (this.showInvoice) {
                this.validateInvoiceField('companyName');
                this.validateInvoiceField('street');
                this.validateInvoiceField('streetNumber');
                this.validateInvoiceField('postalCode');
                this.validateInvoiceField('city');
                this.validateInvoiceField('nip');
            }

            // Validate terms at the end
            this.validateField('termsAccepted');

            // Check validity
            const personalValid = Object.values(this.validFields).every(v => v);
            let invoiceValid = true;
            if (this.showInvoice) {
                invoiceValid = Object.values(this.validInvoiceFields).every(v => v);
            }

            // Set flag on form element for layout to read
            if (!personalValid || !invoiceValid) {
                this.$el.dataset.validationFailed = 'true';
                this.scrollToFirstError();
            } else {
                this.$el.dataset.validationFailed = '';
            }
        },

        validateField(fieldName) {
            const value = this[fieldName];
            delete this.errors[fieldName];

            switch (fieldName) {
                case 'firstName':
                    if (!value.trim()) {
                        this.errors[fieldName] = 'Podaj imię.';
                        this.validFields[fieldName] = false;
                    } else if (value.trim().length < 2) {
                        this.errors[fieldName] = 'Imię musi mieć co najmniej 2 znaki.';
                        this.validFields[fieldName] = false;
                    } else {
                        this.validFields[fieldName] = true;
                    }
                    break;

                case 'lastName':
                    if (!value.trim()) {
                        this.errors[fieldName] = 'Podaj nazwisko.';
                        this.validFields[fieldName] = false;
                    } else if (value.trim().length < 2) {
                        this.errors[fieldName] = 'Nazwisko musi mieć co najmniej 2 znaki.';
                        this.validFields[fieldName] = false;
                    } else {
                        this.validFields[fieldName] = true;
                    }
                    break;

                case 'email':
                    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                    if (!value.trim()) {
                        this.errors[fieldName] = 'Podaj adres e-mail.';
                        this.validFields[fieldName] = false;
                    } else if (!emailRegex.test(value)) {
                        this.errors[fieldName] = 'Podaj prawidłowy adres e-mail.';
                        this.validFields[fieldName] = false;
                    } else {
                        this.validFields[fieldName] = true;
                    }
                    break;

                case 'phone':
                    const phoneRegex = /^(\+48)?[\s-]?\d{9}$/;
                    const cleanPhone = value.replace(/\s/g, '');
                    if (!cleanPhone) {
                        this.errors[fieldName] = 'Podaj numer telefonu.';
                        this.validFields[fieldName] = false;
                    } else if (!phoneRegex.test(cleanPhone)) {
                        this.errors[fieldName] = 'Podaj prawidłowy numer telefonu (9 cyfr).';
                        this.validFields[fieldName] = false;
                    } else {
                        this.validFields[fieldName] = true;
                    }
                    break;

                case 'termsAccepted':
                    if (!this.termsAccepted) {
                        this.errors[fieldName] = 'Musisz zaakceptować regulamin.';
                        this.validFields[fieldName] = false;
                    } else {
                        this.validFields[fieldName] = true;
                    }
                    break;
            }

            if (this.validFields[fieldName]) {
                this.saveProgress();
            }
        },

        validateInvoiceField(fieldName) {
            delete this.errors['invoice_' + fieldName];

            // Map field names to values
            const valueMap = {
                companyName: this.invoiceCompanyName,
                street: this.invoiceStreet,
                streetNumber: this.invoiceStreetNumber,
                postalCode: this.invoicePostalCode,
                city: this.invoiceCity,
                nip: this.invoiceNip,
            };

            const errorMessages = {
                companyName: 'Podaj nazwę firmy lub imię i nazwisko.',
                street: 'Podaj ulicę.',
                streetNumber: 'Podaj numer budynku/lokalu.',
                postalCode: 'Podaj kod pocztowy.',
                city: 'Podaj miasto.',
                nip: 'Podaj NIP.',
            };

            const value = valueMap[fieldName] || '';

            // Special validation for NIP
            if (fieldName === 'nip') {
                const nipClean = value.replace(/[^0-9]/g, '');
                if (!nipClean) {
                    this.errors['invoice_nip'] = 'Podaj NIP.';
                    this.validInvoiceFields.nip = false;
                } else if (nipClean.length !== 10) {
                    this.errors['invoice_nip'] = 'NIP musi składać się z 10 cyfr.';
                    this.validInvoiceFields.nip = false;
                } else {
                    this.validInvoiceFields.nip = true;
                }
            } else if (!value.trim()) {
                this.errors['invoice_' + fieldName] = errorMessages[fieldName];
                this.validInvoiceFields[fieldName] = false;
            } else {
                this.validInvoiceFields[fieldName] = true;
            }

            if (this.validInvoiceFields[fieldName]) {
                this.saveProgress();
            }
        },

        validateForm(event) {
            // Validate all personal info fields
            this.validateField('firstName');
            this.validateField('lastName');
            this.validateField('email');
            this.validateField('phone');

            // Validate invoice fields only if invoice requested
            if (this.showInvoice) {
                this.validateInvoiceField('companyName');
                this.validateInvoiceField('street');
                this.validateInvoiceField('streetNumber');
                this.validateInvoiceField('postalCode');
                this.validateInvoiceField('city');
                this.validateInvoiceField('nip');
            }

            // Validate terms at the end
            this.validateField('termsAccepted');

            // Check personal info validity (includes termsAccepted)
            const personalValid = Object.values(this.validFields).every(valid => valid);

            // Check invoice validity (only if requested)
            let invoiceValid = true;
            if (this.showInvoice) {
                invoiceValid = Object.values(this.validInvoiceFields).every(valid => valid);
            }

            if (!personalValid || !invoiceValid) {
                event.preventDefault();

                // Find first invalid field and scroll to it
                this.scrollToFirstError();
                return false;
            }

            return true;
        },

        scrollToFirstError() {
            // Check personal fields first
            const personalFieldOrder = ['firstName', 'lastName', 'email', 'phone'];
            for (const field of personalFieldOrder) {
                if (!this.validFields[field]) {
                    const element = document.getElementById(this.fieldIdMap[field]);
                    if (element) {
                        element.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        element.focus();
                    }
                    return;
                }
            }

            // Check invoice fields if invoice requested
            if (this.showInvoice) {
                const invoiceFieldOrder = ['companyName', 'street', 'streetNumber', 'postalCode', 'city'];
                for (const field of invoiceFieldOrder) {
                    if (!this.validInvoiceFields[field]) {
                        const element = document.getElementById(this.fieldIdMap[field]);
                        if (element) {
                            element.scrollIntoView({ behavior: 'smooth', block: 'center' });
                            element.focus();
                        }
                        return;
                    }
                }
            }

            // Check terms at the end
            if (!this.validFields.termsAccepted) {
                const element = document.getElementById(this.fieldIdMap.termsAccepted);
                if (element) {
                    element.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    element.focus();
                }
                return;
            }
        },

        saveProgress() {
            clearTimeout(this.saveTimeout);
            this.saveTimeout = setTimeout(() => {
                const data = {
                    first_name: this.firstName,
                    last_name: this.lastName,
                    email: this.email,
                    phone: this.phone,
                    invoice_requested: this.showInvoice,
                };

                if (this.showInvoice) {
                    data.invoice_company_name = this.invoiceCompanyName;
                    data.invoice_street = this.invoiceStreet;
                    data.invoice_street_number = this.invoiceStreetNumber;
                    data.invoice_postal_code = this.invoicePostalCode;
                    data.invoice_city = this.invoiceCity;
                }

                fetch('{{ route('booking.save-progress') }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}'
                    },
                    body: JSON.stringify({ step: 4, data })
                });
            }, 500);
        }
    }
}
</script>
@endpush

@push('styles')
<style>
/* Contact Info Step */
.contact-info {
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

/* Input Fields */
.contact-info__input {
    transition: all 0.2s ease;
}

.contact-info__input:focus {
    transform: translateY(-1px);
    box-shadow: 0 4px 8px rgba(249, 115, 22, 0.1);
}

/* Valid field animation */
.contact-info__input.border-green-500 {
    animation: validPulse 0.3s ease;
}

@keyframes validPulse {
    0%, 100% {
        transform: scale(1);
    }
    50% {
        transform: scale(1.02);
    }
}

/* Checkbox Labels */
.contact-info__checkbox-label {
    transition: all 0.2s ease;
}

.contact-info__checkbox-label:hover {
    transform: translateX(2px);
}

/* Checkbox Styling */
.contact-info__checkbox {
    cursor: pointer;
    transition: all 0.2s ease;
}

.contact-info__checkbox:checked {
    background-color: rgb(249, 115, 22); /* orange-500 */
    border-color: rgb(249, 115, 22);
}

/* Alpine x-cloak */
[x-cloak] {
    display: none !important;
}
</style>
@endpush

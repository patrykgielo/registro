@extends('layouts.app')

@section('content')

{{-- Page header --}}
<x-layout.section spacing="sm" class="bg-surface-sunken">
    <x-layout.container>
        <div class="flex items-center gap-3">
            <a
                href="{{ route('cart.show') }}"
                class="inline-flex items-center justify-center h-8 w-8 rounded-lg text-text-muted
                       hover:text-text-primary hover:bg-surface-raised border border-transparent hover:border-border
                       transition-all duration-200 ease-out
                       focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50 focus-visible:ring-offset-2"
                aria-label="Wróć do koszyka"
            >
                <x-heroicon-m-arrow-left class="h-4 w-4" aria-hidden="true" />
            </a>
            <h1 class="text-3xl font-bold text-text-primary tracking-tight">
                Finalizacja zamówienia
            </h1>
        </div>
    </x-layout.container>
</x-layout.section>

{{-- General error (e.g. from checkout.submit) --}}
@if($errors->has('general'))
    <x-layout.container class="pt-6">
        <x-ui.alert variant="error" dismissible>
            {{ $errors->first('general') }}
        </x-ui.alert>
    </x-layout.container>
@endif

{{-- Main checkout body --}}
<x-layout.section spacing="default">
    <form
        method="POST"
        action="{{ route('checkout.submit') }}"
        novalidate
        data-checkout-form
        x-data="{
            customerType: '{{ old('customer_type', $profileData['customer_type'] ?? 'natural_person') }}',
            settlementMethod: '{{ old('settlement_method', $availableSettlementMethods[0] ?? 'online') }}',

            {{-- Natural person fields --}}
            firstName: '{{ old('customer_first_name', $profileData['first_name'] ?? '') }}',
            lastName: '{{ old('customer_last_name', $profileData['last_name'] ?? '') }}',
            email: '{{ old('customer_email', $profileData['email'] ?? '') }}',
            phone: '{{ old('customer_phone', $profileData['phone'] ?? '') }}',
            pesel: '{{ old('customer_pesel', $profileData['pesel'] ?? '') }}',
            street: '{{ old('customer_street', $profileData['street'] ?? '') }}',
            building: '{{ old('customer_building', $profileData['building'] ?? '') }}',
            apartment: '{{ old('customer_apartment', '') }}',
            city: '{{ old('customer_city', $profileData['city'] ?? '') }}',
            postalCode: '{{ old('customer_postal_code', $profileData['postal_code'] ?? '') }}',

            {{-- Natural person invoice toggle --}}
            invoice: {{ old('invoice_requested') ? 'true' : 'false' }},

            {{-- Business fields --}}
            companyName: '{{ old('invoice_company_name', $profileData['company_name'] ?? '') }}',
            nip: '{{ old('invoice_nip', $profileData['nip'] ?? '') }}',
            regon: '{{ old('company_regon', $profileData['regon'] ?? '') }}',
            krs: '{{ old('company_krs', $profileData['krs'] ?? '') }}',
            billingStreet: '{{ old('invoice_street', $profileData['billing_street'] ?? '') }}',
            billingBuilding: '{{ old('invoice_street_number', $profileData['billing_building'] ?? '') }}',
            billingCity: '{{ old('invoice_city', $profileData['billing_city'] ?? '') }}',
            billingPostal: '{{ old('invoice_postal_code', $profileData['billing_postal'] ?? '') }}',
            companyContactName: '{{ old('company_contact_name', '') }}',
            signatoryIdNumber: '{{ old('signatory_id_number', '') }}',
            differentPickupPerson: {{ old('pickup_person_name') ? 'true' : 'false' }},
            pickupPersonName: '{{ old('pickup_person_name', '') }}',
            pickupPersonIdNumber: '{{ old('pickup_person_id_number', '') }}',

            {{-- Consents --}}
            termsAccepted: {{ old('terms_accepted') ? 'true' : 'false' }},
            rodoAccepted: {{ old('rodo_accepted') ? 'true' : 'false' }},
            withdrawalExclusionAccepted: {{ old('withdrawal_exclusion_accepted') ? 'true' : 'false' }},
            saveToProfile: {{ old('save_to_profile') ? 'true' : 'false' }},

            {{-- Consent validation --}}
            consentSubmitAttempted: false,

            depositTotal: {{ $depositTotal }},

            get consentErrors() {
                if (!this.consentSubmitAttempted) return {};
                const errors = {};
                if (!this.termsAccepted) errors.terms = 'Akceptacja regulaminu jest wymagana.';
                if (!this.rodoAccepted) errors.rodo = 'Zgoda na przetwarzanie danych jest wymagana.';
                if (!this.withdrawalExclusionAccepted) errors.withdrawal = 'Potwierdzenie przyjęcia do wiadomości jest wymagane.';
                return errors;
            },

            submitForm(event) {
                this.consentSubmitAttempted = true;
                if (!this.termsAccepted || !this.rodoAccepted || !this.withdrawalExclusionAccepted) {
                    event.preventDefault();
                    this.$nextTick(() => {
                        const firstError = this.$el.querySelector('[data-consent-error]');
                        if (firstError) {
                            firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        }
                    });
                } else {
                    window.dispatchEvent(new CustomEvent('checkout:submitted'));
                }
            }
        }"
        @submit="submitForm($event)"
        aria-label="Formularz zamówienia"
    >
        @csrf
        <input type="hidden" name="customer_type" :value="customerType">
        <input type="hidden" name="settlement_method" :value="settlementMethod">

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8 items-start">

            {{-- ── Left column: customer data + consents ── --}}
            <div class="lg:col-span-2 space-y-6">

                {{-- ─── SECTION 1: Typ klienta ─── --}}
                <section aria-labelledby="customer-type-heading">
                    <x-ui.card>
                        <h2 id="customer-type-heading" class="text-base font-semibold text-text-primary mb-4">
                            Typ klienta
                        </h2>

                        {{-- Segmented control --}}
                        <div
                            role="radiogroup"
                            aria-labelledby="customer-type-heading"
                            class="inline-flex rounded-lg border border-border bg-surface-sunken p-1 gap-1"
                        >
                            <button
                                type="button"
                                role="radio"
                                :aria-checked="customerType === 'natural_person'"
                                @click="customerType = 'natural_person'"
                                :class="customerType === 'natural_person'
                                    ? 'bg-surface-raised text-text-primary shadow-xs border border-border font-medium'
                                    : 'text-text-muted hover:text-text-secondary'"
                                class="px-4 py-2 rounded-md text-sm transition-all duration-150 ease-out
                                       focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50 focus-visible:ring-offset-1
                                       cursor-pointer select-none min-h-[36px]"
                            >
                                Osoba fizyczna
                            </button>
                            <button
                                type="button"
                                role="radio"
                                :aria-checked="customerType === 'business'"
                                @click="customerType = 'business'"
                                :class="customerType === 'business'
                                    ? 'bg-surface-raised text-text-primary shadow-xs border border-border font-medium'
                                    : 'text-text-muted hover:text-text-secondary'"
                                class="px-4 py-2 rounded-md text-sm transition-all duration-150 ease-out
                                       focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50 focus-visible:ring-offset-1
                                       cursor-pointer select-none min-h-[36px]"
                            >
                                Firma
                            </button>
                        </div>
                    </x-ui.card>
                </section>

                {{-- ─── SECTION 2A: Dane osobowe (natural_person) ─── --}}
                <section
                    aria-labelledby="personal-data-heading"
                    x-show="customerType === 'natural_person'"
                    x-transition:enter="transition ease-out duration-200"
                    x-transition:enter-start="opacity-0 -translate-y-1"
                    x-transition:enter-end="opacity-100 translate-y-0"
                    x-transition:leave="transition ease-in duration-150"
                    x-transition:leave-start="opacity-100 translate-y-0"
                    x-transition:leave-end="opacity-0 -translate-y-1"
                >
                    <x-ui.card>
                        <h2 id="personal-data-heading" class="text-base font-semibold text-text-primary mb-6">
                            Dane osobowe
                        </h2>

                        <div class="space-y-5">

                            {{-- Row: Imię | Nazwisko --}}
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">

                                {{-- Imię --}}
                                <div class="space-y-1.5">
                                    <label for="customer_first_name" class="block text-sm font-medium text-text-primary">
                                        Imię
                                        <span class="text-error ml-0.5" aria-hidden="true">*</span>
                                    </label>
                                    <input
                                        type="text"
                                        id="customer_first_name"
                                        name="customer_first_name"
                                        x-model="firstName"
                                        :value="firstName"
                                        autocomplete="given-name"
                                        aria-required="true"
                                        aria-invalid="{{ $errors->has('customer_first_name') ? 'true' : 'false' }}"
                                        aria-describedby="{{ $errors->has('customer_first_name') ? 'customer_first_name-error' : '' }}"
                                        @class([
                                            'block w-full rounded-lg border bg-surface-raised px-3 py-2.5 text-sm text-text-primary placeholder:text-text-muted min-h-11',
                                            'transition-colors duration-200 ease-out',
                                            'focus:border-brand focus:ring-2 focus:ring-brand/20 focus:outline-none',
                                            'border-error focus:border-error focus:ring-error/20' => $errors->has('customer_first_name'),
                                            'border-border hover:border-border-strong' => !$errors->has('customer_first_name'),
                                        ])
                                        placeholder="Jan"
                                    >
                                    @error('customer_first_name')
                                        <p id="customer_first_name-error" role="alert" class="text-sm text-error mt-1">{{ $message }}</p>
                                    @enderror
                                </div>

                                {{-- Nazwisko --}}
                                <div class="space-y-1.5">
                                    <label for="customer_last_name" class="block text-sm font-medium text-text-primary">
                                        Nazwisko
                                        <span class="text-error ml-0.5" aria-hidden="true">*</span>
                                    </label>
                                    <input
                                        type="text"
                                        id="customer_last_name"
                                        name="customer_last_name"
                                        x-model="lastName"
                                        autocomplete="family-name"
                                        aria-required="true"
                                        aria-invalid="{{ $errors->has('customer_last_name') ? 'true' : 'false' }}"
                                        aria-describedby="{{ $errors->has('customer_last_name') ? 'customer_last_name-error' : '' }}"
                                        @class([
                                            'block w-full rounded-lg border bg-surface-raised px-3 py-2.5 text-sm text-text-primary placeholder:text-text-muted min-h-11',
                                            'transition-colors duration-200 ease-out',
                                            'focus:border-brand focus:ring-2 focus:ring-brand/20 focus:outline-none',
                                            'border-error focus:border-error focus:ring-error/20' => $errors->has('customer_last_name'),
                                            'border-border hover:border-border-strong' => !$errors->has('customer_last_name'),
                                        ])
                                        placeholder="Kowalski"
                                    >
                                    @error('customer_last_name')
                                        <p id="customer_last_name-error" role="alert" class="text-sm text-error mt-1">{{ $message }}</p>
                                    @enderror
                                </div>

                            </div>

                            {{-- Row: Email | Telefon --}}
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">

                                {{-- Email --}}
                                <div class="space-y-1.5">
                                    <label for="customer_email" class="block text-sm font-medium text-text-primary">
                                        Adres e-mail
                                        <span class="text-error ml-0.5" aria-hidden="true">*</span>
                                    </label>
                                    <input
                                        type="email"
                                        id="customer_email"
                                        name="customer_email"
                                        x-model="email"
                                        autocomplete="email"
                                        aria-required="true"
                                        aria-invalid="{{ $errors->has('customer_email') ? 'true' : 'false' }}"
                                        aria-describedby="{{ $errors->has('customer_email') ? 'customer_email-error' : 'customer_email-hint' }}"
                                        @class([
                                            'block w-full rounded-lg border bg-surface-raised px-3 py-2.5 text-sm text-text-primary placeholder:text-text-muted min-h-11',
                                            'transition-colors duration-200 ease-out',
                                            'focus:border-brand focus:ring-2 focus:ring-brand/20 focus:outline-none',
                                            'border-error focus:border-error focus:ring-error/20' => $errors->has('customer_email'),
                                            'border-border hover:border-border-strong' => !$errors->has('customer_email'),
                                        ])
                                        placeholder="jan@kowalski.pl"
                                    >
                                    @error('customer_email')
                                        <p id="customer_email-error" role="alert" class="text-sm text-error mt-1">{{ $message }}</p>
                                    @else
                                        <p id="customer_email-hint" class="text-xs text-text-muted mt-1">
                                            Potwierdzenie zamówienia zostanie wysłane na ten adres.
                                        </p>
                                    @enderror
                                </div>

                                {{-- Telefon --}}
                                <div class="space-y-1.5">
                                    <label for="customer_phone" class="block text-sm font-medium text-text-primary">
                                        Telefon
                                        <span class="text-error ml-0.5" aria-hidden="true">*</span>
                                    </label>
                                    <input
                                        type="tel"
                                        id="customer_phone"
                                        name="customer_phone"
                                        x-model="phone"
                                        autocomplete="tel"
                                        aria-required="true"
                                        aria-invalid="{{ $errors->has('customer_phone') ? 'true' : 'false' }}"
                                        aria-describedby="{{ $errors->has('customer_phone') ? 'customer_phone-error' : '' }}"
                                        @class([
                                            'block w-full rounded-lg border bg-surface-raised px-3 py-2.5 text-sm text-text-primary placeholder:text-text-muted min-h-11',
                                            'transition-colors duration-200 ease-out',
                                            'focus:border-brand focus:ring-2 focus:ring-brand/20 focus:outline-none',
                                            'border-error focus:border-error focus:ring-error/20' => $errors->has('customer_phone'),
                                            'border-border hover:border-border-strong' => !$errors->has('customer_phone'),
                                        ])
                                        placeholder="+48 600 000 000"
                                    >
                                    @error('customer_phone')
                                        <p id="customer_phone-error" role="alert" class="text-sm text-error mt-1">{{ $message }}</p>
                                    @enderror
                                </div>

                            </div>

                            {{-- Row: PESEL (full width). Mandatory only when the tenant opted in
                                 (checkout.pesel_required) — otherwise still offered, just optional. --}}
                            <div class="space-y-1.5">
                                <label for="customer_pesel" class="block text-sm font-medium text-text-primary">
                                    PESEL
                                    @if($peselRequired)
                                        <span class="text-error ml-0.5" aria-hidden="true">*</span>
                                    @else
                                        <span class="text-text-muted font-normal">(opcjonalnie)</span>
                                    @endif
                                </label>
                                <input
                                    type="text"
                                    id="customer_pesel"
                                    name="customer_pesel"
                                    x-model="pesel"
                                    inputmode="numeric"
                                    maxlength="11"
                                    pattern="[0-9]{11}"
                                    autocomplete="off"
                                    aria-required="{{ $peselRequired ? 'true' : 'false' }}"
                                    aria-invalid="{{ $errors->has('customer_pesel') ? 'true' : 'false' }}"
                                    aria-describedby="customer_pesel-hint{{ $errors->has('customer_pesel') ? ' customer_pesel-error' : '' }}"
                                    @class([
                                        'block w-full sm:max-w-xs rounded-lg border bg-surface-raised px-3 py-2.5 text-sm text-text-primary placeholder:text-text-muted font-mono min-h-11',
                                        'transition-colors duration-200 ease-out',
                                        'focus:border-brand focus:ring-2 focus:ring-brand/20 focus:outline-none',
                                        'border-error focus:border-error focus:ring-error/20' => $errors->has('customer_pesel'),
                                        'border-border hover:border-border-strong' => !$errors->has('customer_pesel'),
                                    ])
                                    placeholder="00000000000"
                                >
                                @error('customer_pesel')
                                    <p id="customer_pesel-error" role="alert" class="text-sm text-error mt-1">{{ $message }}</p>
                                @enderror
                                <p id="customer_pesel-hint" class="text-xs text-text-muted mt-1 leading-relaxed">
                                    @if($peselRequired)
                                        Wymagany do zawarcia umowy najmu.
                                    @else
                                        Opcjonalny — podaj tylko jeśli chcesz go dołączyć do umowy najmu. Nie jest drukowany na protokole wydania/zwrotu sprzętu.
                                    @endif
                                </p>
                            </div>

                            {{-- Subheading: Adres --}}
                            <div class="pt-2">
                                <h3 class="text-sm font-semibold text-text-primary mb-4 pb-3 border-b border-border">
                                    Adres do umowy
                                </h3>

                                <div class="space-y-5">

                                    {{-- Row: Ulica | Nr domu | Nr mieszkania --}}
                                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-5">

                                        <div class="sm:col-span-1 space-y-1.5">
                                            <label for="customer_street" class="block text-sm font-medium text-text-primary">
                                                Ulica
                                                <span class="text-error ml-0.5" aria-hidden="true">*</span>
                                            </label>
                                            <input
                                                type="text"
                                                id="customer_street"
                                                name="customer_street"
                                                x-model="street"
                                                autocomplete="address-line1"
                                                aria-required="true"
                                                aria-invalid="{{ $errors->has('customer_street') ? 'true' : 'false' }}"
                                                aria-describedby="{{ $errors->has('customer_street') ? 'customer_street-error' : '' }}"
                                                @class([
                                                    'block w-full rounded-lg border bg-surface-raised px-3 py-2.5 text-sm text-text-primary placeholder:text-text-muted min-h-11',
                                                    'transition-colors duration-200 ease-out',
                                                    'focus:border-brand focus:ring-2 focus:ring-brand/20 focus:outline-none',
                                                    'border-error focus:border-error focus:ring-error/20' => $errors->has('customer_street'),
                                                    'border-border hover:border-border-strong' => !$errors->has('customer_street'),
                                                ])
                                                placeholder="ul. Przykładowa"
                                            >
                                            @error('customer_street')
                                                <p id="customer_street-error" role="alert" class="text-sm text-error mt-1">{{ $message }}</p>
                                            @enderror
                                        </div>

                                        <div class="space-y-1.5">
                                            <label for="customer_building" class="block text-sm font-medium text-text-primary">
                                                Nr domu
                                                <span class="text-error ml-0.5" aria-hidden="true">*</span>
                                            </label>
                                            <input
                                                type="text"
                                                id="customer_building"
                                                name="customer_building"
                                                x-model="building"
                                                aria-required="true"
                                                aria-invalid="{{ $errors->has('customer_building') ? 'true' : 'false' }}"
                                                aria-describedby="{{ $errors->has('customer_building') ? 'customer_building-error' : '' }}"
                                                @class([
                                                    'block w-full rounded-lg border bg-surface-raised px-3 py-2.5 text-sm text-text-primary placeholder:text-text-muted min-h-11',
                                                    'transition-colors duration-200 ease-out',
                                                    'focus:border-brand focus:ring-2 focus:ring-brand/20 focus:outline-none',
                                                    'border-error focus:border-error focus:ring-error/20' => $errors->has('customer_building'),
                                                    'border-border hover:border-border-strong' => !$errors->has('customer_building'),
                                                ])
                                                placeholder="12A"
                                            >
                                            @error('customer_building')
                                                <p id="customer_building-error" role="alert" class="text-sm text-error mt-1">{{ $message }}</p>
                                            @enderror
                                        </div>

                                        <div class="space-y-1.5">
                                            <label for="customer_apartment" class="block text-sm font-medium text-text-primary">
                                                Nr mieszkania
                                                <span class="text-text-muted text-xs font-normal ml-1">(opcjonalne)</span>
                                            </label>
                                            <input
                                                type="text"
                                                id="customer_apartment"
                                                name="customer_apartment"
                                                x-model="apartment"
                                                @class([
                                                    'block w-full rounded-lg border bg-surface-raised px-3 py-2.5 text-sm text-text-primary placeholder:text-text-muted min-h-11',
                                                    'transition-colors duration-200 ease-out',
                                                    'focus:border-brand focus:ring-2 focus:ring-brand/20 focus:outline-none',
                                                    'border-error focus:border-error focus:ring-error/20' => $errors->has('customer_apartment'),
                                                    'border-border hover:border-border-strong' => !$errors->has('customer_apartment'),
                                                ])
                                                placeholder="5"
                                            >
                                            @error('customer_apartment')
                                                <p id="customer_apartment-error" role="alert" class="text-sm text-error mt-1">{{ $message }}</p>
                                            @enderror
                                        </div>

                                    </div>

                                    {{-- Row: Kod pocztowy | Miasto --}}
                                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">

                                        <div class="space-y-1.5">
                                            <label for="customer_postal_code" class="block text-sm font-medium text-text-primary">
                                                Kod pocztowy
                                                <span class="text-error ml-0.5" aria-hidden="true">*</span>
                                            </label>
                                            <input
                                                type="text"
                                                id="customer_postal_code"
                                                name="customer_postal_code"
                                                x-model="postalCode"
                                                inputmode="numeric"
                                                maxlength="6"
                                                autocomplete="postal-code"
                                                aria-required="true"
                                                aria-invalid="{{ $errors->has('customer_postal_code') ? 'true' : 'false' }}"
                                                aria-describedby="{{ $errors->has('customer_postal_code') ? 'customer_postal_code-error' : '' }}"
                                                @class([
                                                    'block w-full rounded-lg border bg-surface-raised px-3 py-2.5 text-sm text-text-primary placeholder:text-text-muted min-h-11',
                                                    'transition-colors duration-200 ease-out',
                                                    'focus:border-brand focus:ring-2 focus:ring-brand/20 focus:outline-none',
                                                    'border-error focus:border-error focus:ring-error/20' => $errors->has('customer_postal_code'),
                                                    'border-border hover:border-border-strong' => !$errors->has('customer_postal_code'),
                                                ])
                                                placeholder="00-000"
                                            >
                                            @error('customer_postal_code')
                                                <p id="customer_postal_code-error" role="alert" class="text-sm text-error mt-1">{{ $message }}</p>
                                            @enderror
                                        </div>

                                        <div class="space-y-1.5">
                                            <label for="customer_city" class="block text-sm font-medium text-text-primary">
                                                Miasto
                                                <span class="text-error ml-0.5" aria-hidden="true">*</span>
                                            </label>
                                            <input
                                                type="text"
                                                id="customer_city"
                                                name="customer_city"
                                                x-model="city"
                                                autocomplete="address-level2"
                                                aria-required="true"
                                                aria-invalid="{{ $errors->has('customer_city') ? 'true' : 'false' }}"
                                                aria-describedby="{{ $errors->has('customer_city') ? 'customer_city-error' : '' }}"
                                                @class([
                                                    'block w-full rounded-lg border bg-surface-raised px-3 py-2.5 text-sm text-text-primary placeholder:text-text-muted min-h-11',
                                                    'transition-colors duration-200 ease-out',
                                                    'focus:border-brand focus:ring-2 focus:ring-brand/20 focus:outline-none',
                                                    'border-error focus:border-error focus:ring-error/20' => $errors->has('customer_city'),
                                                    'border-border hover:border-border-strong' => !$errors->has('customer_city'),
                                                ])
                                                placeholder="Warszawa"
                                            >
                                            @error('customer_city')
                                                <p id="customer_city-error" role="alert" class="text-sm text-error mt-1">{{ $message }}</p>
                                            @enderror
                                        </div>

                                    </div>

                                </div>
                            </div>

                        </div>
                    </x-ui.card>
                </section>

                {{-- ─── Invoice toggle for natural person (optional VAT) ─── --}}
                <section
                    aria-labelledby="invoice-np-heading"
                    x-show="customerType === 'natural_person'"
                    x-transition:enter="transition ease-out duration-200"
                    x-transition:enter-start="opacity-0 -translate-y-1"
                    x-transition:enter-end="opacity-100 translate-y-0"
                    x-transition:leave="transition ease-in duration-150"
                    x-transition:leave-start="opacity-100 translate-y-0"
                    x-transition:leave-end="opacity-0 -translate-y-1"
                >
                    <x-ui.card>
                        {{-- Toggle row --}}
                        <div class="flex items-start gap-3">
                            <div class="flex items-center h-5 mt-0.5">
                                <input
                                    type="checkbox"
                                    id="invoice_requested"
                                    name="invoice_requested"
                                    value="1"
                                    x-model="invoice"
                                    @checked(old('invoice_requested'))
                                    class="h-4 w-4 rounded border-border accent-brand text-brand
                                           transition-colors duration-200 ease-out
                                           focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50 focus-visible:ring-offset-2
                                           cursor-pointer"
                                    aria-controls="invoice-np-fields"
                                    :aria-expanded="invoice.toString()"
                                >
                            </div>
                            <div>
                                <label
                                    for="invoice_requested"
                                    class="text-sm font-medium text-text-primary cursor-pointer select-none"
                                    id="invoice-np-heading"
                                >
                                    Chcę fakturę VAT <span class="font-normal text-text-muted">(JDG / działalność)</span>
                                </label>
                                <p class="text-xs text-text-muted mt-0.5">
                                    Podaj NIP, aby otrzymać fakturę
                                </p>
                            </div>
                        </div>

                        {{-- Invoice NIP field (shown conditionally) --}}
                        <div
                            id="invoice-np-fields"
                            x-show="invoice"
                            x-transition:enter="transition ease-out duration-200"
                            x-transition:enter-start="opacity-0 -translate-y-2"
                            x-transition:enter-end="opacity-100 translate-y-0"
                            x-transition:leave="transition ease-in duration-150"
                            x-transition:leave-start="opacity-100 translate-y-0"
                            x-transition:leave-end="opacity-0 -translate-y-2"
                            role="group"
                            aria-label="NIP do faktury"
                        >
                            <div class="mt-5 pt-5 border-t border-border">
                                <div class="space-y-1.5">
                                    <label for="invoice_nip" class="block text-sm font-medium text-text-primary">
                                        NIP
                                        <span class="text-error ml-0.5" aria-hidden="true">*</span>
                                    </label>
                                    <input
                                        type="text"
                                        id="invoice_nip"
                                        name="invoice_nip"
                                        x-model="nip"
                                        inputmode="numeric"
                                        maxlength="10"
                                        aria-invalid="{{ $errors->has('invoice_nip') ? 'true' : 'false' }}"
                                        aria-describedby="{{ $errors->has('invoice_nip') ? 'invoice_nip-error' : '' }}"
                                        @class([
                                            'block w-full sm:max-w-xs rounded-lg border bg-surface-raised px-3 py-2.5 text-sm text-text-primary placeholder:text-text-muted font-mono min-h-11',
                                            'transition-colors duration-200 ease-out',
                                            'focus:border-brand focus:ring-2 focus:ring-brand/20 focus:outline-none',
                                            'border-error focus:border-error focus:ring-error/20' => $errors->has('invoice_nip'),
                                            'border-border hover:border-border-strong' => !$errors->has('invoice_nip'),
                                        ])
                                        placeholder="0000000000"
                                        :required="invoice"
                                    >
                                    @error('invoice_nip')
                                        <p id="invoice_nip-error" role="alert" class="text-sm text-error mt-1">{{ $message }}</p>
                                    @enderror
                                </div>
                            </div>
                        </div>
                    </x-ui.card>
                </section>

                {{-- ─── SECTION 2B: Dane firmy (business) ─── --}}
                <section
                    aria-labelledby="business-data-heading"
                    x-show="customerType === 'business'"
                    x-transition:enter="transition ease-out duration-200"
                    x-transition:enter-start="opacity-0 -translate-y-1"
                    x-transition:enter-end="opacity-100 translate-y-0"
                    x-transition:leave="transition ease-in duration-150"
                    x-transition:leave-start="opacity-100 translate-y-0"
                    x-transition:leave-end="opacity-0 -translate-y-1"
                >
                    <x-ui.card>
                        <h2 id="business-data-heading" class="text-base font-semibold text-text-primary mb-6">
                            Dane firmy
                        </h2>

                        <div class="space-y-5">

                            {{-- Nazwa firmy (full width) --}}
                            <div class="space-y-1.5">
                                <label for="invoice_company_name" class="block text-sm font-medium text-text-primary">
                                    Nazwa firmy
                                    <span class="text-error ml-0.5" aria-hidden="true">*</span>
                                </label>
                                <input
                                    type="text"
                                    id="invoice_company_name"
                                    name="invoice_company_name"
                                    x-model="companyName"
                                    autocomplete="organization"
                                    aria-required="true"
                                    aria-invalid="{{ $errors->has('invoice_company_name') ? 'true' : 'false' }}"
                                    aria-describedby="{{ $errors->has('invoice_company_name') ? 'invoice_company_name-error' : '' }}"
                                    @class([
                                        'block w-full rounded-lg border bg-surface-raised px-3 py-2.5 text-sm text-text-primary placeholder:text-text-muted min-h-11',
                                        'transition-colors duration-200 ease-out',
                                        'focus:border-brand focus:ring-2 focus:ring-brand/20 focus:outline-none',
                                        'border-error focus:border-error focus:ring-error/20' => $errors->has('invoice_company_name'),
                                        'border-border hover:border-border-strong' => !$errors->has('invoice_company_name'),
                                    ])
                                    placeholder="Acme Sp. z o.o."
                                >
                                @error('invoice_company_name')
                                    <p id="invoice_company_name-error" role="alert" class="text-sm text-error mt-1">{{ $message }}</p>
                                @enderror
                            </div>

                            {{-- Row: NIP | REGON --}}
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">

                                <div class="space-y-1.5">
                                    <label for="business_invoice_nip" class="block text-sm font-medium text-text-primary">
                                        NIP
                                        <span class="text-error ml-0.5" aria-hidden="true">*</span>
                                    </label>
                                    <input
                                        type="text"
                                        id="business_invoice_nip"
                                        name="invoice_nip"
                                        x-model="nip"
                                        inputmode="numeric"
                                        maxlength="10"
                                        aria-required="true"
                                        aria-invalid="{{ $errors->has('invoice_nip') ? 'true' : 'false' }}"
                                        aria-describedby="{{ $errors->has('invoice_nip') ? 'invoice_nip_b-error' : '' }}"
                                        @class([
                                            'block w-full rounded-lg border bg-surface-raised px-3 py-2.5 text-sm text-text-primary placeholder:text-text-muted font-mono min-h-11',
                                            'transition-colors duration-200 ease-out',
                                            'focus:border-brand focus:ring-2 focus:ring-brand/20 focus:outline-none',
                                            'border-error focus:border-error focus:ring-error/20' => $errors->has('invoice_nip'),
                                            'border-border hover:border-border-strong' => !$errors->has('invoice_nip'),
                                        ])
                                        placeholder="0000000000"
                                    >
                                    @error('invoice_nip')
                                        <p id="invoice_nip_b-error" role="alert" class="text-sm text-error mt-1">{{ $message }}</p>
                                    @enderror
                                </div>

                                <div class="space-y-1.5">
                                    <label for="company_regon" class="block text-sm font-medium text-text-primary">
                                        REGON
                                        <span class="text-error ml-0.5" aria-hidden="true">*</span>
                                    </label>
                                    <input
                                        type="text"
                                        id="company_regon"
                                        name="company_regon"
                                        x-model="regon"
                                        inputmode="numeric"
                                        maxlength="14"
                                        aria-required="true"
                                        aria-invalid="{{ $errors->has('company_regon') ? 'true' : 'false' }}"
                                        aria-describedby="{{ $errors->has('company_regon') ? 'company_regon-error' : '' }}"
                                        @class([
                                            'block w-full rounded-lg border bg-surface-raised px-3 py-2.5 text-sm text-text-primary placeholder:text-text-muted font-mono min-h-11',
                                            'transition-colors duration-200 ease-out',
                                            'focus:border-brand focus:ring-2 focus:ring-brand/20 focus:outline-none',
                                            'border-error focus:border-error focus:ring-error/20' => $errors->has('company_regon'),
                                            'border-border hover:border-border-strong' => !$errors->has('company_regon'),
                                        ])
                                        placeholder="000000000"
                                    >
                                    @error('company_regon')
                                        <p id="company_regon-error" role="alert" class="text-sm text-error mt-1">{{ $message }}</p>
                                    @enderror
                                </div>

                            </div>

                            {{-- KRS / CEIDG (optional, full width) --}}
                            <div class="space-y-1.5">
                                <label for="company_krs" class="block text-sm font-medium text-text-primary">
                                    KRS / nr CEIDG
                                    <span class="text-text-muted text-xs font-normal ml-1">(opcjonalne)</span>
                                </label>
                                <input
                                    type="text"
                                    id="company_krs"
                                    name="company_krs"
                                    x-model="krs"
                                    aria-invalid="{{ $errors->has('company_krs') ? 'true' : 'false' }}"
                                    aria-describedby="{{ $errors->has('company_krs') ? 'company_krs-error' : '' }}"
                                    @class([
                                        'block w-full sm:max-w-sm rounded-lg border bg-surface-raised px-3 py-2.5 text-sm text-text-primary placeholder:text-text-muted font-mono min-h-11',
                                        'transition-colors duration-200 ease-out',
                                        'focus:border-brand focus:ring-2 focus:ring-brand/20 focus:outline-none',
                                        'border-error focus:border-error focus:ring-error/20' => $errors->has('company_krs'),
                                        'border-border hover:border-border-strong' => !$errors->has('company_krs'),
                                    ])
                                    placeholder="0000000000"
                                >
                                @error('company_krs')
                                    <p id="company_krs-error" role="alert" class="text-sm text-error mt-1">{{ $message }}</p>
                                @enderror
                            </div>

                            {{-- Row: Email | Telefon --}}
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">

                                <div class="space-y-1.5">
                                    <label for="business_customer_email" class="block text-sm font-medium text-text-primary">
                                        Adres e-mail
                                        <span class="text-error ml-0.5" aria-hidden="true">*</span>
                                    </label>
                                    <input
                                        type="email"
                                        id="business_customer_email"
                                        name="customer_email"
                                        x-model="email"
                                        autocomplete="email"
                                        aria-required="true"
                                        aria-invalid="{{ $errors->has('customer_email') ? 'true' : 'false' }}"
                                        aria-describedby="{{ $errors->has('customer_email') ? 'customer_email_b-error' : 'customer_email_b-hint' }}"
                                        @class([
                                            'block w-full rounded-lg border bg-surface-raised px-3 py-2.5 text-sm text-text-primary placeholder:text-text-muted min-h-11',
                                            'transition-colors duration-200 ease-out',
                                            'focus:border-brand focus:ring-2 focus:ring-brand/20 focus:outline-none',
                                            'border-error focus:border-error focus:ring-error/20' => $errors->has('customer_email'),
                                            'border-border hover:border-border-strong' => !$errors->has('customer_email'),
                                        ])
                                        placeholder="biuro@firma.pl"
                                    >
                                    @error('customer_email')
                                        <p id="customer_email_b-error" role="alert" class="text-sm text-error mt-1">{{ $message }}</p>
                                    @else
                                        <p id="customer_email_b-hint" class="text-xs text-text-muted mt-1">
                                            Potwierdzenie zamówienia zostanie wysłane na ten adres.
                                        </p>
                                    @enderror
                                </div>

                                <div class="space-y-1.5">
                                    <label for="business_customer_phone" class="block text-sm font-medium text-text-primary">
                                        Telefon
                                        <span class="text-error ml-0.5" aria-hidden="true">*</span>
                                    </label>
                                    <input
                                        type="tel"
                                        id="business_customer_phone"
                                        name="customer_phone"
                                        x-model="phone"
                                        autocomplete="tel"
                                        aria-required="true"
                                        aria-invalid="{{ $errors->has('customer_phone') ? 'true' : 'false' }}"
                                        aria-describedby="{{ $errors->has('customer_phone') ? 'customer_phone_b-error' : '' }}"
                                        @class([
                                            'block w-full rounded-lg border bg-surface-raised px-3 py-2.5 text-sm text-text-primary placeholder:text-text-muted min-h-11',
                                            'transition-colors duration-200 ease-out',
                                            'focus:border-brand focus:ring-2 focus:ring-brand/20 focus:outline-none',
                                            'border-error focus:border-error focus:ring-error/20' => $errors->has('customer_phone'),
                                            'border-border hover:border-border-strong' => !$errors->has('customer_phone'),
                                        ])
                                        placeholder="+48 22 000 00 00"
                                    >
                                    @error('customer_phone')
                                        <p id="customer_phone_b-error" role="alert" class="text-sm text-error mt-1">{{ $message }}</p>
                                    @enderror
                                </div>

                            </div>

                            {{-- Subheading: Adres siedziby --}}
                            <div class="pt-2">
                                <h3 class="text-sm font-semibold text-text-primary mb-4 pb-3 border-b border-border">
                                    Adres siedziby
                                </h3>

                                <div class="space-y-5">

                                    {{-- Row: Ulica | Nr domu --}}
                                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">

                                        <div class="space-y-1.5">
                                            <label for="invoice_street" class="block text-sm font-medium text-text-primary">
                                                Ulica
                                                <span class="text-error ml-0.5" aria-hidden="true">*</span>
                                            </label>
                                            <input
                                                type="text"
                                                id="invoice_street"
                                                name="invoice_street"
                                                x-model="billingStreet"
                                                autocomplete="street-address"
                                                aria-required="true"
                                                aria-invalid="{{ $errors->has('invoice_street') ? 'true' : 'false' }}"
                                                aria-describedby="{{ $errors->has('invoice_street') ? 'invoice_street-error' : '' }}"
                                                @class([
                                                    'block w-full rounded-lg border bg-surface-raised px-3 py-2.5 text-sm text-text-primary placeholder:text-text-muted min-h-11',
                                                    'transition-colors duration-200 ease-out',
                                                    'focus:border-brand focus:ring-2 focus:ring-brand/20 focus:outline-none',
                                                    'border-error focus:border-error focus:ring-error/20' => $errors->has('invoice_street'),
                                                    'border-border hover:border-border-strong' => !$errors->has('invoice_street'),
                                                ])
                                                placeholder="ul. Przykładowa"
                                            >
                                            @error('invoice_street')
                                                <p id="invoice_street-error" role="alert" class="text-sm text-error mt-1">{{ $message }}</p>
                                            @enderror
                                        </div>

                                        <div class="space-y-1.5">
                                            <label for="invoice_street_number" class="block text-sm font-medium text-text-primary">
                                                Nr domu
                                                <span class="text-error ml-0.5" aria-hidden="true">*</span>
                                            </label>
                                            <input
                                                type="text"
                                                id="invoice_street_number"
                                                name="invoice_street_number"
                                                x-model="billingBuilding"
                                                aria-required="true"
                                                aria-invalid="{{ $errors->has('invoice_street_number') ? 'true' : 'false' }}"
                                                aria-describedby="{{ $errors->has('invoice_street_number') ? 'invoice_street_number-error' : '' }}"
                                                @class([
                                                    'block w-full rounded-lg border bg-surface-raised px-3 py-2.5 text-sm text-text-primary placeholder:text-text-muted min-h-11',
                                                    'transition-colors duration-200 ease-out',
                                                    'focus:border-brand focus:ring-2 focus:ring-brand/20 focus:outline-none',
                                                    'border-error focus:border-error focus:ring-error/20' => $errors->has('invoice_street_number'),
                                                    'border-border hover:border-border-strong' => !$errors->has('invoice_street_number'),
                                                ])
                                                placeholder="12A"
                                            >
                                            @error('invoice_street_number')
                                                <p id="invoice_street_number-error" role="alert" class="text-sm text-error mt-1">{{ $message }}</p>
                                            @enderror
                                        </div>

                                    </div>

                                    {{-- Row: Kod pocztowy | Miasto --}}
                                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">

                                        <div class="space-y-1.5">
                                            <label for="invoice_postal_code" class="block text-sm font-medium text-text-primary">
                                                Kod pocztowy
                                                <span class="text-error ml-0.5" aria-hidden="true">*</span>
                                            </label>
                                            <input
                                                type="text"
                                                id="invoice_postal_code"
                                                name="invoice_postal_code"
                                                x-model="billingPostal"
                                                inputmode="numeric"
                                                maxlength="6"
                                                autocomplete="postal-code"
                                                aria-required="true"
                                                aria-invalid="{{ $errors->has('invoice_postal_code') ? 'true' : 'false' }}"
                                                aria-describedby="{{ $errors->has('invoice_postal_code') ? 'invoice_postal_code-error' : '' }}"
                                                @class([
                                                    'block w-full rounded-lg border bg-surface-raised px-3 py-2.5 text-sm text-text-primary placeholder:text-text-muted min-h-11',
                                                    'transition-colors duration-200 ease-out',
                                                    'focus:border-brand focus:ring-2 focus:ring-brand/20 focus:outline-none',
                                                    'border-error focus:border-error focus:ring-error/20' => $errors->has('invoice_postal_code'),
                                                    'border-border hover:border-border-strong' => !$errors->has('invoice_postal_code'),
                                                ])
                                                placeholder="00-000"
                                            >
                                            @error('invoice_postal_code')
                                                <p id="invoice_postal_code-error" role="alert" class="text-sm text-error mt-1">{{ $message }}</p>
                                            @enderror
                                        </div>

                                        <div class="space-y-1.5">
                                            <label for="invoice_city" class="block text-sm font-medium text-text-primary">
                                                Miasto
                                                <span class="text-error ml-0.5" aria-hidden="true">*</span>
                                            </label>
                                            <input
                                                type="text"
                                                id="invoice_city"
                                                name="invoice_city"
                                                x-model="billingCity"
                                                autocomplete="address-level2"
                                                aria-required="true"
                                                aria-invalid="{{ $errors->has('invoice_city') ? 'true' : 'false' }}"
                                                aria-describedby="{{ $errors->has('invoice_city') ? 'invoice_city-error' : '' }}"
                                                @class([
                                                    'block w-full rounded-lg border bg-surface-raised px-3 py-2.5 text-sm text-text-primary placeholder:text-text-muted min-h-11',
                                                    'transition-colors duration-200 ease-out',
                                                    'focus:border-brand focus:ring-2 focus:ring-brand/20 focus:outline-none',
                                                    'border-error focus:border-error focus:ring-error/20' => $errors->has('invoice_city'),
                                                    'border-border hover:border-border-strong' => !$errors->has('invoice_city'),
                                                ])
                                                placeholder="Warszawa"
                                            >
                                            @error('invoice_city')
                                                <p id="invoice_city-error" role="alert" class="text-sm text-error mt-1">{{ $message }}</p>
                                            @enderror
                                        </div>

                                    </div>

                                </div>
                            </div>

                            {{-- Subheading: Osoba upoważniona --}}
                            <div class="pt-2">
                                <h3 class="text-sm font-semibold text-text-primary mb-4 pb-3 border-b border-border">
                                    Osoba upoważniona do podpisania umowy
                                </h3>

                                <div class="space-y-1.5">
                                    <label for="company_contact_name" class="block text-sm font-medium text-text-primary">
                                        Imię i nazwisko
                                        <span class="text-error ml-0.5" aria-hidden="true">*</span>
                                    </label>
                                    <input
                                        type="text"
                                        id="company_contact_name"
                                        name="company_contact_name"
                                        x-model="companyContactName"
                                        autocomplete="name"
                                        aria-required="true"
                                        aria-invalid="{{ $errors->has('company_contact_name') ? 'true' : 'false' }}"
                                        aria-describedby="{{ $errors->has('company_contact_name') ? 'company_contact_name-error' : '' }}"
                                        @class([
                                            'block w-full rounded-lg border bg-surface-raised px-3 py-2.5 text-sm text-text-primary placeholder:text-text-muted min-h-11',
                                            'transition-colors duration-200 ease-out',
                                            'focus:border-brand focus:ring-2 focus:ring-brand/20 focus:outline-none',
                                            'border-error focus:border-error focus:ring-error/20' => $errors->has('company_contact_name'),
                                            'border-border hover:border-border-strong' => !$errors->has('company_contact_name'),
                                        ])
                                        placeholder="Jan Kowalski"
                                    >
                                    @error('company_contact_name')
                                        <p id="company_contact_name-error" role="alert" class="text-sm text-error mt-1">{{ $message }}</p>
                                    @enderror
                                </div>

                                {{-- PESEL lub numer dowodu osoby podpisującej --}}
                                <div class="mt-4 space-y-1.5">
                                    <label for="signatory_id_number" class="block text-sm font-medium text-text-primary">
                                        PESEL lub numer dowodu osobistego
                                        <span class="text-error ml-0.5" aria-hidden="true">*</span>
                                    </label>
                                    <input
                                        type="text"
                                        id="signatory_id_number"
                                        name="signatory_id_number"
                                        x-model="signatoryIdNumber"
                                        inputmode="text"
                                        maxlength="20"
                                        aria-required="true"
                                        aria-invalid="{{ $errors->has('signatory_id_number') ? 'true' : 'false' }}"
                                        aria-describedby="signatory_id_number-hint{{ $errors->has('signatory_id_number') ? ' signatory_id_number-error' : '' }}"
                                        @class([
                                            'block w-full sm:max-w-xs rounded-lg border bg-surface-raised px-3 py-2.5 text-sm text-text-primary placeholder:text-text-muted font-mono min-h-11',
                                            'transition-colors duration-200 ease-out',
                                            'focus:border-brand focus:ring-2 focus:ring-brand/20 focus:outline-none',
                                            'border-error focus:border-error focus:ring-error/20' => $errors->has('signatory_id_number'),
                                            'border-border hover:border-border-strong' => !$errors->has('signatory_id_number'),
                                        ])
                                        placeholder="np. ABC123456 lub 12345678901"
                                    >
                                    <p id="signatory_id_number-hint" class="text-xs text-text-muted mt-1 leading-relaxed">
                                        Wymagane do zawarcia umowy najmu i ewentualnego dochodzenia roszczeń.
                                    </p>
                                    @error('signatory_id_number')
                                        <p id="signatory_id_number-error" role="alert" class="text-sm text-error mt-1">{{ $message }}</p>
                                    @enderror
                                </div>
                            </div>

                            {{-- Toggle: inna osoba odbierająca sprzęt --}}
                            <div class="pt-4 border-t border-border mt-2">
                                <div class="flex items-start gap-3">
                                    <div class="flex items-center h-5 mt-0.5">
                                        <input
                                            type="checkbox"
                                            id="different_pickup_person"
                                            x-model="differentPickupPerson"
                                            @change="if (!differentPickupPerson) { pickupPersonName = ''; pickupPersonIdNumber = ''; }"
                                            class="h-4 w-4 rounded border-border accent-brand text-brand
                                                   transition-colors duration-200 ease-out
                                                   focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50 focus-visible:ring-offset-2
                                                   cursor-pointer"
                                            :aria-expanded="differentPickupPerson.toString()"
                                            aria-controls="pickup-person-fields"
                                        >
                                    </div>
                                    <label for="different_pickup_person" class="text-sm font-medium text-text-primary cursor-pointer select-none">
                                        Sprzęt odbierze inna osoba niż podpisująca umowę
                                        <span class="block text-xs font-normal text-text-muted mt-0.5">
                                            Np. pracownik lub kierowca — podaj jej dane do protokołu wydania
                                        </span>
                                    </label>
                                </div>

                                <div
                                    id="pickup-person-fields"
                                    x-show="differentPickupPerson"
                                    x-transition:enter="transition ease-out duration-200"
                                    x-transition:enter-start="opacity-0 -translate-y-2"
                                    x-transition:enter-end="opacity-100 translate-y-0"
                                    x-transition:leave="transition ease-in duration-150"
                                    x-transition:leave-start="opacity-100 translate-y-0"
                                    x-transition:leave-end="opacity-0 -translate-y-2"
                                    role="group"
                                    aria-label="Dane osoby odbierającej sprzęt"
                                >
                                    <div class="mt-5 pt-5 border-t border-border space-y-4">

                                        {{-- Imię i nazwisko osoby odbierającej --}}
                                        <div class="space-y-1.5">
                                            <label for="pickup_person_name" class="block text-sm font-medium text-text-primary">
                                                Imię i nazwisko osoby odbierającej
                                                <span class="text-error ml-0.5" aria-hidden="true">*</span>
                                            </label>
                                            <input
                                                type="text"
                                                id="pickup_person_name"
                                                name="pickup_person_name"
                                                x-model="pickupPersonName"
                                                :disabled="!differentPickupPerson"
                                                autocomplete="off"
                                                aria-invalid="{{ $errors->has('pickup_person_name') ? 'true' : 'false' }}"
                                                aria-describedby="{{ $errors->has('pickup_person_name') ? 'pickup_person_name-error' : '' }}"
                                                @class([
                                                    'block w-full rounded-lg border bg-surface-raised px-3 py-2.5 text-sm text-text-primary placeholder:text-text-muted min-h-11',
                                                    'transition-colors duration-200 ease-out',
                                                    'focus:border-brand focus:ring-2 focus:ring-brand/20 focus:outline-none',
                                                    'border-error focus:border-error focus:ring-error/20' => $errors->has('pickup_person_name'),
                                                    'border-border hover:border-border-strong' => !$errors->has('pickup_person_name'),
                                                ])
                                                placeholder="Jan Kowalski"
                                            >
                                            @error('pickup_person_name')
                                                <p id="pickup_person_name-error" role="alert" class="text-sm text-error mt-1">{{ $message }}</p>
                                            @enderror
                                        </div>

                                        {{-- Numer dowodu osoby odbierającej --}}
                                        <div class="space-y-1.5">
                                            <label for="pickup_person_id_number" class="block text-sm font-medium text-text-primary">
                                                Numer dowodu osobistego
                                                <span class="text-error ml-0.5" aria-hidden="true">*</span>
                                            </label>
                                            <input
                                                type="text"
                                                id="pickup_person_id_number"
                                                name="pickup_person_id_number"
                                                x-model="pickupPersonIdNumber"
                                                :disabled="!differentPickupPerson"
                                                inputmode="text"
                                                maxlength="20"
                                                aria-invalid="{{ $errors->has('pickup_person_id_number') ? 'true' : 'false' }}"
                                                aria-describedby="{{ $errors->has('pickup_person_id_number') ? 'pickup_person_id_number-error' : '' }}"
                                                @class([
                                                    'block w-full sm:max-w-xs rounded-lg border bg-surface-raised px-3 py-2.5 text-sm text-text-primary placeholder:text-text-muted font-mono min-h-11',
                                                    'transition-colors duration-200 ease-out',
                                                    'focus:border-brand focus:ring-2 focus:ring-brand/20 focus:outline-none',
                                                    'border-error focus:border-error focus:ring-error/20' => $errors->has('pickup_person_id_number'),
                                                    'border-border hover:border-border-strong' => !$errors->has('pickup_person_id_number'),
                                                ])
                                                placeholder="np. ABC123456"
                                            >
                                            @error('pickup_person_id_number')
                                                <p id="pickup_person_id_number-error" role="alert" class="text-sm text-error mt-1">{{ $message }}</p>
                                            @enderror
                                        </div>

                                    </div>
                                </div>
                            </div>

                        </div>
                    </x-ui.card>
                </section>

                {{-- ─── SECTION 4: Zgody prawne ─── --}}
                <section aria-labelledby="consents-heading">
                    <div class="rounded-xl border border-border bg-surface-sunken shadow-xs p-6">
                        <div class="flex items-center gap-2.5 mb-5">
                            <x-heroicon-m-shield-check class="h-5 w-5 text-text-muted shrink-0" aria-hidden="true" />
                            <h2 id="consents-heading" class="text-base font-semibold text-text-primary">
                                Zgody i oświadczenia
                            </h2>
                        </div>

                        <div class="space-y-4">

                            {{-- Consent 1: Regulamin --}}
                            <div>
                                <label class="flex items-start gap-3 cursor-pointer group">
                                    <div class="flex items-center h-5 mt-0.5 shrink-0">
                                        <input
                                            type="checkbox"
                                            name="terms_accepted"
                                            value="1"
                                            x-model="termsAccepted"
                                            @checked(old('terms_accepted'))
                                            class="h-4 w-4 rounded border-border accent-brand text-brand
                                                   transition-colors duration-200 ease-out
                                                   focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50 focus-visible:ring-offset-2
                                                   cursor-pointer"
                                            aria-required="true"
                                            :aria-invalid="consentSubmitAttempted && !termsAccepted ? 'true' : 'false'"
                                            aria-describedby="terms-error"
                                        >
                                    </div>
                                    <span class="text-sm text-text-secondary leading-relaxed group-hover:text-text-primary transition-colors duration-150 [&_a]:text-brand [&_a]:underline [&_a]:underline-offset-2 hover:[&_a]:text-brand-hover">
                                        {!! str($checkoutSettings['terms_label'] ?? '')->sanitizeHtml() !!}
                                        <span class="text-error ml-0.5" aria-hidden="true">*</span>
                                    </span>
                                </label>
                                @error('terms_accepted')
                                    <p id="terms-error" role="alert" class="text-sm text-error mt-2 ml-7">{{ $message }}</p>
                                @else
                                    <p
                                        id="terms-error"
                                        role="alert"
                                        class="text-sm text-error mt-2 ml-7"
                                        x-show="consentSubmitAttempted && !termsAccepted"
                                        x-text="consentErrors.terms ?? ''"
                                        data-consent-error
                                    ></p>
                                @enderror
                            </div>

                            <div class="border-t border-border/60"></div>

                            {{-- Consent 2: RODO --}}
                            <div>
                                <label class="flex items-start gap-3 cursor-pointer group">
                                    <div class="flex items-center h-5 mt-0.5 shrink-0">
                                        <input
                                            type="checkbox"
                                            name="rodo_accepted"
                                            value="1"
                                            x-model="rodoAccepted"
                                            @checked(old('rodo_accepted'))
                                            class="h-4 w-4 rounded border-border accent-brand text-brand
                                                   transition-colors duration-200 ease-out
                                                   focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50 focus-visible:ring-offset-2
                                                   cursor-pointer"
                                            aria-required="true"
                                            :aria-invalid="consentSubmitAttempted && !rodoAccepted ? 'true' : 'false'"
                                            aria-describedby="rodo-error"
                                        >
                                    </div>
                                    <span class="text-sm text-text-secondary leading-relaxed group-hover:text-text-primary transition-colors duration-150 [&_a]:text-brand [&_a]:underline [&_a]:underline-offset-2 hover:[&_a]:text-brand-hover">
                                        {!! str($checkoutSettings['rodo_label'] ?? '')->sanitizeHtml() !!}
                                        <span class="text-error ml-0.5" aria-hidden="true">*</span>
                                    </span>
                                </label>
                                @error('rodo_accepted')
                                    <p id="rodo-error" role="alert" class="text-sm text-error mt-2 ml-7">{{ $message }}</p>
                                @else
                                    <p
                                        id="rodo-error"
                                        role="alert"
                                        class="text-sm text-error mt-2 ml-7"
                                        x-show="consentSubmitAttempted && !rodoAccepted"
                                        x-text="consentErrors.rodo ?? ''"
                                        data-consent-error
                                    ></p>
                                @enderror
                            </div>

                            <div class="border-t border-border/60"></div>

                            {{-- Consent 3: Withdrawal exclusion --}}
                            <div>
                                <label class="flex items-start gap-3 cursor-pointer group">
                                    <div class="flex items-center h-5 mt-0.5 shrink-0">
                                        <input
                                            type="checkbox"
                                            name="withdrawal_exclusion_accepted"
                                            value="1"
                                            x-model="withdrawalExclusionAccepted"
                                            @checked(old('withdrawal_exclusion_accepted'))
                                            class="h-4 w-4 rounded border-border accent-brand text-brand
                                                   transition-colors duration-200 ease-out
                                                   focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50 focus-visible:ring-offset-2
                                                   cursor-pointer"
                                            aria-required="true"
                                            :aria-invalid="consentSubmitAttempted && !withdrawalExclusionAccepted ? 'true' : 'false'"
                                            aria-describedby="withdrawal-error"
                                        >
                                    </div>
                                    <span class="text-sm text-text-secondary leading-relaxed group-hover:text-text-primary transition-colors duration-150 [&_a]:text-brand [&_a]:underline [&_a]:underline-offset-2 hover:[&_a]:text-brand-hover">
                                        {!! str($checkoutSettings['withdrawal_label'] ?? '')->sanitizeHtml() !!}
                                        <span class="text-error ml-0.5" aria-hidden="true">*</span>
                                    </span>
                                </label>
                                @error('withdrawal_exclusion_accepted')
                                    <p id="withdrawal-error" role="alert" class="text-sm text-error mt-2 ml-7">{{ $message }}</p>
                                @else
                                    <p
                                        id="withdrawal-error"
                                        role="alert"
                                        class="text-sm text-error mt-2 ml-7"
                                        x-show="consentSubmitAttempted && !withdrawalExclusionAccepted"
                                        x-text="consentErrors.withdrawal ?? ''"
                                        data-consent-error
                                    ></p>
                                @enderror
                            </div>

                        </div>
                    </div>
                </section>

                {{-- ─── SECTION 5: Zapisz do profilu ─── --}}
                @auth
                    <section aria-label="Zapisz dane do profilu">
                        <x-ui.card :padding="true">
                            <label class="flex items-start gap-3 cursor-pointer group">
                                <div class="flex items-center h-5 mt-0.5 shrink-0">
                                    <input
                                        type="checkbox"
                                        name="save_to_profile"
                                        value="1"
                                        x-model="saveToProfile"
                                        @checked(old('save_to_profile'))
                                        class="h-4 w-4 rounded border-border accent-brand text-brand
                                               transition-colors duration-200 ease-out
                                               focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50 focus-visible:ring-offset-2
                                               cursor-pointer"
                                    >
                                </div>
                                <div>
                                    <span class="text-sm font-medium text-text-primary group-hover:text-text-primary transition-colors duration-150 cursor-pointer select-none">
                                        Zapisz dane do profilu na przyszłość
                                    </span>
                                    <p class="text-xs text-text-muted mt-0.5">
                                        Dane zostaną zapisane i automatycznie uzupełnione przy następnym zamówieniu.
                                    </p>
                                </div>
                            </label>
                        </x-ui.card>
                    </section>
                @endauth

            </div>

            {{-- ── Right column: order summary + CTA ── --}}
            <aside aria-label="Podsumowanie i płatność">
                <x-ui.card class="sticky top-6">

                    <h2 class="text-base font-semibold text-text-primary mb-4">
                        Twoje zamówienie
                    </h2>

                    {{-- Items list --}}
                    <ul class="space-y-3 text-sm" aria-label="Pozycje zamówienia">
                        @foreach($cart->items as $item)
                            <li class="flex gap-3">
                                {{-- Thumbnail --}}
                                <div class="shrink-0 w-10 h-10 rounded-lg bg-surface-sunken overflow-hidden">
                                    @if($item->service->featured_image)
                                        <img
                                            src="{{ Storage::url($item->service->featured_image) }}"
                                            alt="{{ $item->service->name }}"
                                            class="w-full h-full object-cover"
                                            loading="lazy"
                                            decoding="async"
                                        >
                                    @else
                                        <div class="w-full h-full flex items-center justify-center">
                                            <x-heroicon-o-photo class="h-5 w-5 text-text-muted" aria-hidden="true" />
                                        </div>
                                    @endif
                                </div>

                                {{-- Info --}}
                                <div class="flex-1 min-w-0">
                                    <p class="font-medium text-text-primary truncate">{{ $item->service->name }}</p>
                                    <p class="text-text-muted text-xs mt-0.5">
                                        <time datetime="{{ $item->start_date }}">{{ \Carbon\Carbon::parse($item->start_date)->format('d.m.Y') }}</time>
                                        <span aria-hidden="true"> – </span>
                                        <time datetime="{{ $item->end_date }}">{{ \Carbon\Carbon::parse($item->end_date)->format('d.m.Y') }}</time>
                                        @if($item->quantity > 1)
                                            &nbsp;&middot;&nbsp;{{ $item->quantity }}&thinsp;szt.
                                        @endif
                                    </p>
                                </div>

                                {{-- Price --}}
                                <div class="shrink-0 font-medium text-text-primary tabular-nums">
                                    {{ number_format($item->total_price, 2, ',', ' ') }}&nbsp;zł
                                </div>
                            </li>
                        @endforeach
                    </ul>

                    {{-- Separator + rental total --}}
                    <div class="mt-4 pt-4 border-t border-border">
                        <div class="flex justify-between items-baseline gap-3">
                            <span class="text-sm font-medium text-text-secondary">Razem za wynajem</span>
                            <span class="text-xl font-bold text-text-primary tabular-nums">
                                {{ number_format($cart->items->sum('total_price'), 2, ',', ' ') }}&nbsp;zł
                            </span>
                        </div>
                        <p class="mt-1 text-xs text-text-muted">Ceny brutto, w tym VAT {{ app(\App\Support\Settings\SettingsManager::class)->vatRate() }}%</p>
                    </div>

                    {{-- Kaucja (conditionally shown) — $depositTotal computed once in CheckoutController::show() --}}
                    @if($depositTotal > 0)
                        <div class="mt-4 pt-4 border-t border-border">
                            <div class="flex justify-between items-baseline gap-3">
                                <span class="text-sm text-text-secondary">Kaucja zwrotna <span class="text-text-muted">(przy odbiorze)</span></span>
                                <span class="text-sm font-semibold text-text-primary tabular-nums">
                                    {{ number_format($depositTotal, 2, ',', ' ') }}&nbsp;zł
                                </span>
                            </div>
                            <p class="mt-1.5 text-xs text-text-muted leading-relaxed [&_a]:text-brand [&_a]:underline [&_a]:underline-offset-2">
                                {!! str($checkoutSettings['deposit_policy_note'] ?? '')->sanitizeHtml() !!}
                            </p>
                        </div>
                    @endif

                    {{-- Sposób rozliczenia — only shown when the tenant offers a real choice --}}
                    @if(count($availableSettlementMethods) > 1)
                        <fieldset class="mt-4 pt-4 border-t border-border">
                            <legend class="text-xs font-semibold text-text-secondary uppercase tracking-wider mb-2.5">
                                Sposób rozliczenia
                            </legend>
                            <div class="space-y-2">
                                <label class="flex items-start gap-3 rounded-lg border border-border p-3 cursor-pointer transition-colors duration-150
                                              hover:border-brand/50"
                                       :class="settlementMethod === 'online' ? 'border-brand ring-1 ring-brand/30 bg-brand/5' : ''">
                                    <input type="radio" name="settlement_method_choice" value="online" x-model="settlementMethod"
                                           class="mt-0.5 h-4 w-4 shrink-0 text-brand focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50">
                                    <span class="text-sm">
                                        <span class="block font-medium text-text-primary">Płatność online (Przelewy24)</span>
                                        <span class="block text-text-muted text-xs mt-0.5">Karta, BLIK lub przelew — od razu po złożeniu zamówienia.</span>
                                    </span>
                                </label>
                                <label class="flex items-start gap-3 rounded-lg border border-border p-3 cursor-pointer transition-colors duration-150
                                              hover:border-brand/50"
                                       :class="settlementMethod === 'offline' ? 'border-brand ring-1 ring-brand/30 bg-brand/5' : ''">
                                    <input type="radio" name="settlement_method_choice" value="offline" x-model="settlementMethod"
                                           class="mt-0.5 h-4 w-4 shrink-0 text-brand focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50">
                                    <span class="text-sm">
                                        <span class="block font-medium text-text-primary">Płatność przy odbiorze</span>
                                        <span class="block text-text-muted text-xs mt-0.5">Gotówka lub przelew przy odbiorze sprzętu. Rezerwacja ważna {{ $offlineReservationHoldHours }}&nbsp;h.</span>
                                    </span>
                                </label>
                            </div>
                        </fieldset>
                    @endif

                    {{-- Co się dzieje dalej? --}}
                    <div class="mt-4 pt-4 border-t border-border">
                        <h3 class="text-xs font-semibold text-text-secondary uppercase tracking-wider mb-2.5">
                            Co się dzieje dalej?
                        </h3>
                        <ol class="space-y-2 text-xs text-text-muted" role="list" x-show="settlementMethod !== 'offline'">
                            <li class="flex items-start gap-2">
                                <span class="shrink-0 mt-0.5 w-4 h-4 rounded-full bg-brand/10 text-brand font-semibold flex items-center justify-center text-[10px]" aria-hidden="true">1</span>
                                <span>Opłacasz zamówienie — otrzymasz e-mail z potwierdzeniem i szczegółami odbioru.</span>
                            </li>
                            <li class="flex items-start gap-2">
                                <span class="shrink-0 mt-0.5 w-4 h-4 rounded-full bg-brand/10 text-brand font-semibold flex items-center justify-center text-[10px]" aria-hidden="true">2</span>
                                <span>Administrator potwierdza dostępność sprzętu i kontaktuje się z Tobą w razie pytań.</span>
                            </li>
                            <li class="flex items-start gap-2">
                                <span class="shrink-0 mt-0.5 w-4 h-4 rounded-full bg-brand/10 text-brand font-semibold flex items-center justify-center text-[10px]" aria-hidden="true">3</span>
                                <span>Odbierasz sprzęt osobiście w umówionym terminie — miej przy sobie dokument tożsamości.</span>
                            </li>
                        </ol>
                        <ol class="space-y-2 text-xs text-text-muted" role="list" x-show="settlementMethod === 'offline'" x-cloak>
                            <li class="flex items-start gap-2">
                                <span class="shrink-0 mt-0.5 w-4 h-4 rounded-full bg-brand/10 text-brand font-semibold flex items-center justify-center text-[10px]" aria-hidden="true">1</span>
                                <span>Rezerwujemy sprzęt dla Ciebie na {{ $offlineReservationHoldHours }}&nbsp;h — otrzymasz e-mail z potwierdzeniem rezerwacji.</span>
                            </li>
                            <li class="flex items-start gap-2">
                                <span class="shrink-0 mt-0.5 w-4 h-4 rounded-full bg-brand/10 text-brand font-semibold flex items-center justify-center text-[10px]" aria-hidden="true">2</span>
                                <span>Odbierasz sprzęt osobiście w umówionym terminie i płacisz gotówką lub przelewem — miej przy sobie dokument tożsamości.</span>
                            </li>
                            <li class="flex items-start gap-2">
                                <span class="shrink-0 mt-0.5 w-4 h-4 rounded-full bg-brand/10 text-brand font-semibold flex items-center justify-center text-[10px]" aria-hidden="true">3</span>
                                <span>Jeśli nie odbierzesz sprzętu w tym czasie, rezerwacja zostanie automatycznie anulowana.</span>
                            </li>
                        </ol>
                    </div>

                    {{-- Submit CTA --}}
                    <div class="mt-6">
                        <button
                            type="submit"
                            class="w-full inline-flex items-center justify-center gap-2.5
                                   min-h-[48px] px-6 py-3 rounded-lg
                                   bg-brand text-text-inverse text-base font-medium
                                   shadow-sm
                                   hover:bg-brand-hover
                                   active:scale-[0.98]
                                   transition-all duration-200 ease-out
                                   focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50 focus-visible:ring-offset-2
                                   cursor-pointer select-none"
                            aria-describedby="payment-notice"
                        >
                            {{-- Przelewy24 icon (P24 branded mark — inline SVG for reliability) --}}
                            <svg
                                x-show="settlementMethod !== 'offline'"
                                viewBox="0 0 24 24"
                                class="h-5 w-5 shrink-0"
                                fill="currentColor"
                                aria-hidden="true"
                                focusable="false"
                            >
                                <path d="M12 2C6.477 2 2 6.477 2 12s4.477 10 10 10 10-4.477 10-10S17.523 2 12 2zm-.5 14.5V13H8l5-7.5V11h3.5L12 16.5z"/>
                            </svg>
                            <span x-show="settlementMethod !== 'offline'">Zamawiam i płacę {{ number_format($cart->items->sum('total_price'), 2, ',', ' ') }}&nbsp;zł</span>
                            <span x-show="settlementMethod === 'offline'" x-cloak>Rezerwuję — zapłacę przy odbiorze</span>
                        </button>
                        <p id="payment-notice" class="mt-3 text-xs text-text-muted text-center">
                            <span x-show="settlementMethod !== 'offline'">Zostaniesz przekierowany do bezpiecznej bramki płatności.</span>
                            <span x-show="settlementMethod === 'offline'" x-cloak>Sprzęt zostanie zarezerwowany, płatność nastąpi przy odbiorze.</span>
                        </p>
                    </div>

                    {{-- Back to cart --}}
                    <div class="mt-3 text-center">
                        <a
                            href="{{ route('cart.show') }}"
                            class="text-sm text-text-muted hover:text-brand transition-colors duration-200
                                   focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50 rounded"
                        >
                            Wróć do koszyka
                        </a>
                    </div>

                </x-ui.card>
            </aside>

        </div>

    </form>

    {{-- DEV: fake payment bypass — MUST be outside main <form> (nested forms are invalid HTML) --}}
    @if(! app()->isProduction())
        <div class="max-w-5xl mx-auto px-4 sm:px-6 pb-4">
            <div class="flex justify-end">
                <div class="w-full lg:w-80">
                    <form method="POST" action="{{ route('dev.fake-pay') }}">
                        @csrf
                        <button
                            type="submit"
                            class="w-full py-3 px-4 rounded-xl border-2 border-dashed border-amber-400
                                   bg-amber-50 text-amber-800 font-medium text-sm
                                   hover:bg-amber-100 transition-colors duration-200 cursor-pointer"
                        >
                            &#9889; [DEV] Zapłać testowo — pomiń Przelewy24
                        </button>
                    </form>
                </div>
            </div>
        </div>
    @endif
</x-layout.section>

@endsection

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
        x-data="{
            invoice: {{ old('invoice_requested') ? 'true' : 'false' }}
        }"
        aria-label="Formularz zamówienia"
    >
        @csrf

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8 items-start">

            {{-- ── Left column: contact + invoice ── --}}
            <div class="lg:col-span-2 space-y-8">

                {{-- ─── Contact details ─── --}}
                <section aria-labelledby="contact-heading">
                    <x-ui.card>
                        <h2 id="contact-heading" class="text-base font-semibold text-text-primary mb-6">
                            Dane kontaktowe
                        </h2>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">

                            {{-- First name --}}
                            <div class="space-y-1.5">
                                <label for="customer_first_name" class="block text-sm font-medium text-text-primary">
                                    Imię
                                    <span class="text-error ml-0.5" aria-hidden="true">*</span>
                                </label>
                                <input
                                    type="text"
                                    id="customer_first_name"
                                    name="customer_first_name"
                                    value="{{ old('customer_first_name') }}"
                                    required
                                    autocomplete="given-name"
                                    aria-required="true"
                                    aria-invalid="{{ $errors->has('customer_first_name') ? 'true' : 'false' }}"
                                    aria-describedby="{{ $errors->has('customer_first_name') ? 'customer_first_name-error' : '' }}"
                                    @class([
                                        'block w-full rounded-lg border bg-surface-raised px-3 py-2.5 text-sm text-text-primary placeholder:text-text-muted min-h-[44px]',
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

                            {{-- Last name --}}
                            <div class="space-y-1.5">
                                <label for="customer_last_name" class="block text-sm font-medium text-text-primary">
                                    Nazwisko
                                    <span class="text-error ml-0.5" aria-hidden="true">*</span>
                                </label>
                                <input
                                    type="text"
                                    id="customer_last_name"
                                    name="customer_last_name"
                                    value="{{ old('customer_last_name') }}"
                                    required
                                    autocomplete="family-name"
                                    aria-required="true"
                                    aria-invalid="{{ $errors->has('customer_last_name') ? 'true' : 'false' }}"
                                    aria-describedby="{{ $errors->has('customer_last_name') ? 'customer_last_name-error' : '' }}"
                                    @class([
                                        'block w-full rounded-lg border bg-surface-raised px-3 py-2.5 text-sm text-text-primary placeholder:text-text-muted min-h-[44px]',
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
                                    value="{{ old('customer_email') }}"
                                    required
                                    autocomplete="email"
                                    aria-required="true"
                                    aria-invalid="{{ $errors->has('customer_email') ? 'true' : 'false' }}"
                                    aria-describedby="{{ $errors->has('customer_email') ? 'customer_email-error' : 'customer_email-hint' }}"
                                    @class([
                                        'block w-full rounded-lg border bg-surface-raised px-3 py-2.5 text-sm text-text-primary placeholder:text-text-muted min-h-[44px]',
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

                            {{-- Phone --}}
                            <div class="space-y-1.5">
                                <label for="customer_phone" class="block text-sm font-medium text-text-primary">
                                    Telefon
                                    <span class="text-error ml-0.5" aria-hidden="true">*</span>
                                </label>
                                <input
                                    type="tel"
                                    id="customer_phone"
                                    name="customer_phone"
                                    value="{{ old('customer_phone') }}"
                                    required
                                    autocomplete="tel"
                                    aria-required="true"
                                    aria-invalid="{{ $errors->has('customer_phone') ? 'true' : 'false' }}"
                                    aria-describedby="{{ $errors->has('customer_phone') ? 'customer_phone-error' : '' }}"
                                    @class([
                                        'block w-full rounded-lg border bg-surface-raised px-3 py-2.5 text-sm text-text-primary placeholder:text-text-muted min-h-[44px]',
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
                    </x-ui.card>
                </section>

                {{-- ─── Invoice section ─── --}}
                <section aria-labelledby="invoice-heading">
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
                                    class="h-4 w-4 rounded border-border text-brand
                                           transition-colors duration-200 ease-out
                                           focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50 focus-visible:ring-offset-2
                                           cursor-pointer"
                                    aria-controls="invoice-fields"
                                    :aria-expanded="invoice.toString()"
                                >
                            </div>
                            <div>
                                <label
                                    for="invoice_requested"
                                    class="text-sm font-medium text-text-primary cursor-pointer select-none"
                                    id="invoice-heading"
                                >
                                    Chcę otrzymać fakturę VAT
                                </label>
                                <p class="text-xs text-text-muted mt-0.5">
                                    Dane do faktury możesz podać poniżej
                                </p>
                            </div>
                        </div>

                        {{-- Invoice fields (shown conditionally) --}}
                        <div
                            id="invoice-fields"
                            x-show="invoice"
                            x-transition:enter="transition ease-out duration-200"
                            x-transition:enter-start="opacity-0 -translate-y-2"
                            x-transition:enter-end="opacity-100 translate-y-0"
                            x-transition:leave="transition ease-in duration-150"
                            x-transition:leave-start="opacity-100 translate-y-0"
                            x-transition:leave-end="opacity-0 -translate-y-2"
                            role="group"
                            aria-label="Dane do faktury"
                        >
                            <div class="mt-6 pt-6 border-t border-border grid grid-cols-1 sm:grid-cols-2 gap-5">

                                {{-- Company name — full width --}}
                                <div class="sm:col-span-2 space-y-1.5">
                                    <label for="invoice_company_name" class="block text-sm font-medium text-text-primary">
                                        Nazwa firmy
                                        <span class="text-error ml-0.5" aria-hidden="true">*</span>
                                    </label>
                                    <input
                                        type="text"
                                        id="invoice_company_name"
                                        name="invoice_company_name"
                                        value="{{ old('invoice_company_name') }}"
                                        autocomplete="organization"
                                        aria-invalid="{{ $errors->has('invoice_company_name') ? 'true' : 'false' }}"
                                        aria-describedby="{{ $errors->has('invoice_company_name') ? 'invoice_company_name-error' : '' }}"
                                        @class([
                                            'block w-full rounded-lg border bg-surface-raised px-3 py-2.5 text-sm text-text-primary placeholder:text-text-muted min-h-[44px]',
                                            'transition-colors duration-200 ease-out',
                                            'focus:border-brand focus:ring-2 focus:ring-brand/20 focus:outline-none',
                                            'border-error focus:border-error focus:ring-error/20' => $errors->has('invoice_company_name'),
                                            'border-border hover:border-border-strong' => !$errors->has('invoice_company_name'),
                                        ])
                                        placeholder="Acme Sp. z o.o."
                                        :required="invoice"
                                    >
                                    @error('invoice_company_name')
                                        <p id="invoice_company_name-error" role="alert" class="text-sm text-error mt-1">{{ $message }}</p>
                                    @enderror
                                </div>

                                {{-- NIP --}}
                                <div class="space-y-1.5">
                                    <label for="invoice_nip" class="block text-sm font-medium text-text-primary">
                                        NIP
                                        <span class="text-error ml-0.5" aria-hidden="true">*</span>
                                    </label>
                                    <input
                                        type="text"
                                        id="invoice_nip"
                                        name="invoice_nip"
                                        value="{{ old('invoice_nip') }}"
                                        inputmode="numeric"
                                        maxlength="13"
                                        aria-invalid="{{ $errors->has('invoice_nip') ? 'true' : 'false' }}"
                                        aria-describedby="{{ $errors->has('invoice_nip') ? 'invoice_nip-error' : '' }}"
                                        @class([
                                            'block w-full rounded-lg border bg-surface-raised px-3 py-2.5 text-sm text-text-primary placeholder:text-text-muted min-h-[44px]',
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

                                {{-- Street --}}
                                <div class="space-y-1.5">
                                    <label for="invoice_street" class="block text-sm font-medium text-text-primary">
                                        Ulica
                                        <span class="text-error ml-0.5" aria-hidden="true">*</span>
                                    </label>
                                    <input
                                        type="text"
                                        id="invoice_street"
                                        name="invoice_street"
                                        value="{{ old('invoice_street') }}"
                                        autocomplete="street-address"
                                        aria-invalid="{{ $errors->has('invoice_street') ? 'true' : 'false' }}"
                                        aria-describedby="{{ $errors->has('invoice_street') ? 'invoice_street-error' : '' }}"
                                        @class([
                                            'block w-full rounded-lg border bg-surface-raised px-3 py-2.5 text-sm text-text-primary placeholder:text-text-muted min-h-[44px]',
                                            'transition-colors duration-200 ease-out',
                                            'focus:border-brand focus:ring-2 focus:ring-brand/20 focus:outline-none',
                                            'border-error focus:border-error focus:ring-error/20' => $errors->has('invoice_street'),
                                            'border-border hover:border-border-strong' => !$errors->has('invoice_street'),
                                        ])
                                        placeholder="ul. Przykładowa"
                                        :required="invoice"
                                    >
                                    @error('invoice_street')
                                        <p id="invoice_street-error" role="alert" class="text-sm text-error mt-1">{{ $message }}</p>
                                    @enderror
                                </div>

                                {{-- Street number --}}
                                <div class="space-y-1.5">
                                    <label for="invoice_street_number" class="block text-sm font-medium text-text-primary">
                                        Numer
                                        <span class="text-error ml-0.5" aria-hidden="true">*</span>
                                    </label>
                                    <input
                                        type="text"
                                        id="invoice_street_number"
                                        name="invoice_street_number"
                                        value="{{ old('invoice_street_number') }}"
                                        aria-invalid="{{ $errors->has('invoice_street_number') ? 'true' : 'false' }}"
                                        aria-describedby="{{ $errors->has('invoice_street_number') ? 'invoice_street_number-error' : '' }}"
                                        @class([
                                            'block w-full rounded-lg border bg-surface-raised px-3 py-2.5 text-sm text-text-primary placeholder:text-text-muted min-h-[44px]',
                                            'transition-colors duration-200 ease-out',
                                            'focus:border-brand focus:ring-2 focus:ring-brand/20 focus:outline-none',
                                            'border-error focus:border-error focus:ring-error/20' => $errors->has('invoice_street_number'),
                                            'border-border hover:border-border-strong' => !$errors->has('invoice_street_number'),
                                        ])
                                        placeholder="12A"
                                        :required="invoice"
                                    >
                                    @error('invoice_street_number')
                                        <p id="invoice_street_number-error" role="alert" class="text-sm text-error mt-1">{{ $message }}</p>
                                    @enderror
                                </div>

                                {{-- Postal code --}}
                                <div class="space-y-1.5">
                                    <label for="invoice_postal_code" class="block text-sm font-medium text-text-primary">
                                        Kod pocztowy
                                        <span class="text-error ml-0.5" aria-hidden="true">*</span>
                                    </label>
                                    <input
                                        type="text"
                                        id="invoice_postal_code"
                                        name="invoice_postal_code"
                                        value="{{ old('invoice_postal_code') }}"
                                        inputmode="numeric"
                                        maxlength="6"
                                        autocomplete="postal-code"
                                        aria-invalid="{{ $errors->has('invoice_postal_code') ? 'true' : 'false' }}"
                                        aria-describedby="{{ $errors->has('invoice_postal_code') ? 'invoice_postal_code-error' : '' }}"
                                        @class([
                                            'block w-full rounded-lg border bg-surface-raised px-3 py-2.5 text-sm text-text-primary placeholder:text-text-muted min-h-[44px]',
                                            'transition-colors duration-200 ease-out',
                                            'focus:border-brand focus:ring-2 focus:ring-brand/20 focus:outline-none',
                                            'border-error focus:border-error focus:ring-error/20' => $errors->has('invoice_postal_code'),
                                            'border-border hover:border-border-strong' => !$errors->has('invoice_postal_code'),
                                        ])
                                        placeholder="00-000"
                                        :required="invoice"
                                    >
                                    @error('invoice_postal_code')
                                        <p id="invoice_postal_code-error" role="alert" class="text-sm text-error mt-1">{{ $message }}</p>
                                    @enderror
                                </div>

                                {{-- City --}}
                                <div class="space-y-1.5">
                                    <label for="invoice_city" class="block text-sm font-medium text-text-primary">
                                        Miasto
                                        <span class="text-error ml-0.5" aria-hidden="true">*</span>
                                    </label>
                                    <input
                                        type="text"
                                        id="invoice_city"
                                        name="invoice_city"
                                        value="{{ old('invoice_city') }}"
                                        autocomplete="address-level2"
                                        aria-invalid="{{ $errors->has('invoice_city') ? 'true' : 'false' }}"
                                        aria-describedby="{{ $errors->has('invoice_city') ? 'invoice_city-error' : '' }}"
                                        @class([
                                            'block w-full rounded-lg border bg-surface-raised px-3 py-2.5 text-sm text-text-primary placeholder:text-text-muted min-h-[44px]',
                                            'transition-colors duration-200 ease-out',
                                            'focus:border-brand focus:ring-2 focus:ring-brand/20 focus:outline-none',
                                            'border-error focus:border-error focus:ring-error/20' => $errors->has('invoice_city'),
                                            'border-border hover:border-border-strong' => !$errors->has('invoice_city'),
                                        ])
                                        placeholder="Warszawa"
                                        :required="invoice"
                                    >
                                    @error('invoice_city')
                                        <p id="invoice_city-error" role="alert" class="text-sm text-error mt-1">{{ $message }}</p>
                                    @enderror
                                </div>

                            </div>
                        </div>
                    </x-ui.card>
                </section>

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
                                    @if($item->service->getFirstMediaUrl('gallery'))
                                        <img
                                            src="{{ $item->service->getFirstMediaUrl('gallery') }}"
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

                    {{-- Separator + total --}}
                    <div class="mt-4 pt-4 border-t border-border">
                        <div class="flex justify-between items-baseline gap-3">
                            <span class="text-sm font-medium text-text-secondary">Razem</span>
                            <span class="text-xl font-bold text-text-primary tabular-nums">
                                {{ number_format($cart->items->sum('total_price'), 2, ',', ' ') }}&nbsp;zł
                            </span>
                        </div>
                        <p class="mt-1 text-xs text-text-muted">Ceny brutto, w tym VAT</p>
                    </div>

                    {{-- Przelewy24 CTA --}}
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
                                viewBox="0 0 24 24"
                                class="h-5 w-5 shrink-0"
                                fill="currentColor"
                                aria-hidden="true"
                                focusable="false"
                            >
                                <path d="M12 2C6.477 2 2 6.477 2 12s4.477 10 10 10 10-4.477 10-10S17.523 2 12 2zm-.5 14.5V13H8l5-7.5V11h3.5L12 16.5z"/>
                            </svg>
                            Zapłać przez Przelewy24
                        </button>
                        <p id="payment-notice" class="mt-3 text-xs text-text-muted text-center">
                            Zostaniesz przekierowany do bezpiecznej bramki płatności.
                        </p>
                    </div>

                    {{-- Back to cart --}}
                    <div class="mt-3 text-center">
                        <a
                            href="{{ route('cart.show') }}"
                            class="text-sm text-text-muted hover:text-text-secondary transition-colors duration-200
                                   focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50 rounded"
                        >
                            Wróć do koszyka
                        </a>
                    </div>

                </x-ui.card>
            </aside>

        </div>

    </form>
</x-layout.section>

@endsection

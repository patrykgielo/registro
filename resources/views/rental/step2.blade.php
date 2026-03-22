@extends('layouts.app')

@section('content')
<x-layout.section>
    <div class="max-w-2xl mx-auto">
        @include('rental._progress', ['current' => 2])

        {{-- Hold countdown timer --}}
        @if(isset($holdExpiresAt))
            <div x-data="{
                expiresAt: new Date('{{ $holdExpiresAt }}'),
                remaining: 0,
                expired: false,
                tick() {
                    this.remaining = Math.max(0, Math.floor((this.expiresAt - Date.now()) / 1000));
                    this.expired = this.remaining <= 0;
                    if (this.expired) window.location.href = '{{ route('rental.step1', $service) }}';
                },
                get minutes() { return Math.floor(this.remaining / 60); },
                get seconds() { return this.remaining % 60; },
                init() { this.tick(); setInterval(() => this.tick(), 1000); }
            }" x-show="!expired"
               class="mb-4 p-3 rounded-xl border text-sm flex items-center justify-between"
               :class="remaining <= 120 ? 'bg-danger/10 border-danger/20 text-danger' : 'bg-warning/10 border-warning/20 text-warning-dark'">
                <span class="flex items-center gap-1.5">
                    <x-heroicon-m-clock class="h-4 w-4 shrink-0" />
                    <span>Rezerwacja wygasa za</span>
                </span>
                <span class="font-mono font-semibold" x-text="String(minutes).padStart(2,'0') + ':' + String(seconds).padStart(2,'0')"></span>
            </div>
        @endif

        <x-ui.card>
            <h2 class="text-xl font-bold text-text-primary mb-6">Dane kontaktowe</h2>

            <form action="{{ route('rental.step2.store', $service) }}" method="POST" class="space-y-5">
                @csrf

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <x-ui.input
                        name="first_name"
                        label="Imię"
                        :value="old('first_name', $step2['first_name'] ?? '')"
                        :error="$errors->first('first_name')"
                        required
                    />
                    <x-ui.input
                        name="last_name"
                        label="Nazwisko"
                        :value="old('last_name', $step2['last_name'] ?? '')"
                        :error="$errors->first('last_name')"
                        required
                    />
                </div>

                <x-ui.input
                    type="email"
                    name="email"
                    label="Email"
                    icon="envelope"
                    :value="old('email', $step2['email'] ?? '')"
                    :error="$errors->first('email')"
                    required
                />

                <x-ui.input
                    type="tel"
                    name="phone"
                    label="Telefon"
                    icon="phone"
                    :value="old('phone', $step2['phone'] ?? '')"
                    :error="$errors->first('phone')"
                    placeholder="+48 500 123 456"
                    required
                />

                <x-ui.textarea
                    name="notes"
                    label="Uwagi (opcjonalnie)"
                    :hint="'Np. specjalne wymagania, pytania o sprzęt'"
                    rows="3"
                >{{ old('notes', $step2['notes'] ?? '') }}</x-ui.textarea>

                {{-- Invoice toggle --}}
                <div x-data="{ invoice: {{ old('invoice_requested', $step2['invoice_requested'] ?? false) ? 'true' : 'false' }} }">
                    <label class="flex items-center gap-3 cursor-pointer py-2">
                        <input type="checkbox" name="invoice_requested" value="1" x-model="invoice"
                               class="h-5 w-5 rounded border-border text-brand focus:ring-brand/50">
                        <span class="text-sm font-medium text-text-primary">Potrzebuję fakturę VAT</span>
                    </label>

                    <div x-show="invoice" x-transition class="mt-4 space-y-4 pl-8 border-l-2 border-brand/20">
                        <x-ui.input name="invoice_company_name" label="Nazwa firmy"
                            :value="old('invoice_company_name', $step2['invoice_company_name'] ?? '')"
                            :error="$errors->first('invoice_company_name')" />
                        <x-ui.input name="invoice_nip" label="NIP"
                            :value="old('invoice_nip', $step2['invoice_nip'] ?? '')"
                            :error="$errors->first('invoice_nip')"
                            placeholder="1234567890" />
                        <div class="grid grid-cols-3 gap-3">
                            <div class="col-span-2">
                                <x-ui.input name="invoice_street" label="Ulica"
                                    :value="old('invoice_street', $step2['invoice_street'] ?? '')"
                                    :error="$errors->first('invoice_street')" />
                            </div>
                            <x-ui.input name="invoice_street_number" label="Nr"
                                :value="old('invoice_street_number', $step2['invoice_street_number'] ?? '')"
                                :error="$errors->first('invoice_street_number')" />
                        </div>
                        <div class="grid grid-cols-3 gap-3">
                            <x-ui.input name="invoice_postal_code" label="Kod pocztowy"
                                :value="old('invoice_postal_code', $step2['invoice_postal_code'] ?? '')"
                                :error="$errors->first('invoice_postal_code')"
                                placeholder="00-000" />
                            <div class="col-span-2">
                                <x-ui.input name="invoice_city" label="Miasto"
                                    :value="old('invoice_city', $step2['invoice_city'] ?? '')"
                                    :error="$errors->first('invoice_city')" />
                            </div>
                        </div>
                    </div>
                </div>

                <div class="flex items-center justify-between pt-4">
                    <x-ui.button variant="ghost" href="{{ route('rental.step1', $service) }}" icon="arrow-left">
                        Wstecz
                    </x-ui.button>
                    <x-ui.button type="submit" icon-right="arrow-right">
                        Dalej
                    </x-ui.button>
                </div>
            </form>
        </x-ui.card>
    </div>
</x-layout.section>
@endsection

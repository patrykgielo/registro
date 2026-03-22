@extends('layouts.app')

@php
    $startDate = \Carbon\Carbon::parse($step1['start_date']);
    $endDate = \Carbon\Carbon::parse($step1['end_date']);
    $quantity = (int) $step1['quantity'];

    $unitPrice = (float) $service->price_per_day;
    if ($service->price_per_day_long && $service->price_threshold_days && $durationDays >= $service->price_threshold_days) {
        $unitPrice = (float) $service->price_per_day_long;
    }
    $totalPrice = $unitPrice * $durationDays * $quantity;
@endphp

@section('content')
<x-layout.section>
    <div class="max-w-2xl mx-auto">
        @include('rental._progress', ['current' => 3])

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

        <x-ui.card class="space-y-6">
            <h2 class="text-xl font-bold text-text-primary">Podsumowanie rezerwacji</h2>

            {{-- Item --}}
            <div class="flex items-center gap-4 pb-4 border-b border-border">
                @if($service->featured_image)
                    <x-media.image :src="$service->featured_image" :alt="$service->name" aspect="1/1" rounded="lg" class="w-16 h-16 shrink-0" />
                @endif
                <div>
                    <p class="font-semibold text-text-primary">{{ $service->name }}</p>
                    @if($service->brand)
                        <p class="text-sm text-text-muted">{{ $service->brand }}</p>
                    @endif
                </div>
            </div>

            {{-- Rental details --}}
            <div class="space-y-3 text-sm">
                <div class="flex justify-between">
                    <span class="text-text-secondary">Termin</span>
                    <span class="font-medium text-text-primary">{{ $startDate->format('d.m.Y') }} — {{ $endDate->format('d.m.Y') }}</span>
                </div>
                <div class="flex justify-between">
                    <span class="text-text-secondary">Czas trwania</span>
                    <span class="font-medium text-text-primary">{{ $durationDays }} {{ $durationDays === 1 ? 'dzień' : 'dni' }}</span>
                </div>
                <div class="flex justify-between">
                    <span class="text-text-secondary">Ilość</span>
                    <span class="font-medium text-text-primary">{{ $quantity }} szt.</span>
                </div>
                <div class="flex justify-between">
                    <span class="text-text-secondary">Stawka</span>
                    <span class="font-medium text-text-primary">{{ number_format($unitPrice, 2, ',', ' ') }} zł/dzień</span>
                </div>

                <x-ui.separator />

                <div class="flex justify-between text-base">
                    <span class="font-semibold text-text-primary">Koszt wypożyczenia</span>
                    <span class="font-bold text-text-primary">{{ number_format($totalPrice, 2, ',', ' ') }} zł</span>
                </div>

                @if($service->deposit_amount)
                    <div class="flex justify-between">
                        <span class="text-text-secondary">Kaucja zwrotna</span>
                        <span class="font-medium text-text-primary">{{ number_format($service->deposit_amount, 2, ',', ' ') }} zł</span>
                    </div>
                    <div class="flex justify-between text-base pt-2 border-t border-border">
                        <span class="font-semibold text-text-primary">Łącznie przy odbiorze</span>
                        <span class="font-bold text-brand">{{ number_format($totalPrice + (float) $service->deposit_amount, 2, ',', ' ') }} zł</span>
                    </div>
                @endif
            </div>

            <x-ui.separator />

            {{-- Contact summary --}}
            <div class="space-y-2 text-sm">
                <h3 class="font-semibold text-text-primary">Dane kontaktowe</h3>
                <p class="text-text-secondary">{{ $step2['first_name'] }} {{ $step2['last_name'] }}</p>
                <p class="text-text-secondary">{{ $step2['email'] }} &middot; {{ $step2['phone'] }}</p>
                @if(!empty($step2['notes']))
                    <p class="text-text-muted italic">„{{ $step2['notes'] }}"</p>
                @endif
                @if(!empty($step2['invoice_requested']))
                    <div class="mt-2 p-3 rounded-lg bg-surface-sunken text-xs">
                        <p class="font-medium">Faktura VAT: {{ $step2['invoice_company_name'] ?? '' }}</p>
                        <p>NIP: {{ $step2['invoice_nip'] ?? '' }}</p>
                    </div>
                @endif
            </div>

            {{-- Actions --}}
            <div class="flex items-center justify-between pt-4">
                <x-ui.button variant="ghost" href="{{ route('rental.step2', $service) }}" icon="arrow-left">
                    Wstecz
                </x-ui.button>

                <form action="{{ route('rental.confirm', $service) }}" method="POST">
                    @csrf
                    <x-ui.button type="submit" size="lg" icon-right="check">
                        Wyślij zapytanie
                    </x-ui.button>
                </form>
            </div>
        </x-ui.card>
    </div>
</x-layout.section>
@endsection

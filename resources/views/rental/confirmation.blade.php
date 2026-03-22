@extends('layouts.app')

@section('content')
<x-layout.section>
    <div class="max-w-lg mx-auto text-center">
        <div class="flex items-center justify-center w-16 h-16 rounded-full bg-success/10 mx-auto mb-6">
            <x-heroicon-m-check class="h-8 w-8 text-success" />
        </div>

        <h1 class="text-2xl font-bold text-text-primary mb-2">Zapytanie wysłane!</h1>
        <p class="text-text-secondary mb-8">
            Dziękujemy za zainteresowanie. Skontaktujemy się z Tobą w ciągu 24 godzin aby potwierdzić rezerwację.
        </p>

        @if($rental)
            <x-ui.card class="text-left mb-8">
                <div class="space-y-3 text-sm">
                    <div class="flex justify-between">
                        <span class="text-text-muted">Numer zapytania</span>
                        <span class="font-mono font-semibold text-text-primary">#RNT-{{ $rental->id }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-text-muted">Przedmiot</span>
                        <span class="font-medium text-text-primary">{{ $service->name }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-text-muted">Termin</span>
                        <span class="font-medium text-text-primary">{{ $rental->start_date->format('d.m.Y') }} — {{ $rental->end_date->format('d.m.Y') }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-text-muted">Status</span>
                        <x-ui.badge variant="warning" dot>Oczekujące na potwierdzenie</x-ui.badge>
                    </div>
                </div>
            </x-ui.card>
        @endif

        <div class="flex flex-col sm:flex-row gap-3 justify-center">
            <x-ui.button href="{{ route('service.show', $service) }}" variant="secondary" icon="arrow-left">
                Wróć do oferty
            </x-ui.button>
            <x-ui.button href="{{ route('services.index') }}" icon="cube">
                Przeglądaj katalog
            </x-ui.button>
        </div>
    </div>
</x-layout.section>
@endsection

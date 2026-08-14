@extends('layouts.app')

@section('content')

{{-- Hero --}}
<x-layout.section spacing="lg" class="bg-surface-sunken">
    <div class="max-w-3xl mx-auto text-center">
        <h1 class="text-4xl md:text-5xl font-bold text-text-primary tracking-tight mb-4">
            Nasze usługi
        </h1>
        <p class="text-lg md:text-xl text-text-secondary mb-8">
            Profesjonalne usługi dopasowane do Twoich potrzeb
        </p>

        @auth
            @if($bookingEnabled)
                <x-ui.button href="{{ route('booking.step', ['step' => 1]) }}" size="lg" icon-right="arrow-right">
                    Zarezerwuj termin
                </x-ui.button>
            @endif
        @else
            <div class="flex flex-col sm:flex-row gap-3 justify-center">
                @if($registrationEnabled)
                    <x-ui.button href="{{ route('customer.register') }}" size="lg">Rozpocznij</x-ui.button>
                @endif
                <x-ui.button variant="secondary" href="{{ route('login') }}" size="lg">Zaloguj się</x-ui.button>
            </div>
        @endauth
    </div>
</x-layout.section>

{{-- Services Grid --}}
<x-layout.section>
    @if($services->count() > 0)
        <x-layout.grid cols="3" gap="8">
            @foreach($services as $service)
                <x-ui.card hover href="{{ route('service.show', $service) }}" class="group" data-animate data-animate-delay="{{ $loop->index * 80 }}">
                    @if($service->featured_image)
                        <div class="-mx-6 -mt-6 mb-6 overflow-hidden rounded-t-xl">
                            <x-media.image :src="$service->featured_image" :alt="$service->name" aspect="16/9" rounded="none" class="group-hover:scale-[1.03] transition-transform duration-300" />
                        </div>
                    @endif

                    @if($service->icon)
                        <div class="flex items-center justify-center w-10 h-10 rounded-lg bg-brand-subtle text-brand mb-4">
                            <x-dynamic-component :component="'heroicon-m-' . $service->icon" class="h-5 w-5" />
                        </div>
                    @endif

                    <h3 class="text-lg font-semibold text-text-primary mb-2 group-hover:text-brand transition-colors">
                        {{ $service->name }}
                    </h3>

                    <p class="text-sm text-text-secondary mb-4 line-clamp-2">
                        {{ $service->excerpt ?? Str::limit($service->description, 120) }}
                    </p>

                    <div class="flex items-center justify-between mt-auto pt-4 border-t border-border">
                        @if($service->service_type === \App\Enums\ServiceType::ItemRental && $service->price_on_request)
                            <span class="text-sm font-medium text-text-muted italic">Cena do potwierdzenia</span>
                        @elseif($service->service_type === \App\Enums\ServiceType::ItemRental && $service->price_per_day)
                            <span class="text-lg font-bold text-text-primary">{{ number_format($service->price_per_day, 0, ',', ' ') }} zł<span class="text-sm font-normal text-text-muted">/dzień</span></span>
                        @elseif($service->price)
                            <span class="text-lg font-bold text-text-primary">{{ $service->price_from ? 'od ' : '' }}{{ number_format($service->price_from ?? $service->price, 0, ',', ' ') }} zł</span>
                        @endif

                        @if($service->duration_minutes && $service->service_type !== \App\Enums\ServiceType::ItemRental)
                            <x-ui.badge variant="default" icon="clock">{{ $service->formatted_duration }}</x-ui.badge>
                        @endif
                    </div>
                </x-ui.card>
            @endforeach
        </x-layout.grid>
    @else
        <div class="max-w-md mx-auto text-center py-16">
            <x-heroicon-o-cube class="h-16 w-16 text-text-muted mx-auto mb-4" />
            <h3 class="text-xl font-semibold text-text-primary mb-2">Brak dostępnych usług</h3>
            <p class="text-text-secondary">Wkrótce pojawią się nowe usługi. Sprawdź ponownie później.</p>
        </div>
    @endif

    @if($services->hasPages())
        <div class="mt-12 flex justify-center">
            {{ $services->links() }}
        </div>
    @endif
</x-layout.section>

{{-- CTA --}}
<x-layout.section dark>
    <div class="max-w-2xl mx-auto text-center">
        <h2 class="text-3xl font-bold text-dark-text mb-4">Gotowy, aby zacząć?</h2>
        <p class="text-dark-text-muted text-lg mb-8">
            @if($bookingEnabled)
                Zarezerwuj termin online w kilka kliknięć
            @else
                Skontaktuj się z nami i umów wizytę
            @endif
        </p>
        @auth
            @if($bookingEnabled)
                <x-ui.button href="{{ route('booking.step', ['step' => 1]) }}" size="lg" icon-right="arrow-right" class="bg-surface-raised text-text-primary hover:bg-surface">
                    Zarezerwuj teraz
                </x-ui.button>
            @endif
        @else
            <div class="flex flex-col sm:flex-row gap-3 justify-center">
                @if($registrationEnabled)
                    <x-ui.button href="{{ route('customer.register') }}" size="lg" class="bg-surface-raised text-text-primary hover:bg-surface">Załóż konto</x-ui.button>
                @endif
                <x-ui.button variant="ghost" href="{{ route('login') }}" size="lg" class="text-dark-text hover:text-dark-text">Mam już konto</x-ui.button>
            </div>
        @endauth
    </div>
</x-layout.section>

@endsection

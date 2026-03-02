@props([
    'service' => null,
    'duration' => null,
    'price' => null,
    'priceFrom' => null,
    'areaServed' => null,
])

@php
    if ($service) {
        $duration = $service->duration_display ?? ($service->duration_minutes ? $service->duration_minutes . ' min' : null);
        $price = $service->price;
        $priceFrom = $service->price_from;
        $areaServed = $service->area_served ?? 'Poznań';
    }

    // Format price display
    $priceDisplay = $priceFrom
        ? number_format($priceFrom, 0, ',', ' ') . ' zł'
        : ($price ? number_format($price, 0, ',', ' ') . ' zł' : 'Wycena indywidualna');
@endphp

<div class="container mx-auto px-4 md:px-6 -mt-16 relative z-10">
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 md:gap-6">
        {{-- Duration Card --}}
        <div class="bg-white rounded-2xl p-6 shadow-lg hover:shadow-xl transition-shadow duration-300 ios-spring border border-gray-100">
            <div class="flex items-center gap-4">
                <div class="flex-shrink-0 w-12 h-12 rounded-xl bg-gradient-to-br from-blue-50 to-blue-100 flex items-center justify-center">
                    <x-heroicon-o-clock class="w-6 h-6 text-blue-600" />
                </div>
                <div>
                    <p class="text-sm text-gray-500 mb-1">Czas trwania</p>
                    <p class="text-lg font-semibold text-gray-900">{{ $duration ?? 'Elastyczny' }}</p>
                </div>
            </div>
        </div>

        {{-- Price Card --}}
        <div class="bg-white rounded-2xl p-6 shadow-lg hover:shadow-xl transition-shadow duration-300 ios-spring border border-gray-100">
            <div class="flex items-center gap-4">
                <div class="flex-shrink-0 w-12 h-12 rounded-xl bg-gradient-to-br from-green-50 to-green-100 flex items-center justify-center">
                    <x-heroicon-o-currency-dollar class="w-6 h-6 text-green-600" />
                </div>
                <div>
                    <p class="text-sm text-gray-500 mb-1">Cena</p>
                    <p class="text-lg font-semibold text-gray-900">{{ $priceDisplay }}</p>
                </div>
            </div>
        </div>

        {{-- Area Card --}}
        <div class="bg-white rounded-2xl p-6 shadow-lg hover:shadow-xl transition-shadow duration-300 ios-spring border border-gray-100">
            <div class="flex items-center gap-4">
                <div class="flex-shrink-0 w-12 h-12 rounded-xl bg-gradient-to-br from-purple-50 to-purple-100 flex items-center justify-center">
                    <x-heroicon-o-map-pin class="w-6 h-6 text-purple-600" />
                </div>
                <div>
                    <p class="text-sm text-gray-500 mb-1">Obszar obsługi</p>
                    <p class="text-lg font-semibold text-gray-900">{{ $areaServed }}</p>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    /* iOS Spring Animation */
    .ios-spring {
        transition-timing-function: cubic-bezier(0.36, 0.66, 0.04, 1);
    }

    /* Accessibility: Reduced Motion */
    @media (prefers-reduced-motion: reduce) {
        .ios-spring {
            transition: none !important;
        }
    }
</style>

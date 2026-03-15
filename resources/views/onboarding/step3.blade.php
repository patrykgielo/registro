<x-ios.auth-card
    title="Personalizacja"
    subtitle="Krok 3 z 3 — Opcjonalny"
>
    <form method="POST" action="{{ route('register.step3.store') }}" class="space-y-6">
        @csrf

        {{-- City --}}
        <x-ios.input
            type="text"
            name="city"
            label="Miasto"
            placeholder="np. Warszawa"
            icon="map-pin"
            :value="old('city')"
            autocomplete="address-level2"
            id="city"
        />

        {{-- Address --}}
        <x-ios.input
            type="text"
            name="address"
            label="Adres (opcjonalnie)"
            placeholder="np. ul. Główna 10"
            icon="map"
            :value="old('address')"
            autocomplete="street-address"
            id="address"
        />

        @if($industry === \App\Enums\Industry::AutoDetailing)
            {{-- Mobile service toggle --}}
            <div class="p-4 bg-gray-50 rounded-lg border border-gray-200 space-y-4">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="font-semibold text-gray-900">Dojazd do klienta</p>
                        <p class="text-sm text-gray-500">Oferujesz usługi mobilne?</p>
                    </div>
                    <label class="relative inline-flex items-center cursor-pointer">
                        <input type="hidden" name="mobile_service" value="0">
                        <input type="checkbox" name="mobile_service" value="1"
                               class="sr-only peer" id="mobile_service"
                               {{ old('mobile_service', '1') === '1' ? 'checked' : '' }}>
                        <div class="w-11 h-6 bg-gray-200 rounded-full peer peer-checked:bg-primary-500 peer-focus:ring-4 peer-focus:ring-primary-500/20 after:content-[''] after:absolute after:top-0.5 after:left-[2px] after:bg-white after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:after:translate-x-full"></div>
                    </label>
                </div>

                {{-- Service radius --}}
                <div id="radius-field">
                    <label for="service_radius_km" class="block text-sm font-medium text-gray-700 mb-1">Promień działania (km)</label>
                    <input type="number" name="service_radius_km" id="service_radius_km"
                           value="{{ old('service_radius_km', 30) }}"
                           min="1" max="200"
                           class="w-full rounded-lg border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 text-sm">
                    @error('service_radius_km')
                        <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                    @enderror
                </div>
            </div>
        @endif

        @if($industry === \App\Enums\Industry::EquipmentRental)
            {{-- Delivery toggle --}}
            <div class="p-4 bg-gray-50 rounded-lg border border-gray-200">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="font-semibold text-gray-900">Dostawa sprzętu</p>
                        <p class="text-sm text-gray-500">Oferujesz dostawę sprzętu do klienta?</p>
                    </div>
                    <label class="relative inline-flex items-center cursor-pointer">
                        <input type="hidden" name="mobile_service" value="0">
                        <input type="checkbox" name="mobile_service" value="1"
                               class="sr-only peer" id="mobile_service"
                               {{ old('mobile_service') === '1' ? 'checked' : '' }}>
                        <div class="w-11 h-6 bg-gray-200 rounded-full peer peer-checked:bg-primary-500 peer-focus:ring-4 peer-focus:ring-primary-500/20 after:content-[''] after:absolute after:top-0.5 after:left-[2px] after:bg-white after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:after:translate-x-full"></div>
                    </label>
                </div>
            </div>
        @endif

        {{-- Buttons --}}
        <div class="flex gap-3">
            <a href="{{ route('register.welcome') }}"
               class="flex-1 bg-gray-100 text-gray-700 font-semibold py-4 rounded-lg text-center hover:bg-gray-200 transition-all duration-200">
                Pomiń
            </a>
            <button type="submit"
                    class="flex-[2] bg-primary-500 text-white font-semibold py-4 rounded-lg shadow-lg hover:shadow-xl hover:scale-[1.02] active:scale-[0.98] transition-all duration-300 focus:outline-none focus:ring-4 focus:ring-primary/30">
                <span class="flex items-center justify-center gap-2">
                    Zapisz i przejdź do panelu
                    <x-heroicon-m-arrow-right class="w-5 h-5" />
                </span>
            </button>
        </div>
    </form>
</x-ios.auth-card>

@if($industry === \App\Enums\Industry::AutoDetailing)
<script>
document.addEventListener('DOMContentLoaded', function() {
    const toggle = document.getElementById('mobile_service');
    const radiusField = document.getElementById('radius-field');

    function updateRadius() {
        radiusField.style.display = toggle.checked ? 'block' : 'none';
    }

    toggle.addEventListener('change', updateRadius);
    updateRadius();
});
</script>
@endif

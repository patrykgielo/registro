@php
    $mapId = 'map-' . uniqid();
    $record = $getRecord();
    $latitude = $record?->latitude ?? 52.2297; // Default Warsaw
    $longitude = $record?->longitude ?? 21.0122;
    $radiusKm = $record?->radius_km ?? 50; // Default 50km
    $colorHex = $record?->color_hex ?? '#4CAF50';
    $googleMapsApiKey = config('services.google_maps.api_key');
@endphp

{{-- Load Google Maps API if not already loaded --}}
@once
    @push('scripts')
    <script>
        // Check if Google Maps is already loaded
        if (typeof google === 'undefined' || typeof google.maps === 'undefined') {
            const script = document.createElement('script');
            script.src = 'https://maps.googleapis.com/maps/api/js?key={{ $googleMapsApiKey }}&libraries=places';
            script.async = true;
            script.defer = true;
            document.head.appendChild(script);
        }
    </script>
    @endpush
@endonce

<div x-data="{
    ...googleMapsPicker('{{ $mapId }}', {{ $latitude }}, {{ $longitude }}, {{ $radiusKm }}, '{{ $colorHex }}'),
    showInstructions: false
}" class="space-y-8">

    {{-- Collapsible Instructions Card --}}
    <div class="fi-fo-field-wrp">
        <div class="rounded-lg overflow-hidden border border-gray-300 dark:border-gray-600">
            <button
                type="button"
                @click="showInstructions = !showInstructions"
                class="w-full flex items-center justify-between gap-3 p-4 bg-gray-50 dark:bg-white/5 hover:bg-gray-100 dark:hover:bg-white/10 transition-colors text-gray-950 dark:text-white"
            >
                <div class="flex items-center gap-3">
                    <x-filament::icon
                        icon="heroicon-o-information-circle"
                        class="h-5 w-5 text-gray-400 dark:text-gray-500 flex-shrink-0"
                    />
                    <h4 class="text-sm font-semibold">
                        Jak ustawić obszar obsługi
                    </h4>
                </div>
                <x-filament::icon
                    icon="heroicon-m-chevron-down"
                    class="h-5 w-5 text-gray-400 dark:text-gray-500 transition-transform"
                    ::class="{ 'rotate-180': showInstructions }"
                />
            </button>

            <div
                x-show="showInstructions"
                x-collapse
                class="border-t border-gray-200 dark:border-white/10"
            >
                <ul class="p-4 space-y-3 text-sm text-gray-700 dark:text-gray-200">
                    <li class="flex items-start gap-3">
                        <x-filament::icon icon="heroicon-m-map-pin" class="h-5 w-5 mt-0.5 flex-shrink-0 text-gray-400 dark:text-gray-500" />
                        <span>Kliknij na mapie lub przeciągnij czerwony marker, aby ustawić środek obszaru</span>
                    </li>
                    <li class="flex items-start gap-3">
                        <x-filament::icon icon="heroicon-m-magnifying-glass" class="h-5 w-5 mt-0.5 flex-shrink-0 text-gray-400 dark:text-gray-500" />
                        <span>Użyj pola wyszukiwania poniżej, aby znaleźć konkretny adres lub miejsce</span>
                    </li>
                    <li class="flex items-start gap-3">
                        <x-filament::icon icon="heroicon-m-arrows-pointing-out" class="h-5 w-5 mt-0.5 flex-shrink-0 text-gray-400 dark:text-gray-500" />
                        <span>Wprowadź promień obszaru (1-200 km) i kliknij "Zaktualizuj" lub naciśnij Enter</span>
                    </li>
                    <li class="flex items-start gap-3">
                        <x-filament::icon icon="heroicon-m-eye" class="h-5 w-5 mt-0.5 flex-shrink-0 text-gray-400 dark:text-gray-500" />
                        <span>Koło na mapie pokazuje zasięg obszaru obsługi widoczny dla klientów</span>
                    </li>
                </ul>
            </div>
        </div>
    </div>

    {{-- Search Input --}}
    <div class="fi-fo-field-wrp">
        <div class="grid gap-y-2">
            <div class="flex items-center justify-between gap-x-3">
                <label class="fi-fo-field-wrp-label inline-flex items-center gap-x-3">
                    <span class="text-sm font-medium leading-6 text-gray-950 dark:text-white">
                        <x-filament::icon icon="heroicon-o-magnifying-glass" class="h-5 w-5 inline-block" />
                        Wyszukaj adres lub miejsce
                    </span>
                </label>
            </div>

            <div class="grid auto-cols-fr gap-y-2">
                <div class="fi-input-wrp flex rounded-lg shadow-sm ring-1 transition duration-75 bg-white dark:bg-white/5 [&:not(:has(.fi-ac-action:focus))]:focus-within:ring-2 ring-gray-950/10 dark:ring-white/20 [&:not(:has(.fi-ac-action:focus))]:focus-within:ring-primary-600 dark:[&:not(:has(.fi-ac-action:focus))]:focus-within:ring-primary-500">
                    <div class="min-w-0 flex-1">
                        <input
                            type="text"
                            x-ref="searchInput"
                            class="fi-input block w-full border-none py-1.5 text-base text-gray-950 transition duration-75 placeholder:text-gray-400 focus:ring-0 disabled:text-gray-500 disabled:[-webkit-text-fill-color:theme(colors.gray.500)] disabled:placeholder:[-webkit-text-fill-color:theme(colors.gray.400)] dark:text-white dark:placeholder:text-gray-500 dark:disabled:text-gray-400 dark:disabled:[-webkit-text-fill-color:theme(colors.gray.400)] dark:disabled:placeholder:[-webkit-text-fill-color:theme(colors.gray.500)] sm:text-sm sm:leading-6 bg-white/0 ps-3 pe-3"
                            placeholder="np. Plac Defilad 1, Warszawa"
                        />
                    </div>
                </div>
            </div>

            <p class="fi-fo-field-wrp-hint text-sm text-gray-500 dark:text-gray-400">
                Zacznij wpisywać nazwę miejsca lub adres, aby wyświetlić podpowiedzi
            </p>
        </div>
    </div>

    {{-- Map Container - LARGE FULL WIDTH --}}
    <div class="fi-fo-field-wrp">
        <div class="overflow-hidden rounded-lg border-2 border-gray-300 dark:border-gray-600 shadow-sm">
            <div
                id="{{ $mapId }}"
                class="w-full h-[600px]"
                style="min-height: 600px;"
                wire:ignore
            ></div>
        </div>
    </div>

    {{-- Radius Control - Enhanced Design --}}
    <div class="fi-fo-field-wrp">
        <div class="rounded-lg bg-white dark:bg-white/5 p-6 shadow-sm ring-1 ring-gray-950/5 dark:ring-white/10">
            <div class="flex flex-col sm:flex-row sm:items-end gap-4">
                <div class="flex-1 space-y-2">
                    <label class="fi-fo-field-wrp-label inline-flex items-center gap-x-3">
                        <span class="text-sm font-medium leading-6 text-gray-950 dark:text-white">
                            <x-filament::icon icon="heroicon-o-arrows-pointing-out" class="h-5 w-5 inline-block text-primary-600 dark:text-primary-400" />
                            Promień obszaru obsługi
                        </span>
                    </label>

                    <div class="fi-input-wrp flex rounded-lg shadow-sm ring-1 transition duration-75 bg-white dark:bg-white/5 [&:not(:has(.fi-ac-action:focus))]:focus-within:ring-2 ring-gray-950/10 dark:ring-white/20 [&:not(:has(.fi-ac-action:focus))]:focus-within:ring-primary-600 dark:[&:not(:has(.fi-ac-action:focus))]:focus-within:ring-primary-500">
                        <div class="min-w-0 flex-1">
                            <input
                                type="number"
                                x-model.number="currentRadius"
                                @keydown.enter="updateCircleRadius()"
                                min="1"
                                max="200"
                                step="1"
                                placeholder="np. 50"
                                class="fi-input block w-full border-none py-1.5 text-base text-gray-950 transition duration-75 placeholder:text-gray-400 focus:ring-0 disabled:text-gray-500 disabled:[-webkit-text-fill-color:theme(colors.gray.500)] disabled:placeholder:[-webkit-text-fill-color:theme(colors.gray.400)] dark:text-white dark:placeholder:text-gray-500 dark:disabled:text-gray-400 dark:disabled:[-webkit-text-fill-color:theme(colors.gray.400)] dark:disabled:placeholder:[-webkit-text-fill-color:theme(colors.gray.500)] sm:text-sm sm:leading-6 bg-white/0 ps-3 pe-12 font-semibold"
                            />
                        </div>
                        <div class="flex items-center pe-3 text-sm font-medium text-gray-500 dark:text-gray-400 pointer-events-none">
                            km
                        </div>
                    </div>

                    <p class="fi-fo-field-wrp-hint text-sm text-gray-500 dark:text-gray-400">
                        Wprowadź promień w kilometrach (1-200 km) i kliknij "Zaktualizuj" lub naciśnij Enter
                    </p>
                </div>

                <x-filament::button
                    type="button"
                    @click.prevent="updateCircleRadius()"
                    style="pointer-events: auto !important; position: relative; z-index: 10;"
                    size="lg"
                    icon="heroicon-o-arrow-path"
                >
                    Zaktualizuj zasięg
                </x-filament::button>
            </div>
        </div>
    </div>

    {{-- Current Coordinates Display - Enhanced --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
        <div class="fi-fo-field-wrp">
            <div class="rounded-lg bg-gray-50 dark:bg-white/5 p-5 ring-1 ring-gray-950/5 dark:ring-white/10 shadow-sm">
                <div class="flex items-center gap-2 mb-3">
                    <x-filament::icon icon="heroicon-o-arrows-up-down" class="h-5 w-5 text-gray-400 dark:text-gray-500" />
                    <span class="text-xs font-semibold uppercase tracking-wider text-gray-700 dark:text-gray-300">
                        Szerokość geograficzna
                    </span>
                </div>
                <div x-text="currentLat.toFixed(7)" class="text-lg font-mono font-bold text-gray-950 dark:text-white"></div>
            </div>
        </div>
        <div class="fi-fo-field-wrp">
            <div class="rounded-lg bg-gray-50 dark:bg-white/5 p-5 ring-1 ring-gray-950/5 dark:ring-white/10 shadow-sm">
                <div class="flex items-center gap-2 mb-3">
                    <x-filament::icon icon="heroicon-o-arrows-right-left" class="h-5 w-5 text-gray-400 dark:text-gray-500" />
                    <span class="text-xs font-semibold uppercase tracking-wider text-gray-700 dark:text-gray-300">
                        Długość geograficzna
                    </span>
                </div>
                <div x-text="currentLng.toFixed(7)" class="text-lg font-mono font-bold text-gray-950 dark:text-white"></div>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('googleMapsPicker', (mapId, initialLat, initialLng, initialRadius, initialColor) => ({
        map: null,
        marker: null,
        circle: null,
        currentLat: initialLat,
        currentLng: initialLng,
        currentRadius: initialRadius,
        currentColor: initialColor,

        init() {
            // Wait for Google Maps to load
            const initMap = () => {
                if (typeof google === 'undefined' || typeof google.maps === 'undefined') {
                    setTimeout(initMap, 100);
                    return;
                }
                this.initializeMap();
            };
            initMap();
        },

        initializeMap() {
            // Initialize map
            this.map = new google.maps.Map(document.getElementById(mapId), {
                center: { lat: this.currentLat, lng: this.currentLng },
                zoom: 12,
                mapTypeControl: true,
                streetViewControl: false,
                fullscreenControl: true,
            });

            // Add draggable marker (standard Google Maps marker)
            this.marker = new google.maps.Marker({
                position: { lat: this.currentLat, lng: this.currentLng },
                map: this.map,
                draggable: true,
                title: 'Środek obszaru obsługi',
            });

            // Add service area circle
            this.circle = new google.maps.Circle({
                strokeColor: this.currentColor,
                strokeOpacity: 0.8,
                strokeWeight: 2,
                fillColor: this.currentColor,
                fillOpacity: 0.15,
                map: this.map,
                center: { lat: this.currentLat, lng: this.currentLng },
                radius: this.currentRadius * 1000, // Convert km to meters
            });

            // Fit map to circle bounds
            this.map.fitBounds(this.circle.getBounds());

            // Click on map to set position
            this.map.addListener('click', (event) => {
                if (event.latLng) {
                    this.updatePosition(event.latLng.lat(), event.latLng.lng());
                    this.map.panTo(event.latLng);
                }
            });

            // Drag marker to set position
            this.marker.addListener('dragend', (event) => {
                if (event.latLng) {
                    this.updatePosition(event.latLng.lat(), event.latLng.lng());
                    this.map.panTo(event.latLng);
                }
            });

            // Initialize search autocomplete
            if (this.$refs.searchInput) {
                const autocomplete = new google.maps.places.Autocomplete(this.$refs.searchInput, {
                    fields: ['geometry', 'name'],
                });

                autocomplete.addListener('place_changed', () => {
                    const place = autocomplete.getPlace();
                    if (place.geometry) {
                        const lat = place.geometry.location.lat();
                        const lng = place.geometry.location.lng();
                        this.updatePosition(lat, lng);
                        this.map.panTo({ lat, lng });
                        this.map.setZoom(14);
                    }
                });
            }

            // NOTE: Watcher REMOVED to prevent infinite loop with Livewire
            // Manual updates are triggered by Enter key or button click only

            console.log('✅ Map initialized successfully. Circle radius:', this.circle.getRadius() / 1000, 'km');
        },

        updatePosition(lat, lng) {
            // Validate input coordinates
            if (typeof lat !== 'number' || typeof lng !== 'number') {
                console.error('❌ Invalid coordinates:', lat, lng);
                return;
            }

            this.currentLat = lat;
            this.currentLng = lng;

            // Update marker position (with null check)
            if (this.marker) {
                this.marker.setPosition({ lat, lng });
            }

            // Update circle position
            if (this.circle) {
                this.circle.setCenter({ lat, lng });
            }

            // Update form fields via Livewire (deferred - no re-render)
            this.$wire.set('data.latitude', lat, false);
            this.$wire.set('data.longitude', lng, false);
        },

        updateCircleRadius() {
            console.log('📍 🚨 updateCircleRadius() CALLED! 🚨');
            console.log('   Event source:', event?.type || 'unknown');
            console.log('   currentRadius value:', this.currentRadius, '(type:', typeof this.currentRadius, ')');
            console.log('   circle exists?', this.circle !== null);

            // Guard clause - ensure circle exists
            if (!this.circle) {
                console.error('❌ Circle not initialized!');
                return;
            }

            // Validate and convert
            const radiusValue = parseFloat(this.currentRadius);
            console.log('   Parsed radius:', radiusValue);

            if (isNaN(radiusValue) || radiusValue < 1) {
                console.warn('❌ Invalid radius value:', radiusValue);
                return;
            }

            // Update circle radius (convert km to meters)
            const radiusMeters = radiusValue * 1000;
            console.log('   Setting circle radius to:', radiusMeters, 'meters (', radiusValue, 'km)');
            this.circle.setRadius(radiusMeters);

            // Verify the update
            const actualRadius = this.circle.getRadius();
            console.log('   ✅ Circle radius after update:', actualRadius, 'meters (', (actualRadius / 1000).toFixed(1), 'km)');

            // Sync with Livewire form field WITHOUT triggering re-render (false = no re-render)
            this.$wire.set('data.radius_km', radiusValue, false);
            console.log('   💾 Synced with Livewire (no re-render)');

            // Fit map to new circle bounds
            const bounds = this.circle.getBounds();
            if (bounds) {
                this.map.fitBounds(bounds);
                console.log('   ✅ Map bounds updated and fitted');
            }
        },

        updateCircleColor() {
            if (!this.circle) return;

            // Update circle color
            this.circle.setOptions({
                strokeColor: this.currentColor,
                fillColor: this.currentColor,
            });
        }
    }));
});
</script>
@endpush

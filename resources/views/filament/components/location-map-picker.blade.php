@php
    // Copy of filament.components.google-maps-picker WITHOUT radius_km/color_hex —
    // a service area is a coverage circle, a location is a single point (see
    // app/docs/features/lokalizacje/plan-wdrozenia.md step 1.5). Coordinates are
    // nullable on Location (e.g. right after the primary-location backfill, which
    // has no coordinates to derive them from), so nothing here is ->required() and
    // the marker's default center is purely visual — it is never written back to
    // the form until the admin actually clicks/drags/searches.
    $mapId = 'map-' . uniqid();
    $record = $getRecord();
    $latitude = $record?->latitude ?? 52.2297; // Default Warsaw (visual only)
    $longitude = $record?->longitude ?? 21.0122;
    $hasCoordinates = $record?->latitude !== null && $record?->longitude !== null;
    $googleMapsApiKey = config('services.google_maps.api_key');
@endphp

{{-- Load Google Maps API if not already loaded --}}
@once
    @push('scripts')
    <script>
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
    ...locationMapPicker('{{ $mapId }}', {{ $latitude }}, {{ $longitude }}, {{ $hasCoordinates ? 'true' : 'false' }})
}" class="space-y-6">

    {{-- Search Input --}}
    <div class="fi-fo-field-wrp">
        <div class="grid gap-y-2">
            <label class="fi-fo-field-wrp-label inline-flex items-center gap-x-3">
                <span class="text-sm font-medium leading-6 text-gray-950 dark:text-white">
                    <x-filament::icon icon="heroicon-o-magnifying-glass" class="h-5 w-5 inline-block" />
                    Wyszukaj adres lub miejsce
                </span>
            </label>

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

    {{-- Map Container --}}
    <div class="fi-fo-field-wrp">
        <div class="overflow-hidden rounded-lg border-2 border-gray-300 dark:border-gray-600 shadow-sm">
            <div
                id="{{ $mapId }}"
                class="w-full h-[450px]"
                style="min-height: 450px;"
                wire:ignore
            ></div>
        </div>
        <p class="fi-fo-field-wrp-hint text-sm text-gray-500 dark:text-gray-400 mt-2">
            Kliknij na mapie lub przeciągnij marker, aby ustawić dokładną pozycję lokalizacji
        </p>
    </div>

    {{-- Current Coordinates Display --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
        <div class="fi-fo-field-wrp">
            <div class="rounded-lg bg-gray-50 dark:bg-white/5 p-5 ring-1 ring-gray-950/5 dark:ring-white/10 shadow-sm">
                <div class="flex items-center gap-2 mb-3">
                    <x-filament::icon icon="heroicon-o-arrows-up-down" class="h-5 w-5 text-gray-400 dark:text-gray-500" />
                    <span class="text-xs font-semibold uppercase tracking-wider text-gray-700 dark:text-gray-300">
                        Szerokość geograficzna
                    </span>
                </div>
                <div class="text-lg font-mono font-bold text-gray-950 dark:text-white">
                    <span x-show="hasCoordinates" x-text="currentLat.toFixed(7)"></span>
                    <span x-show="!hasCoordinates" class="text-gray-400 dark:text-gray-500 font-sans font-normal text-sm">nie ustawiono</span>
                </div>
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
                <div class="text-lg font-mono font-bold text-gray-950 dark:text-white">
                    <span x-show="hasCoordinates" x-text="currentLng.toFixed(7)"></span>
                    <span x-show="!hasCoordinates" class="text-gray-400 dark:text-gray-500 font-sans font-normal text-sm">nie ustawiono</span>
                </div>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('locationMapPicker', (mapId, initialLat, initialLng, initialHasCoordinates) => ({
        map: null,
        marker: null,
        currentLat: initialLat,
        currentLng: initialLng,
        hasCoordinates: initialHasCoordinates,

        init() {
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
            this.map = new google.maps.Map(document.getElementById(mapId), {
                center: { lat: this.currentLat, lng: this.currentLng },
                zoom: this.hasCoordinates ? 15 : 11,
                mapTypeControl: true,
                streetViewControl: false,
                fullscreenControl: true,
            });

            // Marker is always shown (draggable) — even before the admin has
            // picked a position — so there is always something to drag into
            // place. Its starting position is visual only: updatePosition()
            // (which writes to the Livewire form) fires only on click/drag/
            // search, never during init, so `latitude`/`longitude` stay NULL
            // until the admin actually interacts, matching the nullable column.
            this.marker = new google.maps.Marker({
                position: { lat: this.currentLat, lng: this.currentLng },
                map: this.map,
                draggable: true,
                title: 'Pozycja lokalizacji',
            });

            this.map.addListener('click', (event) => {
                if (event.latLng) {
                    this.updatePosition(event.latLng.lat(), event.latLng.lng());
                    this.map.panTo(event.latLng);
                }
            });

            this.marker.addListener('dragend', (event) => {
                if (event.latLng) {
                    this.updatePosition(event.latLng.lat(), event.latLng.lng());
                    this.map.panTo(event.latLng);
                }
            });

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
                        this.map.setZoom(15);
                    }
                });
            }
        },

        updatePosition(lat, lng) {
            if (typeof lat !== 'number' || typeof lng !== 'number') {
                console.error('Invalid coordinates:', lat, lng);
                return;
            }

            this.currentLat = lat;
            this.currentLng = lng;
            this.hasCoordinates = true;

            if (this.marker) {
                this.marker.setPosition({ lat, lng });
            }

            // Deferred (false = no re-render), same convention as the
            // service-area picker this was copied from.
            this.$wire.set('data.latitude', lat, false);
            this.$wire.set('data.longitude', lng, false);
        },
    }));
});
</script>
@endpush

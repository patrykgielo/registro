@extends('profile.layout')

@section('profile-content')
    @include('profile.partials.tab-address')
@endsection

@push('scripts')
@if($googleMapsApiKey)
<script>
    window.GOOGLE_MAPS_CONFIG = {
        apiKey: '{{ $googleMapsApiKey }}',
        mapId: '{{ $googleMapsMapId }}'
    };
</script>
<script src="https://maps.googleapis.com/maps/api/js?key={{ $googleMapsApiKey }}&libraries=places&callback=initGoogleMaps" async defer></script>
<script>
function initGoogleMaps() {
    // Initialize address autocomplete if the element exists
    const addressInput = document.getElementById('address-input');
    if (addressInput) {
        const autocomplete = new google.maps.places.Autocomplete(addressInput, {
            types: ['address'],
            componentRestrictions: { country: 'pl' }
        });

        autocomplete.addListener('place_changed', function() {
            const place = autocomplete.getPlace();

            if (place.geometry) {
                document.getElementById('address-latitude').value = place.geometry.location.lat();
                document.getElementById('address-longitude').value = place.geometry.location.lng();
                document.getElementById('address-place-id').value = place.place_id || '';
                document.getElementById('address-components').value = JSON.stringify(place.address_components || []);
                document.getElementById('address-full').value = place.formatted_address || addressInput.value;
            }
        });
    }
}
</script>
@endif
@endpush

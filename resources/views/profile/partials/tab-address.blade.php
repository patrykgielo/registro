<div class="bg-white rounded-lg shadow-md p-6">
    <div class="flex items-center justify-between mb-4">
        <h2 class="text-lg font-semibold text-gray-800">{{ __('Mój adres') }}</h2>
        <span class="text-sm text-gray-500">
            {{ $user->addresses->count() }}/{{ $user->address_limit }} {{ __('adresów') }}
        </span>
    </div>

    @if($user->addresses->count() > 0)
        {{-- Existing Address --}}
        @php $address = $user->addresses->first(); @endphp

        <form action="{{ route('profile.address.update', $address) }}" method="POST">
            @csrf
            @method('PATCH')

            <div class="space-y-4">
                {{-- Address Input with Autocomplete --}}
                <div>
                    <label for="address-input" class="block text-sm font-medium text-gray-700 mb-1">
                        {{ __('Adres') }} <span class="text-red-500">*</span>
                    </label>
                    <input type="text" id="address-input"
                           value="{{ old('address', $address->address) }}"
                           placeholder="{{ __('Zacznij wpisywać adres...') }}"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500"
                           autocomplete="off">
                    <input type="hidden" name="address" id="address-full" value="{{ old('address', $address->address) }}">
                    <input type="hidden" name="latitude" id="address-latitude" value="{{ old('latitude', $address->latitude) }}">
                    <input type="hidden" name="longitude" id="address-longitude" value="{{ old('longitude', $address->longitude) }}">
                    <input type="hidden" name="place_id" id="address-place-id" value="{{ old('place_id', $address->place_id) }}">
                    <input type="hidden" name="components" id="address-components" value="{{ old('components', json_encode($address->components)) }}">
                    @error('address')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                    @error('latitude')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Nickname --}}
                <div>
                    <label for="address_nickname" class="block text-sm font-medium text-gray-700 mb-1">
                        {{ __('Nazwa własna (opcjonalnie)') }}
                    </label>
                    <input type="text" name="nickname" id="address_nickname"
                           value="{{ old('nickname', $address->nickname) }}"
                           placeholder="{{ __('np. Dom, Praca, Garaż') }}"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500">
                </div>

                {{-- Map Preview --}}
                @if($address->latitude && $address->longitude)
                <div class="mt-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('Lokalizacja') }}</label>
                    <div class="h-48 bg-gray-100 rounded-lg flex items-center justify-center">
                        <a href="{{ $address->google_maps_link }}" target="_blank"
                           class="text-primary-600 hover:text-primary-800 flex items-center">
                            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/>
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/>
                            </svg>
                            {{ __('Zobacz na mapie Google') }}
                        </a>
                    </div>
                </div>
                @endif
            </div>

            <div class="mt-6 flex justify-between">
                <button type="button" onclick="confirmDeleteAddress({{ $address->id }})"
                        class="px-4 py-2 text-red-600 hover:text-red-800 font-medium">
                    {{ __('Usuń adres') }}
                </button>
                <button type="submit"
                        class="px-6 py-2 bg-primary-600 text-white font-medium rounded-lg hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2">
                    {{ __('Zapisz zmiany') }}
                </button>
            </div>
        </form>

        {{-- Delete form (hidden) --}}
        <form id="delete-address-form-{{ $address->id }}"
              action="{{ route('profile.address.destroy', $address) }}"
              method="POST" class="hidden">
            @csrf
            @method('DELETE')
        </form>
    @else
        {{-- Add New Address --}}
        @if($user->canAddAddress())
            <form action="{{ route('profile.address.store') }}" method="POST">
                @csrf

                <div class="space-y-4">
                    {{-- Address Input with Autocomplete --}}
                    <div>
                        <label for="address-input" class="block text-sm font-medium text-gray-700 mb-1">
                            {{ __('Adres') }} <span class="text-red-500">*</span>
                        </label>
                        <input type="text" id="address-input"
                               value="{{ old('address') }}"
                               placeholder="{{ __('Zacznij wpisywać adres...') }}"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500"
                               autocomplete="off">
                        <input type="hidden" name="address" id="address-full" value="{{ old('address') }}">
                        <input type="hidden" name="latitude" id="address-latitude" value="{{ old('latitude') }}">
                        <input type="hidden" name="longitude" id="address-longitude" value="{{ old('longitude') }}">
                        <input type="hidden" name="place_id" id="address-place-id" value="{{ old('place_id') }}">
                        <input type="hidden" name="components" id="address-components" value="{{ old('components') }}">
                        <p class="mt-1 text-sm text-gray-500">{{ __('Wybierz adres z listy podpowiedzi') }}</p>
                        @error('address')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                        @error('latitude')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    {{-- Nickname --}}
                    <div>
                        <label for="address_nickname_new" class="block text-sm font-medium text-gray-700 mb-1">
                            {{ __('Nazwa własna (opcjonalnie)') }}
                        </label>
                        <input type="text" name="nickname" id="address_nickname_new"
                               value="{{ old('nickname') }}"
                               placeholder="{{ __('np. Dom, Praca, Garaż') }}"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500">
                    </div>
                </div>

                <div class="mt-6 flex justify-end">
                    <button type="submit"
                            class="px-6 py-2 bg-primary-600 text-white font-medium rounded-lg hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2">
                        {{ __('Dodaj adres') }}
                    </button>
                </div>
            </form>
        @else
            <div class="text-center py-8 text-gray-500">
                <p>{{ __('Osiągnięto limit adresów. Skontaktuj się z administratorem, aby zwiększyć limit.') }}</p>
            </div>
        @endif
    @endif

    <div class="mt-6 p-4 bg-primary-50 rounded-lg">
        <p class="text-sm text-primary-700">
            <svg class="w-4 h-4 inline-block mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            {{ __('Zapisany adres zostanie automatycznie podstawiony przy składaniu nowych rezerwacji dla usług mobilnych.') }}
        </p>
    </div>
</div>

@push('scripts')
<script>
function confirmDeleteAddress(addressId) {
    if (confirm('{{ __("Czy na pewno chcesz usunąć ten adres?") }}')) {
        document.getElementById('delete-address-form-' + addressId).submit();
    }
}
</script>
@endpush

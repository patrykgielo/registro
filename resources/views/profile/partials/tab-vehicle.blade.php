<div class="bg-white rounded-lg shadow-md p-6">
    <div class="flex items-center justify-between mb-4">
        <h2 class="text-lg font-semibold text-gray-800">{{ __('Mój pojazd') }}</h2>
        <span class="text-sm text-gray-500">
            {{ $user->vehicles->count() }}/{{ $user->vehicle_limit }} {{ __('pojazdów') }}
        </span>
    </div>

    @if($user->vehicles->count() > 0)
        {{-- Existing Vehicle --}}
        @php $vehicle = $user->vehicles->first(); @endphp

        <form action="{{ route('profile.vehicle.update', $vehicle) }}" method="POST">
            @csrf
            @method('PATCH')

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                {{-- Vehicle Type --}}
                <div>
                    <label for="vehicle_type_id" class="block text-sm font-medium text-gray-700 mb-1">
                        {{ __('Typ pojazdu') }} <span class="text-red-500">*</span>
                    </label>
                    <select name="vehicle_type_id" id="vehicle_type_id"
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500"
                            required>
                        <option value="">{{ __('Wybierz typ') }}</option>
                        @foreach($vehicleTypes as $type)
                            <option value="{{ $type->id }}"
                                    {{ old('vehicle_type_id', $vehicle->vehicle_type_id) == $type->id ? 'selected' : '' }}>
                                {{ $type->name }}
                            </option>
                        @endforeach
                    </select>
                    @error('vehicle_type_id')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Car Brand --}}
                <div>
                    <label for="car_brand_id" class="block text-sm font-medium text-gray-700 mb-1">
                        {{ __('Marka') }}
                    </label>
                    <select name="car_brand_id" id="car_brand_id"
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500">
                        <option value="">{{ __('Wybierz markę lub wpisz własną') }}</option>
                        @foreach($carBrands as $brand)
                            <option value="{{ $brand->id }}"
                                    {{ old('car_brand_id', $vehicle->car_brand_id) == $brand->id ? 'selected' : '' }}>
                                {{ $brand->name }}
                            </option>
                        @endforeach
                    </select>
                </div>

                {{-- Custom Brand --}}
                <div>
                    <label for="custom_brand" class="block text-sm font-medium text-gray-700 mb-1">
                        {{ __('Własna nazwa marki') }}
                    </label>
                    <input type="text" name="custom_brand" id="custom_brand"
                           value="{{ old('custom_brand', $vehicle->custom_brand) }}"
                           placeholder="{{ __('Jeśli marki nie ma na liście') }}"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500">
                </div>

                {{-- Custom Model --}}
                <div>
                    <label for="custom_model" class="block text-sm font-medium text-gray-700 mb-1">
                        {{ __('Model') }}
                    </label>
                    <input type="text" name="custom_model" id="custom_model"
                           value="{{ old('custom_model', $vehicle->custom_model ?? $vehicle->carModel?->name) }}"
                           placeholder="{{ __('np. Golf, Civic, 3 Series') }}"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500">
                </div>

                {{-- Year --}}
                <div>
                    <label for="year" class="block text-sm font-medium text-gray-700 mb-1">
                        {{ __('Rok produkcji') }}
                    </label>
                    <input type="number" name="year" id="year"
                           value="{{ old('year', $vehicle->year) }}"
                           min="1900" max="{{ date('Y') + 1 }}"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500">
                </div>

                {{-- Nickname --}}
                <div>
                    <label for="nickname" class="block text-sm font-medium text-gray-700 mb-1">
                        {{ __('Nazwa własna (opcjonalnie)') }}
                    </label>
                    <input type="text" name="nickname" id="nickname"
                           value="{{ old('nickname', $vehicle->nickname) }}"
                           placeholder="{{ __('np. Mój samochód służbowy') }}"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500">
                </div>
            </div>

            <div class="mt-6 flex justify-between">
                <button type="button" onclick="confirmDeleteVehicle({{ $vehicle->id }})"
                        class="px-4 py-2 text-red-600 hover:text-red-800 font-medium">
                    {{ __('Usuń pojazd') }}
                </button>
                <button type="submit"
                        class="px-6 py-2 bg-primary-600 text-white font-medium rounded-lg hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2">
                    {{ __('Zapisz zmiany') }}
                </button>
            </div>
        </form>

        {{-- Delete form (hidden) --}}
        <form id="delete-vehicle-form-{{ $vehicle->id }}"
              action="{{ route('profile.vehicle.destroy', $vehicle) }}"
              method="POST" class="hidden">
            @csrf
            @method('DELETE')
        </form>
    @else
        {{-- Add New Vehicle --}}
        @if($user->canAddVehicle())
            <form action="{{ route('profile.vehicle.store') }}" method="POST">
                @csrf

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    {{-- Vehicle Type --}}
                    <div>
                        <label for="vehicle_type_id" class="block text-sm font-medium text-gray-700 mb-1">
                            {{ __('Typ pojazdu') }} <span class="text-red-500">*</span>
                        </label>
                        <select name="vehicle_type_id" id="vehicle_type_id"
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500"
                                required>
                            <option value="">{{ __('Wybierz typ') }}</option>
                            @foreach($vehicleTypes as $type)
                                <option value="{{ $type->id }}" {{ old('vehicle_type_id') == $type->id ? 'selected' : '' }}>
                                    {{ $type->name }}
                                </option>
                            @endforeach
                        </select>
                        @error('vehicle_type_id')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    {{-- Car Brand --}}
                    <div>
                        <label for="car_brand_id_new" class="block text-sm font-medium text-gray-700 mb-1">
                            {{ __('Marka') }}
                        </label>
                        <select name="car_brand_id" id="car_brand_id_new"
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500">
                            <option value="">{{ __('Wybierz markę lub wpisz własną') }}</option>
                            @foreach($carBrands as $brand)
                                <option value="{{ $brand->id }}" {{ old('car_brand_id') == $brand->id ? 'selected' : '' }}>
                                    {{ $brand->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    {{-- Custom Brand --}}
                    <div>
                        <label for="custom_brand_new" class="block text-sm font-medium text-gray-700 mb-1">
                            {{ __('Własna nazwa marki') }}
                        </label>
                        <input type="text" name="custom_brand" id="custom_brand_new"
                               value="{{ old('custom_brand') }}"
                               placeholder="{{ __('Jeśli marki nie ma na liście') }}"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500">
                    </div>

                    {{-- Custom Model --}}
                    <div>
                        <label for="custom_model_new" class="block text-sm font-medium text-gray-700 mb-1">
                            {{ __('Model') }}
                        </label>
                        <input type="text" name="custom_model" id="custom_model_new"
                               value="{{ old('custom_model') }}"
                               placeholder="{{ __('np. Golf, Civic, 3 Series') }}"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500">
                    </div>

                    {{-- Year --}}
                    <div>
                        <label for="year_new" class="block text-sm font-medium text-gray-700 mb-1">
                            {{ __('Rok produkcji') }}
                        </label>
                        <input type="number" name="year" id="year_new"
                               value="{{ old('year') }}"
                               min="1900" max="{{ date('Y') + 1 }}"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500">
                    </div>

                    {{-- Nickname --}}
                    <div>
                        <label for="nickname_new" class="block text-sm font-medium text-gray-700 mb-1">
                            {{ __('Nazwa własna (opcjonalnie)') }}
                        </label>
                        <input type="text" name="nickname" id="nickname_new"
                               value="{{ old('nickname') }}"
                               placeholder="{{ __('np. Mój samochód służbowy') }}"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500">
                    </div>
                </div>

                <div class="mt-6 flex justify-end">
                    <button type="submit"
                            class="px-6 py-2 bg-primary-600 text-white font-medium rounded-lg hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2">
                        {{ __('Dodaj pojazd') }}
                    </button>
                </div>
            </form>
        @else
            <div class="text-center py-8 text-gray-500">
                <p>{{ __('Osiągnięto limit pojazdów. Skontaktuj się z administratorem, aby zwiększyć limit.') }}</p>
            </div>
        @endif
    @endif

    <div class="mt-6 p-4 bg-primary-50 rounded-lg">
        <p class="text-sm text-primary-700">
            <svg class="w-4 h-4 inline-block mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            {{ __('Zapisany pojazd zostanie automatycznie podstawiony przy składaniu nowych rezerwacji.') }}
        </p>
    </div>
</div>

@push('scripts')
<script>
function confirmDeleteVehicle(vehicleId) {
    if (confirm('{{ __("Czy na pewno chcesz usunąć ten pojazd?") }}')) {
        document.getElementById('delete-vehicle-form-' + vehicleId).submit();
    }
}
</script>
@endpush

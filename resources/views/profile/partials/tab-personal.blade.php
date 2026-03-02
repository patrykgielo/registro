<div class="bg-white rounded-lg shadow-md p-6">
    <h2 class="text-lg font-semibold text-gray-800 mb-4">{{ __('Dane osobowe') }}</h2>

    <form action="{{ route('profile.personal.update') }}" method="POST">
        @csrf
        @method('PATCH')

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            {{-- First Name --}}
            <div>
                <label for="first_name" class="block text-sm font-medium text-gray-700 mb-1">
                    {{ __('Imię') }} <span class="text-red-500">*</span>
                </label>
                <input type="text" name="first_name" id="first_name"
                       value="{{ old('first_name', $user->first_name) }}"
                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500"
                       required>
                @error('first_name')
                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            {{-- Last Name --}}
            <div>
                <label for="last_name" class="block text-sm font-medium text-gray-700 mb-1">
                    {{ __('Nazwisko') }} <span class="text-red-500">*</span>
                </label>
                <input type="text" name="last_name" id="last_name"
                       value="{{ old('last_name', $user->last_name) }}"
                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500"
                       required>
                @error('last_name')
                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            {{-- Phone --}}
            <div class="md:col-span-2">
                <label for="phone_e164" class="block text-sm font-medium text-gray-700 mb-1">
                    {{ __('Numer telefonu') }}
                </label>
                <input type="tel" name="phone_e164" id="phone_e164"
                       value="{{ old('phone_e164', $user->phone_e164) }}"
                       placeholder="+48123456789"
                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500">
                <p class="mt-1 text-sm text-gray-500">{{ __('Format międzynarodowy, np. +48123456789') }}</p>
                @error('phone_e164')
                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>
        </div>

        {{-- Email (read-only, change via security tab) --}}
        <div class="mt-4 p-4 bg-gray-50 rounded-lg">
            <label class="block text-sm font-medium text-gray-700 mb-1">
                {{ __('Adres email') }}
            </label>
            <div class="flex items-center justify-between">
                <span class="text-gray-800">{{ $user->email }}</span>
                <button type="button" onclick="window.location.hash='security'"
                        class="text-sm text-primary-600 hover:text-primary-800">
                    {{ __('Zmień email') }} &rarr;
                </button>
            </div>
            @if($user->hasPendingEmailChange())
                <p class="mt-2 text-sm text-yellow-600">
                    <svg class="w-4 h-4 inline-block mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    {{ __('Oczekuje na potwierdzenie zmiany na:') }} {{ $user->pending_email }}
                </p>
            @endif
        </div>

        {{-- Billing Address Section --}}
        <div class="mt-6 p-4 border border-gray-200 rounded-lg">
            <h3 class="text-base font-semibold text-gray-800 mb-4">{{ __('Dane do faktury (opcjonalne)') }}</h3>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                {{-- Company Name --}}
                <div class="md:col-span-2">
                    <label for="company_name" class="block text-sm font-medium text-gray-700 mb-1">
                        {{ __('Nazwa firmy') }}
                    </label>
                    <input type="text" name="company_name" id="company_name"
                           value="{{ old('company_name', $user->company_name) }}"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500">
                    @error('company_name')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                {{-- NIP --}}
                <div class="md:col-span-2">
                    <label for="nip" class="block text-sm font-medium text-gray-700 mb-1">
                        {{ __('NIP') }}
                    </label>
                    <input type="text" name="nip" id="nip"
                           value="{{ old('nip', $user->nip) }}"
                           placeholder="1234567890"
                           maxlength="10"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500">
                    <p class="mt-1 text-sm text-gray-500">{{ __('10 cyfr bez kresek') }}</p>
                    @error('nip')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Billing Street --}}
                <div>
                    <label for="billing_street" class="block text-sm font-medium text-gray-700 mb-1">
                        {{ __('Ulica') }}
                    </label>
                    <input type="text" name="billing_street" id="billing_street"
                           value="{{ old('billing_street', $user->billing_street) }}"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500">
                    @error('billing_street')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Building Number --}}
                <div>
                    <label for="billing_building_number" class="block text-sm font-medium text-gray-700 mb-1">
                        {{ __('Nr budynku') }}
                    </label>
                    <input type="text" name="billing_building_number" id="billing_building_number"
                           value="{{ old('billing_building_number', $user->billing_building_number) }}"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500">
                    @error('billing_building_number')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Apartment Number --}}
                <div>
                    <label for="billing_apartment_number" class="block text-sm font-medium text-gray-700 mb-1">
                        {{ __('Nr lokalu') }}
                    </label>
                    <input type="text" name="billing_apartment_number" id="billing_apartment_number"
                           value="{{ old('billing_apartment_number', $user->billing_apartment_number) }}"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500">
                    @error('billing_apartment_number')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Postal Code --}}
                <div>
                    <label for="billing_postal_code" class="block text-sm font-medium text-gray-700 mb-1">
                        {{ __('Kod pocztowy') }}
                    </label>
                    <input type="text" name="billing_postal_code" id="billing_postal_code"
                           value="{{ old('billing_postal_code', $user->billing_postal_code) }}"
                           placeholder="00-000"
                           maxlength="6"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500">
                    @error('billing_postal_code')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                {{-- City --}}
                <div class="md:col-span-2">
                    <label for="billing_city" class="block text-sm font-medium text-gray-700 mb-1">
                        {{ __('Miasto') }}
                    </label>
                    <input type="text" name="billing_city" id="billing_city"
                           value="{{ old('billing_city', $user->billing_city) }}"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500">
                    @error('billing_city')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>
            </div>
        </div>

        <div class="mt-6 flex justify-end">
            <button type="submit"
                    class="px-6 py-2 bg-primary-600 text-white font-medium rounded-lg hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2">
                {{ __('Zapisz zmiany') }}
            </button>
        </div>
    </form>
</div>

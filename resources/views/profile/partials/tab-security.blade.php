<div class="space-y-6">
    {{-- Change Email Section --}}
    <div class="bg-white rounded-lg shadow-md p-6">
        <h2 class="text-lg font-semibold text-gray-800 mb-4">{{ __('Zmiana adresu email') }}</h2>

        <div class="mb-4">
            <p class="text-gray-600">{{ __('Aktualny email:') }} <strong>{{ $user->email }}</strong></p>
        </div>

        @if($user->hasPendingEmailChange())
            <div class="p-4 bg-yellow-50 border border-yellow-200 rounded-lg mb-4">
                <p class="text-yellow-700">
                    <svg class="w-5 h-5 inline-block mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    {{ __('Oczekuje na potwierdzenie zmiany email na:') }} <strong>{{ $user->pending_email }}</strong>
                </p>
                <p class="text-sm text-yellow-600 mt-1">
                    {{ __('Sprawdź skrzynkę nowego adresu email i kliknij link weryfikacyjny.') }}
                </p>
            </div>
        @endif

        <button type="button" onclick="openEmailModal()"
                class="px-4 py-2 bg-gray-100 text-gray-700 font-medium rounded-lg hover:bg-gray-200 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2">
            {{ __('Zmień adres email') }}
        </button>
    </div>

    {{-- Change Password Section --}}
    <div class="bg-white rounded-lg shadow-md p-6">
        <h2 class="text-lg font-semibold text-gray-800 mb-4">{{ __('Zmiana hasła') }}</h2>

        <form action="{{ route('profile.password.update') }}" method="POST">
            @csrf
            @method('PATCH')

            <div class="space-y-4">
                {{-- Current Password --}}
                <div>
                    <label for="current_password" class="block text-sm font-medium text-gray-700 mb-1">
                        {{ __('Obecne hasło') }} <span class="text-red-500">*</span>
                    </label>
                    <input type="password" name="current_password" id="current_password"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500"
                           required>
                    @error('current_password')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                {{-- New Password --}}
                <div>
                    <label for="password" class="block text-sm font-medium text-gray-700 mb-1">
                        {{ __('Nowe hasło') }} <span class="text-red-500">*</span>
                    </label>
                    <input type="password" name="password" id="password"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500"
                           required minlength="8">
                    <p class="mt-1 text-sm text-gray-500">{{ __('Minimum 8 znaków') }}</p>
                    @error('password')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Confirm Password --}}
                <div>
                    <label for="password_confirmation" class="block text-sm font-medium text-gray-700 mb-1">
                        {{ __('Potwierdź nowe hasło') }} <span class="text-red-500">*</span>
                    </label>
                    <input type="password" name="password_confirmation" id="password_confirmation"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500"
                           required>
                </div>
            </div>

            <div class="mt-6">
                <button type="submit"
                        class="px-6 py-2 bg-primary-600 text-white font-medium rounded-lg hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2">
                    {{ __('Zmień hasło') }}
                </button>
            </div>
        </form>
    </div>

    {{-- Data Export Section (GDPR Art. 20) --}}
    <div class="bg-white rounded-lg shadow-md p-6">
        <h2 class="text-lg font-semibold text-gray-800 mb-4">{{ __('Pobierz swoje dane') }}</h2>

        <p class="text-gray-600 mb-4">
            {{ __('Zgodnie z art. 20 RODO masz prawo do pobrania swoich danych osobowych w formacie umożliwiającym ich przeniesienie. Plik zawiera Twoje dane osobowe, adresy, pojazdy, historię rezerwacji oraz zgody marketingowe.') }}
        </p>

        <div class="mb-4 p-4 bg-blue-50 border border-blue-200 rounded-lg">
            <h3 class="font-medium text-blue-800 mb-2">{{ __('Eksport zawiera:') }}</h3>
            <ul class="text-sm text-blue-700 list-disc list-inside space-y-1">
                <li>{{ __('Dane osobowe (imię, nazwisko, email, telefon)') }}</li>
                <li>{{ __('Zapisane adresy') }}</li>
                <li>{{ __('Dane pojazdów') }}</li>
                <li>{{ __('Historia rezerwacji') }}</li>
                <li>{{ __('Zgody marketingowe') }}</li>
                <li>{{ __('Historia aktywności konta') }}</li>
            </ul>
        </div>

        <button type="button" onclick="openDataExportModal()"
                class="px-4 py-2 bg-primary-600 text-white font-medium rounded-lg hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2 inline-flex items-center gap-2">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
            </svg>
            {{ __('Pobierz moje dane (JSON)') }}
        </button>

        <p class="mt-3 text-sm text-gray-500">
            {{ __('Plik zostanie pobrany automatycznie. Limit: 3 pobrania na godzinę.') }}
        </p>
    </div>

    {{-- Delete Account Section --}}
    <div class="bg-white rounded-lg shadow-md p-6 border-2 border-red-100">
        <h2 class="text-lg font-semibold text-red-600 mb-4">{{ __('Usunięcie konta') }}</h2>

        @if($user->hasPendingDeletion())
            <div class="p-4 bg-red-50 border border-red-200 rounded-lg mb-4">
                <p class="text-red-700">
                    <svg class="w-5 h-5 inline-block mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                    </svg>
                    {{ __('Żądanie usunięcia konta zostało złożone.') }}
                </p>
                <p class="text-sm text-red-600 mt-1">
                    {{ __('Sprawdź swoją skrzynkę email, aby potwierdzić usunięcie konta.') }}
                </p>
                <form action="{{ route('profile.delete.cancel') }}" method="POST" class="mt-3">
                    @csrf
                    <button type="submit"
                            class="px-4 py-2 bg-white text-red-600 border border-red-300 font-medium rounded-lg hover:bg-red-50">
                        {{ __('Anuluj żądanie usunięcia') }}
                    </button>
                </form>
            </div>
        @else
            <p class="text-gray-600 mb-4">
                {{ __('Po usunięciu konta wszystkie Twoje dane osobowe zostaną zanonimizowane. Historia wizyt zostanie zachowana w celach księgowych, ale bez powiązania z Twoimi danymi osobowymi.') }}
            </p>

            <button type="button" onclick="openDeleteModal()"
                    class="px-4 py-2 bg-red-600 text-white font-medium rounded-lg hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2">
                {{ __('Usuń moje konto') }}
            </button>
        @endif
    </div>
</div>

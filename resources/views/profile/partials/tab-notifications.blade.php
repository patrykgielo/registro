<div class="bg-white rounded-lg shadow-md p-6">
    <h2 class="text-lg font-semibold text-gray-800 mb-4">{{ __('Preferencje powiadomień') }}</h2>

    <form action="{{ route('profile.notifications.update') }}" method="POST">
        @csrf
        @method('PATCH')

        {{-- Transactional Emails (Legitimate Interest - always on) --}}
        <div class="mb-6">
            <h3 class="text-md font-medium text-gray-700 mb-3">{{ __('Powiadomienia o wizytach (email)') }}</h3>
            <p class="text-sm text-gray-500 mb-4">{{ __('Te powiadomienia są niezbędne do realizacji usługi i nie można ich wyłączyć.') }}</p>

            <div class="space-y-3">
                <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                    <div>
                        <span class="font-medium text-gray-700">{{ __('Potwierdzenia wizyt') }}</span>
                        <p class="text-sm text-gray-500">{{ __('Email z potwierdzeniem po złożeniu rezerwacji') }}</p>
                    </div>
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                        {{ __('Zawsze włączone') }}
                    </span>
                </div>

                <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                    <div>
                        <span class="font-medium text-gray-700">{{ __('Przypomnienia o wizytach') }}</span>
                        <p class="text-sm text-gray-500">{{ __('Przypomnienia 24h i 2h przed wizytą') }}</p>
                    </div>
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                        {{ __('Zawsze włączone') }}
                    </span>
                </div>
            </div>
        </div>

        <hr class="my-6">

        {{-- SMS Notifications --}}
        <div class="mb-6">
            <h3 class="text-md font-medium text-gray-700 mb-3">{{ __('Powiadomienia SMS') }}</h3>

            <div class="space-y-4">
                {{-- SMS General Consent --}}
                <label class="flex items-start cursor-pointer">
                    <div class="flex items-center h-5">
                        <input type="hidden" name="sms_consent" value="0">
                        <input type="checkbox" name="sms_consent" value="1"
                               {{ $user->hasSmsConsent() ? 'checked' : '' }}
                               class="h-4 w-4 text-primary-600 border-gray-300 rounded focus:ring-primary-500">
                    </div>
                    <div class="ml-3">
                        <span class="font-medium text-gray-700">{{ __('Powiadomienia SMS o wizytach') }}</span>
                        <p class="text-sm text-gray-500">{{ __('Potwierdzenia i przypomnienia SMS') }}</p>
                    </div>
                </label>
            </div>
        </div>

        <div class="mt-6 flex justify-end">
            <button type="submit"
                    class="px-6 py-2 bg-primary-600 text-white font-medium rounded-lg hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2">
                {{ __('Zapisz preferencje') }}
            </button>
        </div>
    </form>

    <div class="mt-6 p-4 bg-gray-50 rounded-lg">
        <p class="text-sm text-gray-600">
            <svg class="w-4 h-4 inline-block mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
            </svg>
            {{ __('Twoje dane są przetwarzane zgodnie z RODO. Możesz w każdej chwili zmienić swoje preferencje lub zażądać usunięcia danych w zakładce Bezpieczeństwo.') }}
        </p>
    </div>
</div>

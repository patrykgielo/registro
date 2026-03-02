{{-- Change Email Modal --}}
<div id="email-modal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50">
    <div class="bg-white rounded-lg max-w-md w-full mx-4 p-6">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-semibold text-gray-800">{{ __('Zmiana adresu email') }}</h3>
            <button type="button" onclick="closeEmailModal()" class="text-gray-400 hover:text-gray-600">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>

        <form action="{{ route('profile.email.change') }}" method="POST">
            @csrf

            <div class="space-y-4">
                <div>
                    <label for="modal_email" class="block text-sm font-medium text-gray-700 mb-1">
                        {{ __('Nowy adres email') }} <span class="text-red-500">*</span>
                    </label>
                    <input type="email" name="email" id="modal_email"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500"
                           required>
                </div>

                <div>
                    <label for="modal_password" class="block text-sm font-medium text-gray-700 mb-1">
                        {{ __('Twoje hasło (potwierdzenie)') }} <span class="text-red-500">*</span>
                    </label>
                    <input type="password" name="password" id="modal_password"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500"
                           required>
                </div>

                <div class="p-3 bg-primary-50 rounded-lg">
                    <p class="text-sm text-primary-700">
                        <svg class="w-4 h-4 inline-block mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        {{ __('Na nowy adres email zostanie wysłany link weryfikacyjny. Obecny email pozostanie aktywny do momentu potwierdzenia zmiany.') }}
                    </p>
                </div>
            </div>

            <div class="mt-6 flex justify-end space-x-3">
                <button type="button" onclick="closeEmailModal()"
                        class="px-4 py-2 text-gray-700 font-medium rounded-lg hover:bg-gray-100">
                    {{ __('Anuluj') }}
                </button>
                <button type="submit"
                        class="px-6 py-2 bg-primary-600 text-white font-medium rounded-lg hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2">
                    {{ __('Wyślij link weryfikacyjny') }}
                </button>
            </div>
        </form>
    </div>
</div>

@push('scripts')
<script>
function openEmailModal() {
    document.getElementById('email-modal').classList.remove('hidden');
    document.getElementById('email-modal').classList.add('flex');
    document.getElementById('modal_email').focus();
}

function closeEmailModal() {
    document.getElementById('email-modal').classList.add('hidden');
    document.getElementById('email-modal').classList.remove('flex');
}

// Close on ESC key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeEmailModal();
        closeDeleteModal();
    }
});

// Close on outside click
document.getElementById('email-modal')?.addEventListener('click', function(e) {
    if (e.target === this) {
        closeEmailModal();
    }
});
</script>
@endpush

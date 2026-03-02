{{-- Delete Account Modal --}}
<div id="delete-modal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50">
    <div class="bg-white rounded-lg max-w-md w-full mx-4 p-6">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-semibold text-red-600">{{ __('Usunięcie konta') }}</h3>
            <button type="button" onclick="closeDeleteModal()" class="text-gray-400 hover:text-gray-600">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>

        <div class="mb-4 p-4 bg-red-50 border border-red-200 rounded-lg">
            <p class="text-red-700 font-medium mb-2">{{ __('Uwaga! Ta operacja jest nieodwracalna.') }}</p>
            <ul class="text-sm text-red-600 list-disc list-inside space-y-1">
                <li>{{ __('Wszystkie Twoje dane osobowe zostaną zanonimizowane') }}</li>
                <li>{{ __('Zapisane pojazdy i adresy zostaną usunięte') }}</li>
                <li>{{ __('Nie będziesz mógł się zalogować') }}</li>
                <li>{{ __('Historia wizyt zostanie zachowana bez powiązania z Twoimi danymi') }}</li>
            </ul>
        </div>

        <form action="{{ route('profile.delete.request') }}" method="POST">
            @csrf

            <div class="space-y-4">
                <div>
                    <label for="delete_password" class="block text-sm font-medium text-gray-700 mb-1">
                        {{ __('Twoje hasło (potwierdzenie)') }} <span class="text-red-500">*</span>
                    </label>
                    <input type="password" name="password" id="delete_password"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-red-500 focus:border-red-500"
                           required>
                </div>

                <label class="flex items-start cursor-pointer">
                    <div class="flex items-center h-5">
                        <input type="checkbox" name="confirmation" value="1" id="delete_confirmation"
                               class="h-4 w-4 text-red-600 border-gray-300 rounded focus:ring-red-500"
                               required>
                    </div>
                    <div class="ml-3">
                        <span class="text-sm text-gray-700">
                            {{ __('Rozumiem konsekwencje i chcę usunąć moje konto') }}
                        </span>
                    </div>
                </label>
            </div>

            <div class="mt-6 flex justify-end space-x-3">
                <button type="button" onclick="closeDeleteModal()"
                        class="px-4 py-2 text-gray-700 font-medium rounded-lg hover:bg-gray-100">
                    {{ __('Anuluj') }}
                </button>
                <button type="submit"
                        class="px-6 py-2 bg-red-600 text-white font-medium rounded-lg hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2">
                    {{ __('Usuń moje konto') }}
                </button>
            </div>
        </form>
    </div>
</div>

@push('scripts')
<script>
function openDeleteModal() {
    document.getElementById('delete-modal').classList.remove('hidden');
    document.getElementById('delete-modal').classList.add('flex');
    document.getElementById('delete_password').focus();
}

function closeDeleteModal() {
    document.getElementById('delete-modal').classList.add('hidden');
    document.getElementById('delete-modal').classList.remove('flex');
}

// Close on outside click
document.getElementById('delete-modal')?.addEventListener('click', function(e) {
    if (e.target === this) {
        closeDeleteModal();
    }
});
</script>
@endpush

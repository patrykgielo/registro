{{-- GDPR Article 20 - Data Export Modal --}}
<div id="dataExportModal"
     x-data="{ open: false }"
     x-show="open"
     x-cloak
     @open-data-export.window="open = true"
     class="fixed inset-0 z-50 overflow-y-auto">

    {{-- Backdrop --}}
    <div class="fixed inset-0 bg-black/50 backdrop-blur-sm" @click="open = false"></div>

    {{-- Modal Content --}}
    <div class="relative min-h-screen flex items-center justify-center p-4">
        <div class="relative bg-white dark:bg-gray-800 rounded-2xl shadow-xl max-w-lg w-full p-6"
             @click.away="open = false">

            {{-- Header --}}
            <div class="flex items-center justify-between mb-6">
                <h3 class="text-xl font-bold text-gray-900 dark:text-white">
                    Eksport danych osobowych
                </h3>
                <button @click="open = false" class="text-gray-400 hover:text-gray-600">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>

            {{-- Content --}}
            <div class="space-y-4 mb-6">
                <div class="bg-cyan-50 dark:bg-cyan-900/20 border border-cyan-200 dark:border-cyan-800 rounded-lg p-4">
                    <div class="flex items-start">
                        <svg class="w-5 h-5 text-cyan-600 mt-0.5 mr-3 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        <div class="text-sm text-cyan-800 dark:text-cyan-200">
                            <p class="font-medium mb-1">Prawo do przenoszenia danych (art. 20 RODO)</p>
                            <p>Masz prawo otrzymać swoje dane osobowe w ustrukturyzowanym, powszechnie używanym formacie.</p>
                        </div>
                    </div>
                </div>

                <div class="text-sm text-gray-600 dark:text-gray-300 space-y-2">
                    <p><strong>Eksportowane dane obejmują:</strong></p>
                    <ul class="list-disc list-inside space-y-1 ml-2">
                        <li>Dane osobowe (imię, nazwisko, email, telefon)</li>
                        <li>Zapisane adresy</li>
                        <li>Zarejestrowane pojazdy</li>
                        <li>Historia rezerwacji</li>
                        <li>Historia zgód marketingowych</li>
                    </ul>
                </div>

                <div class="bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 rounded-lg p-4">
                    <div class="flex items-start">
                        <svg class="w-5 h-5 text-amber-600 mt-0.5 mr-3 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                        </svg>
                        <div class="text-sm text-amber-800 dark:text-amber-200">
                            <p>Możesz pobrać swoje dane <strong>raz na 24 godziny</strong>.</p>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Actions --}}
            <div class="flex gap-3">
                <button @click="open = false"
                        class="flex-1 px-4 py-2.5 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors">
                    Anuluj
                </button>
                <a href="{{ route('profile.data.export') }}"
                   class="flex-1 px-4 py-2.5 bg-cyan-600 hover:bg-cyan-700 text-white rounded-lg text-center transition-colors flex items-center justify-center gap-2">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                    </svg>
                    Pobierz JSON
                </a>
            </div>
        </div>
    </div>
</div>

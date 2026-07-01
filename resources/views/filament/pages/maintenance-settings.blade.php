<x-filament-panels::page>
    {{-- Current Status --}}
    <x-filament::section
        :icon="$isActive ? 'heroicon-o-lock-closed' : 'heroicon-o-check-badge'"
        :icon-color="$isActive ? 'danger' : 'success'"
    >
        <div class="flex items-center justify-between gap-4">
            <div>
                <div class="flex items-center gap-2">
                    <h3 class="text-base font-semibold text-gray-950 dark:text-white">
                        @if($isActive)
                            Tryb konserwacji aktywny
                        @else
                            Panel działa normalnie
                        @endif
                    </h3>

                    @if($isActive)
                        <span class="relative flex h-2.5 w-2.5">
                            <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-danger-400 opacity-75"></span>
                            <span class="relative inline-flex h-2.5 w-2.5 rounded-full bg-danger-500"></span>
                        </span>
                    @endif
                </div>

                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    @if($isActive && $currentType)
                        Typ: {{ $currentType->label() }}
                        @if($currentType === \App\Enums\MaintenanceType::PRELAUNCH)
                            <x-filament::badge color="danger" class="ms-2">Bez możliwości ominięcia</x-filament::badge>
                        @else
                            <x-filament::badge color="warning" class="ms-2">Bypass dla adminów włączony</x-filament::badge>
                        @endif
                    @else
                        Wszyscy użytkownicy mają normalny dostęp do strony
                    @endif
                </p>
            </div>
        </div>

        {{-- Secret Token Display --}}
        @if($isActive && $secretToken)
            <div class="mt-4 border-t border-gray-200 pt-4 dark:border-white/10">
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <p class="text-sm font-medium text-gray-950 dark:text-white">Tajny token bypass:</p>
                        <code class="mt-1 inline-block rounded bg-gray-100 px-2 py-1 font-mono text-xs text-gray-700 dark:bg-white/5 dark:text-gray-300">
                            {{ $secretToken }}
                        </code>
                    </div>
                    <button
                        type="button"
                        onclick="navigator.clipboard.writeText('{{ $secretToken }}').then(() => {
                            new FilamentNotification()
                                .title('Token skopiowany do schowka')
                                .success()
                                .send()
                        })"
                        class="min-h-11 text-sm text-primary-600 underline hover:text-primary-500 cursor-pointer focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-500"
                    >
                        Kopiuj
                    </button>
                </div>
                <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                    Udostępnij ten token autoryzowanym użytkownikom: <code class="rounded bg-gray-100 px-1 dark:bg-white/5">?maintenance_token={{ $secretToken }}</code>
                </p>
            </div>
        @endif

        {{-- Current Config Display --}}
        @if($isActive && !empty($currentConfig))
            <div class="mt-4 border-t border-gray-200 pt-4 dark:border-white/10">
                <p class="mb-2 text-sm font-medium text-gray-950 dark:text-white">Bieżąca konfiguracja:</p>
                <div class="grid grid-cols-1 gap-x-4 gap-y-1 text-xs text-gray-600 sm:grid-cols-2 dark:text-gray-400">
                    @foreach($currentConfig as $key => $value)
                        @if($value)
                            <div>
                                <span class="font-semibold">{{ ucfirst(str_replace('_', ' ', $key)) }}:</span>
                                <span>{{ $value }}</span>
                            </div>
                        @endif
                    @endforeach
                </div>
            </div>
        @endif
    </x-filament::section>

    {{-- Configuration Form --}}
    <form wire:submit="submit" class="mt-6">
        {{ $this->form }}
    </form>

    {{-- Info Cards --}}
    <div class="mt-8 grid grid-cols-1 gap-4 md:grid-cols-2">
        <x-filament::section icon="heroicon-o-information-circle" heading="Sposoby ominięcia blokady">
            <ul class="space-y-1 text-sm text-gray-600 dark:text-gray-400">
                <li>• Wg roli: role super-admin i admin</li>
                <li>• Wg tokenu: tajny token w adresie URL lub sesji</li>
                <li>• Pre-launch: brak możliwości ominięcia</li>
            </ul>
        </x-filament::section>

        <x-filament::section icon="heroicon-o-document-text" heading="Typy konserwacji">
            <ul class="space-y-1 text-sm text-gray-600 dark:text-gray-400">
                <li>• <strong>Deployment:</strong> aktualizacje kodu (ponowienie co 60s)</li>
                <li>• <strong>Scheduled:</strong> planowana konserwacja (ponowienie co 300s)</li>
                <li>• <strong>Emergency:</strong> pilne poprawki (ponowienie co 120s)</li>
                <li>• <strong>Pre-launch:</strong> całkowita blokada (ponowienie co 3600s)</li>
            </ul>
        </x-filament::section>
    </div>
</x-filament-panels::page>

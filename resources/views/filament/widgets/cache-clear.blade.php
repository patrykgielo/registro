<x-filament-widgets::widget>
    <x-slot name="heading">
        Zarządzanie cache
    </x-slot>

    <x-slot name="description">
        Szybkie operacje czyszczenia cache do celów deweloperskich i diagnostycznych
    </x-slot>

    {{-- Cache Operations Grid --}}
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
        {{-- Application Cache --}}
        <x-filament::section icon="heroicon-o-circle-stack" heading="Aplikacja" description="Ustawienia, obszary obsługi, rezerwacje">
            <x-filament::button
                size="sm"
                color="warning"
                wire:click="clearApplicationCache"
                wire:loading.attr="disabled"
                class="w-full"
            >
                <span wire:loading.remove wire:target="clearApplicationCache">Wyczyść cache</span>
                <span wire:loading wire:target="clearApplicationCache">Czyszczenie...</span>
            </x-filament::button>
        </x-filament::section>

        {{-- Config Cache --}}
        <x-filament::section icon="heroicon-o-cog-8-tooth" heading="Konfiguracja" description="Konfiguracja, trasy, widoki">
            <x-filament::button
                size="sm"
                color="warning"
                wire:click="clearConfigCache"
                wire:loading.attr="disabled"
                class="w-full"
            >
                <span wire:loading.remove wire:target="clearConfigCache">Wyczyść cache</span>
                <span wire:loading wire:target="clearConfigCache">Czyszczenie...</span>
            </x-filament::button>
        </x-filament::section>

        {{-- All Caches --}}
        <x-filament::section icon="heroicon-o-trash" heading="Wszystkie cache" description="Wszystko naraz">
            <x-filament::button
                size="sm"
                color="danger"
                wire:click="clearAllCaches"
                wire:loading.attr="disabled"
                class="w-full"
            >
                <span wire:loading.remove wire:target="clearAllCaches">Wyczyść wszystko</span>
                <span wire:loading wire:target="clearAllCaches">Czyszczenie...</span>
            </x-filament::button>
        </x-filament::section>
    </div>

    {{-- Compact Info Banner --}}
    <div class="mt-4 flex items-start gap-2 rounded-lg border border-gray-200 bg-gray-50 p-3 text-xs dark:border-gray-700 dark:bg-gray-900/50">
        <x-filament::icon
            icon="heroicon-o-information-circle"
            class="h-4 w-4 flex-shrink-0 text-gray-500 dark:text-gray-400"
            style="max-width: 1rem; max-height: 1rem;"
        />
        <div class="text-gray-600 dark:text-gray-400">
            <strong class="font-medium">Uwaga:</strong> OPcache wymaga restartu kontenera. Użyj: <code class="rounded bg-gray-200 px-1 py-0.5 font-mono text-xs dark:bg-gray-800">docker compose restart app</code>
        </div>
    </div>
</x-filament-widgets::widget>

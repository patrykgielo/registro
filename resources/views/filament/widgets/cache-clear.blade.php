<x-filament-widgets::widget>
    <x-slot name="heading">
        Cache Management
    </x-slot>

    <x-slot name="description">
        Quick cache operations for development and troubleshooting
    </x-slot>

    {{-- Cache Operations Grid --}}
    <div class="grid grid-cols-3 gap-4">
        {{-- Application Cache --}}
        <div class="flex flex-col gap-3 p-4 rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800">
            <x-filament::icon
                icon="heroicon-o-circle-stack"
                class="w-6 h-6 text-gray-400 flex-shrink-0"
                style="max-width: 1.5rem; max-height: 1.5rem;"
            />
            <div>
                <div class="text-base font-semibold text-gray-900 dark:text-gray-100">Application</div>
                <div class="mt-1 text-sm text-gray-600 dark:text-gray-400">Settings, service areas, bookings</div>
            </div>
            <div class="mt-auto">
                <x-filament::button
                    size="sm"
                    color="warning"
                    wire:click="clearApplicationCache"
                    wire:loading.attr="disabled"
                    class="w-full"
                >
                    <span wire:loading.remove wire:target="clearApplicationCache">Clear Cache</span>
                    <span wire:loading wire:target="clearApplicationCache">Clearing...</span>
                </x-filament::button>
            </div>
        </div>

        {{-- Config Cache --}}
        <div class="flex flex-col gap-3 p-4 rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800">
            <x-filament::icon
                icon="heroicon-o-cog-8-tooth"
                class="w-6 h-6 text-gray-400 flex-shrink-0"
                style="max-width: 1.5rem; max-height: 1.5rem;"
            />
            <div>
                <div class="text-base font-semibold text-gray-900 dark:text-gray-100">Config</div>
                <div class="mt-1 text-sm text-gray-600 dark:text-gray-400">Configuration, routes, views</div>
            </div>
            <div class="mt-auto">
                <x-filament::button
                    size="sm"
                    color="warning"
                    wire:click="clearConfigCache"
                    wire:loading.attr="disabled"
                    class="w-full"
                >
                    <span wire:loading.remove wire:target="clearConfigCache">Clear Cache</span>
                    <span wire:loading wire:target="clearConfigCache">Clearing...</span>
                </x-filament::button>
            </div>
        </div>

        {{-- All Caches --}}
        <div class="flex flex-col gap-3 p-4 rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800">
            <x-filament::icon
                icon="heroicon-o-trash"
                class="w-6 h-6 text-gray-400 flex-shrink-0"
                style="max-width: 1.5rem; max-height: 1.5rem;"
            />
            <div>
                <div class="text-base font-semibold text-gray-900 dark:text-gray-100">All Caches</div>
                <div class="mt-1 text-sm text-gray-600 dark:text-gray-400">Everything at once</div>
            </div>
            <div class="mt-auto">
                <x-filament::button
                    size="sm"
                    color="danger"
                    wire:click="clearAllCaches"
                    wire:loading.attr="disabled"
                    class="w-full"
                >
                    <span wire:loading.remove wire:target="clearAllCaches">Clear All</span>
                    <span wire:loading wire:target="clearAllCaches">Clearing...</span>
                </x-filament::button>
            </div>
        </div>
    </div>

    {{-- Compact Info Banner --}}
    <div class="mt-4 flex items-start gap-2 p-3 bg-gray-50 dark:bg-gray-900/50 border border-gray-200 dark:border-gray-700 rounded-lg text-xs">
        <x-filament::icon
            icon="heroicon-o-information-circle"
            class="w-4 h-4 text-gray-500 dark:text-gray-400 flex-shrink-0"
            style="max-width: 1rem; max-height: 1rem;"
        />
        <div class="text-gray-600 dark:text-gray-400">
            <strong class="font-medium">Note:</strong> OPcache requires container restart. Use: <code class="px-1 py-0.5 bg-gray-200 dark:bg-gray-800 rounded font-mono text-xs">docker compose restart app</code>
        </div>
    </div>
</x-filament-widgets::widget>

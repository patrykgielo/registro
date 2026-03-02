<x-filament-panels::page.simple>
    <div class="text-center py-12">
        <div class="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-warning-100 dark:bg-warning-900/20">
            <x-heroicon-o-wrench-screwdriver class="h-8 w-8 text-warning-600 dark:text-warning-400" />
        </div>

        <h2 class="mt-6 text-2xl font-bold tracking-tight text-gray-900 dark:text-white">
            Panel w trybie konserwacji
        </h2>

        <p class="mt-4 text-base text-gray-600 dark:text-gray-400">
            Dostep do panelu administracyjnego jest tymczasowo ograniczony.<br>
            Tylko super-administratorzy moga korzystac z systemu w tym czasie.
        </p>

        @if($type)
            <div class="mt-6 inline-flex items-center gap-2 rounded-full bg-gray-100 px-4 py-2 text-sm font-medium text-gray-700 dark:bg-gray-800 dark:text-gray-300">
                <span class="h-2 w-2 rounded-full bg-warning-500"></span>
                Tryb: {{ $type->value }}
            </div>
        @endif

        <div class="mt-8 flex flex-col items-center gap-4">
            <form method="POST" action="{{ route('filament.admin.auth.logout') }}">
                @csrf
                <x-filament::button type="submit" color="gray" size="lg">
                    <x-heroicon-m-arrow-right-start-on-rectangle class="mr-2 h-5 w-5" />
                    Wyloguj sie
                </x-filament::button>
            </form>

            <p class="text-sm text-gray-500 dark:text-gray-400">
                Zalogowany jako: <span class="font-medium">{{ $user->email ?? 'N/A' }}</span>
            </p>
        </div>
    </div>
</x-filament-panels::page.simple>

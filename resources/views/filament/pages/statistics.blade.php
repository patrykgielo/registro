<x-filament-panels::page>
    @php
        $statsData   = $this->getStatsData();
        $chartData   = $this->getChartData();
        $topServices = $this->getTopServices();
        $bookingEnabled = app(\App\Support\Settings\SettingsManager::class)->isBookingEnabled();
        $rentalEnabled  = app(\App\Support\Settings\SettingsManager::class)->isRentalEnabled();
    @endphp

    {{-- Period selector --}}
    <x-filament::tabs contained label="Wybierz okres" class="mb-6">
        @foreach($this->periodOptions() as $option)
            <x-filament::tabs.item
                wire:click="$set('period', '{{ $option['value'] }}')"
                :active="$this->period === $option['value']"
            >
                {{ $option['label'] }}
            </x-filament::tabs.item>
        @endforeach
    </x-filament::tabs>

    {{-- KPI Cards --}}
    <div class="grid grid-cols-2 gap-4 xl:grid-cols-4 mb-6">

        {{-- Total Revenue --}}
        <div class="fi-wi-stats-overview-stat">
            <div class="fi-wi-stats-overview-stat-content">
                <div class="fi-wi-stats-overview-stat-label-ctn">
                    <x-filament::icon icon="heroicon-o-banknotes" class="fi-icon h-5 w-5" />
                    <span class="fi-wi-stats-overview-stat-label">Przychód łączny</span>
                </div>
                <div class="fi-wi-stats-overview-stat-value tabular-nums">
                    {{ number_format($statsData['total_revenue'], 2, ',', ' ') }}
                    <span class="text-base font-normal text-gray-500 dark:text-gray-400">PLN</span>
                </div>
            </div>
        </div>

        {{-- Orders --}}
        <div class="fi-wi-stats-overview-stat">
            <div class="fi-wi-stats-overview-stat-content">
                <div class="fi-wi-stats-overview-stat-label-ctn">
                    <x-filament::icon icon="heroicon-o-shopping-bag" class="fi-icon h-5 w-5" />
                    <span class="fi-wi-stats-overview-stat-label">Zamówienia</span>
                </div>
                <div class="fi-wi-stats-overview-stat-value tabular-nums">
                    {{ number_format($statsData['orders']['count']) }}
                </div>
                <p class="fi-wi-stats-overview-stat-description tabular-nums">
                    {{ number_format($statsData['orders']['revenue'], 2, ',', ' ') }} PLN
                </p>
            </div>
        </div>

        {{-- Appointments (only if booking module active) --}}
        @if($bookingEnabled)
        <div class="fi-wi-stats-overview-stat">
            <div class="fi-wi-stats-overview-stat-content">
                <div class="fi-wi-stats-overview-stat-label-ctn">
                    <x-filament::icon icon="heroicon-o-calendar-days" class="fi-icon h-5 w-5" />
                    <span class="fi-wi-stats-overview-stat-label">Wizyty</span>
                </div>
                <div class="fi-wi-stats-overview-stat-value tabular-nums">
                    {{ number_format($statsData['appointments']['count']) }}
                </div>
                <p class="fi-wi-stats-overview-stat-description tabular-nums">
                    {{ number_format($statsData['appointments']['revenue'], 2, ',', ' ') }} PLN
                </p>
            </div>
        </div>
        @endif

        {{-- Rentals (only if rental module active) --}}
        @if($rentalEnabled)
        <div class="fi-wi-stats-overview-stat">
            <div class="fi-wi-stats-overview-stat-content">
                <div class="fi-wi-stats-overview-stat-label-ctn">
                    <x-filament::icon icon="heroicon-o-wrench-screwdriver" class="fi-icon h-5 w-5" />
                    <span class="fi-wi-stats-overview-stat-label">Wypożyczenia</span>
                </div>
                <div class="fi-wi-stats-overview-stat-value tabular-nums">
                    {{ number_format($statsData['rentals']['count']) }}
                </div>
                <p class="fi-wi-stats-overview-stat-description tabular-nums">
                    {{ number_format($statsData['rentals']['revenue'], 2, ',', ' ') }} PLN
                </p>
            </div>
        </div>
        @endif

    </div>

    {{-- Revenue Chart --}}
    <x-filament::section heading="Przychody — wybrany okres" class="mb-6">
        <div
            wire:ignore
            class="h-72"
            x-data="revenueChart(@js($chartData['series']), @js($chartData['categories']))"
            x-on:chart-refresh.window="refreshChart($event.detail)"
        ></div>
    </x-filament::section>

    {{-- Top Services Table --}}
    <x-filament::section heading="Top 10 usług wg przychodu">
        @if(count($topServices) > 0)
        <div class="overflow-x-auto">
            <table class="fi-ta-table w-full text-start">
                <thead class="divide-y divide-gray-200 dark:divide-white/10">
                    <tr class="bg-gray-50 dark:bg-white/5">
                        <th class="fi-ta-header-cell px-3 py-3.5 text-start w-10">
                            <span class="text-sm font-semibold text-gray-950 dark:text-white">#</span>
                        </th>
                        <th class="fi-ta-header-cell px-3 py-3.5 text-start">
                            <span class="text-sm font-semibold text-gray-950 dark:text-white">Usługa</span>
                        </th>
                        <th class="fi-ta-header-cell px-3 py-3.5 text-end w-20">
                            <span class="text-sm font-semibold text-gray-950 dark:text-white">Szt.</span>
                        </th>
                        <th class="fi-ta-header-cell px-3 py-3.5 text-end w-36">
                            <span class="text-sm font-semibold text-gray-950 dark:text-white">Przychód</span>
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-white/5">
                    @foreach($topServices as $index => $service)
                    <tr class="hover:bg-gray-50 dark:hover:bg-white/5 transition-colors duration-100">
                        <td class="px-3 py-4 text-gray-400 dark:text-gray-500 font-mono text-xs">{{ $index + 1 }}</td>
                        <td class="px-3 py-4 text-sm text-gray-950 dark:text-white font-medium">{{ $service['name'] }}</td>
                        <td class="px-3 py-4 text-end text-sm text-gray-500 dark:text-gray-400 tabular-nums">{{ number_format($service['count']) }}</td>
                        <td class="px-3 py-4 text-end text-sm font-semibold text-gray-950 dark:text-white tabular-nums">
                            {{ number_format($service['revenue'], 2, ',', ' ') }} PLN
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @else
        <x-filament::empty-state
            icon="heroicon-o-chart-bar"
            heading="Brak danych o usługach"
            description="W wybranym okresie nie odnotowano sprzedaży żadnej usługi."
        />
        @endif
    </x-filament::section>
</x-filament-panels::page>

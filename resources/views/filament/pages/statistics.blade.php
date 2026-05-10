<x-filament-panels::page>
    @php
        $statsData = $this->getStatsData();
        $chartData = $this->getChartData();
        $topServices = $this->getTopServices();
    @endphp

    {{-- Period selector --}}
    <div class="flex flex-wrap gap-2 mb-6">
        @foreach($this->periodOptions() as $option)
            <a
                href="{{ request()->url() }}?period={{ $option['value'] }}"
                @class([
                    'inline-flex items-center px-4 py-2 rounded-lg text-sm font-medium transition-colors duration-150',
                    'bg-primary-600 text-white' => $this->period === $option['value'],
                    'bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 border border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-700' => $this->period !== $option['value'],
                ])
            >
                {{ $option['label'] }}
            </a>
        @endforeach
    </div>

    {{-- KPI Cards --}}
    <div class="grid grid-cols-2 gap-4 md:grid-cols-4 mb-6">
        <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-5">
            <div class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-1">Przychód łączny</div>
            <div class="text-2xl font-bold text-gray-900 dark:text-white">
                {{ number_format($statsData['total_revenue'], 2, ',', ' ') }} PLN
            </div>
        </div>

        <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-5">
            <div class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-1">Zamówienia</div>
            <div class="text-2xl font-bold text-gray-900 dark:text-white">{{ number_format($statsData['orders']['count']) }}</div>
            <div class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                {{ number_format($statsData['orders']['revenue'], 2, ',', ' ') }} PLN
            </div>
        </div>

        <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-5">
            <div class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-1">Wizyty</div>
            <div class="text-2xl font-bold text-gray-900 dark:text-white">{{ number_format($statsData['appointments']['count']) }}</div>
            <div class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                {{ number_format($statsData['appointments']['revenue'], 2, ',', ' ') }} PLN
            </div>
        </div>

        <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-5">
            <div class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-1">Wypożyczenia</div>
            <div class="text-2xl font-bold text-gray-900 dark:text-white">{{ number_format($statsData['rentals']['count']) }}</div>
            <div class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                {{ number_format($statsData['rentals']['revenue'], 2, ',', ' ') }} PLN
            </div>
        </div>
    </div>

    {{-- Revenue chart (last 30 days) --}}
    <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-5 mb-6">
        <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-4">Przychód — ostatnie 30 dni</h3>
        <div style="height: 200px; position: relative;">
            <canvas
                id="revenue-chart"
                wire:ignore
                data-labels="{{ json_encode($chartData['labels']) }}"
                data-values="{{ json_encode($chartData['totals']) }}"
            ></canvas>
        </div>
    </div>

    {{-- Top services table --}}
    @if(count($topServices) > 0)
    <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
        <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700">
            <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300">Top 10 usług wg przychodu</h3>
        </div>
        <table class="w-full text-sm">
            <thead>
                <tr class="bg-gray-50 dark:bg-gray-700/50">
                    <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">#</th>
                    <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">Usługa</th>
                    <th class="px-5 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">Szt.</th>
                    <th class="px-5 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">Przychód</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                @foreach($topServices as $index => $service)
                <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/30 transition-colors duration-100">
                    <td class="px-5 py-3 text-gray-400 dark:text-gray-500">{{ $index + 1 }}</td>
                    <td class="px-5 py-3 text-gray-900 dark:text-white font-medium">{{ $service['name'] }}</td>
                    <td class="px-5 py-3 text-right text-gray-600 dark:text-gray-400">{{ number_format($service['count']) }}</td>
                    <td class="px-5 py-3 text-right font-semibold text-gray-900 dark:text-white">
                        {{ number_format($service['revenue'], 2, ',', ' ') }} PLN
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @else
    <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-8 text-center">
        <p class="text-gray-500 dark:text-gray-400 text-sm">Brak danych o usługach w wybranym okresie.</p>
    </div>
    @endif
</x-filament-panels::page>

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const canvas = document.getElementById('revenue-chart');
    if (!canvas || typeof Chart === 'undefined') return;

    const labels = JSON.parse(canvas.dataset.labels || '[]');
    const values = JSON.parse(canvas.dataset.values || '[]');
    const isDark = document.documentElement.classList.contains('dark');
    const gridColor = isDark ? 'rgba(255,255,255,0.08)' : 'rgba(0,0,0,0.06)';
    const tickColor = isDark ? '#9ca3af' : '#6b7280';

    new Chart(canvas, {
        type: 'line',
        data: {
            labels,
            datasets: [{
                label: 'Przychód (PLN)',
                data: values,
                borderColor: '#3D8A94',
                backgroundColor: 'rgba(61,138,148,0.12)',
                fill: true,
                tension: 0.4,
                pointRadius: 2,
                pointHoverRadius: 5,
            }],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                x: { grid: { color: gridColor }, ticks: { color: tickColor, maxTicksLimit: 10 } },
                y: { grid: { color: gridColor }, ticks: { color: tickColor, callback: v => v.toLocaleString('pl') + ' PLN' } },
            },
        },
    });
});
</script>
@endpush

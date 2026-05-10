import ApexCharts from 'apexcharts';

// Brand colors from design-system.json
const COLORS = {
    orders:       '#3D8A94',  // primary.500
    appointments: '#34C759',  // semantic.success
    rentals:      '#FF9500',  // semantic.warning
};

function buildOptions(series, categories, isDark) {
    return {
        chart: {
            type: 'area',
            height: '100%',
            toolbar: { show: false },
            fontFamily: 'inherit',
            background: 'transparent',
            animations: { enabled: true, easing: 'easeinout', speed: 400 },
        },
        series,
        xaxis: {
            categories,
            labels: { style: { colors: isDark ? '#9ca3af' : '#6b7280', fontSize: '11px' }, rotate: 0 },
            axisBorder: { show: false },
            axisTicks: { show: false },
            tickAmount: Math.min(categories.length, 10),
        },
        yaxis: {
            labels: {
                style: { colors: isDark ? '#9ca3af' : '#6b7280', fontSize: '11px' },
                formatter: (val) => val.toLocaleString('pl-PL', { minimumFractionDigits: 0, maximumFractionDigits: 0 }) + ' PLN',
            },
        },
        colors: [COLORS.orders, COLORS.appointments, COLORS.rentals],
        fill: {
            type: 'gradient',
            gradient: { shadeIntensity: 1, opacityFrom: 0.18, opacityTo: 0.02, stops: [0, 95, 100] },
        },
        stroke: { curve: 'smooth', width: 2 },
        dataLabels: { enabled: false },
        grid: {
            borderColor: isDark ? 'rgba(255,255,255,0.06)' : 'rgba(0,0,0,0.06)',
            strokeDashArray: 4,
            xaxis: { lines: { show: false } },
        },
        legend: {
            show: true,
            position: 'top',
            horizontalAlign: 'right',
            labels: { colors: isDark ? '#d1d5db' : '#374151' },
        },
        tooltip: {
            theme: isDark ? 'dark' : 'light',
            y: {
                formatter: (val) => val.toLocaleString('pl-PL', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' PLN',
            },
            shared: true,
            intersect: false,
        },
    };
}

export function revenueChart(series, categories) {
    return {
        chart: null,
        init() {
            const isDark = document.documentElement.classList.contains('dark');
            this.chart = new ApexCharts(this.$el, buildOptions(series, categories, isDark));
            this.chart.render();
        },
        refreshChart({ series: newSeries, categories: newCategories }) {
            if (!this.chart) return;
            const isDark = document.documentElement.classList.contains('dark');
            this.chart.updateOptions(buildOptions(newSeries, newCategories, isDark), true, true);
        },
        destroy() {
            this.chart?.destroy();
        },
    };
}

import ApexCharts from 'apexcharts';

function niceMax(series) {
    const max = Math.max(...series.flatMap(s => s.data));
    if (max === 0) return 10;
    const step = Math.pow(10, Math.floor(Math.log10(max)));
    return Math.ceil(max / step) * step + step;
}

function buildOptions(series, categories, isDark) {
    return {
        chart: {
            type: 'bar',
            width: '100%',
            height: 300,
            toolbar: { show: false },
            fontFamily: 'inherit',
            background: 'transparent',
            animations: { enabled: true, easing: 'easeinout', speed: 400 },
        },
        plotOptions: {
            bar: { borderRadius: 4, columnWidth: '55%' },
        },
        series,
        xaxis: {
            categories,
            labels: {
                style: { colors: isDark ? '#9ca3af' : '#6b7280', fontSize: '11px' },
                rotate: -45,
                rotateAlways: true,
                formatter: (val, i) => {
                    if (typeof i !== 'number') return val;
                    return i % 2 === 0 ? val : '';
                },
            },
            axisBorder: { show: false },
            axisTicks: { show: false },
        },
        yaxis: {
            max: niceMax(series),
            min: 0,
            labels: {
                style: { colors: isDark ? '#9ca3af' : '#6b7280', fontSize: '11px' },
                formatter: (val) => Math.round(val).toString(),
            },
        },
        colors: ['#3D8A94'],
        dataLabels: { enabled: false },
        grid: {
            borderColor: isDark ? 'rgba(255,255,255,0.06)' : 'rgba(0,0,0,0.06)',
            strokeDashArray: 4,
            xaxis: { lines: { show: false } },
            padding: { top: 0, right: 30, bottom: 0, left: 8 },
        },
        legend: { show: false },
        tooltip: {
            theme: isDark ? 'dark' : 'light',
            y: {
                title: { formatter: () => 'Odsłony: ' },
                formatter: (val) => Math.round(val).toString(),
            },
        },
    };
}

export function analyticsPageviewChart(series, categories) {
    return {
        chart: null,
        _resizeObserver: null,
        init() {
            const isDark = document.documentElement.classList.contains('dark');
            this.chart = new ApexCharts(this.$el, buildOptions(series, categories, isDark));
            this.chart.render();

            this._resizeObserver = new ResizeObserver(() => {
                const w = this.$el.offsetWidth;
                if (w > 0) this.chart?.updateOptions({ chart: { width: w } }, false, false);
            });
            this._resizeObserver.observe(this.$el);
        },
        refreshPageviewChart({ series: newSeries, categories: newCategories }) {
            if (!this.chart) return;
            const isDark = document.documentElement.classList.contains('dark');
            this.chart.updateOptions(buildOptions(newSeries, newCategories, isDark), true, true);
        },
        destroy() {
            this._resizeObserver?.disconnect();
            this.chart?.destroy();
        },
    };
}

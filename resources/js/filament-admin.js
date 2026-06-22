import { revenueChart } from './charts/revenue-chart.js';
import { analyticsPageviewChart } from './charts/analytics-pageview-chart.js';

// Register Alpine components with Filament's existing Alpine instance.
// This script must NOT import or start Alpine itself — Filament manages Alpine.
// Defensive pattern: Livewire 3.8+ may fire alpine:init before this ES module executes.
const registerAlpine = (Alpine) => {
    Alpine.data('revenueChart', revenueChart);
    Alpine.data('analyticsPageviewChart', analyticsPageviewChart);
};

if (window.Alpine) {
    registerAlpine(window.Alpine);
} else {
    document.addEventListener('alpine:init', () => registerAlpine(window.Alpine));
}

import { revenueChart } from './charts/revenue-chart.js';

// Register Alpine components with Filament's existing Alpine instance.
// This script must NOT import or start Alpine itself — Filament manages Alpine.
// The alpine:init event fires before Alpine processes x-data, so registrations land in time.
document.addEventListener('alpine:init', () => {
    Alpine.data('revenueChart', revenueChart);
});

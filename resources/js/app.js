import './bootstrap';
import './booking-wizard';
import { revenueChart } from './charts/revenue-chart.js';
import Alpine from 'alpinejs';
import Focus from '@alpinejs/focus';
import AlpineTrackerPlugin from './tracker/alpine-tracker-plugin.js';

Alpine.plugin(Focus);
Alpine.plugin(AlpineTrackerPlugin);
Alpine.data('revenueChart', revenueChart);
window.Alpine = Alpine;
Alpine.start();

/**
 * Scroll Reveal — Intersection Observer
 *
 * Any element with [data-animate] will fade in when scrolled into view.
 * Optional: data-animate-delay="200" for staggered animations.
 *
 * Usage in Blade:
 *   <div data-animate>Fades in on scroll</div>
 *   <div data-animate data-animate-delay="100">Delayed by 100ms</div>
 */
if (!window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
    const observer = new IntersectionObserver(
        (entries) => {
            entries.forEach((entry) => {
                if (entry.isIntersecting) {
                    const delay = entry.target.dataset.animateDelay || 0;
                    setTimeout(() => {
                        entry.target.classList.add('visible');
                    }, Number(delay));
                    observer.unobserve(entry.target);
                }
            });
        },
        { threshold: 0.1, rootMargin: '0px 0px -40px 0px' }
    );

    document.querySelectorAll('[data-animate]').forEach((el) => {
        observer.observe(el);
    });
}

/**
 * Toast helper — dispatch from anywhere
 *
 * Usage:
 *   window.toast({ message: 'Saved!', variant: 'success' })
 *   window.toast({ message: 'Error occurred', variant: 'error', duration: 8000 })
 */
window.toast = function (options) {
    window.dispatchEvent(new CustomEvent('toast', { detail: options }));
};

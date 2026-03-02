import './bootstrap';
import './booking-wizard';
import Alpine from 'alpinejs';

// Alpine.js service card component
Alpine.data('serviceCard', () => ({
    hover: false,
    showDetails: false,

    toggleDetails() {
        this.showDetails = !this.showDetails;
    }
}));

window.Alpine = Alpine;
Alpine.start();

// Scroll-triggered animations using Intersection Observer (iOS-style)
document.addEventListener('DOMContentLoaded', () => {
    // iOS Spring Animation for service cards
    const observerOptions = {
        threshold: 0.1,
        rootMargin: '0px 0px -50px 0px'
    };

    const observer = new IntersectionObserver((entries) => {
        entries.forEach((entry, index) => {
            if (entry.isIntersecting) {
                // Stagger animation delay for each card
                setTimeout(() => {
                    entry.target.classList.add('animate-fade-in-up');
                    entry.target.style.animationDelay = `${index * 0.1}s`;
                }, 0);

                // Unobserve after animation triggers (performance)
                observer.unobserve(entry.target);
            }
        });
    }, observerOptions);

    // Observe all service cards
    const serviceCards = document.querySelectorAll('.ios-card');
    serviceCards.forEach(card => {
        // Start with opacity 0 for fade-in effect
        card.style.opacity = '0';
        observer.observe(card);
    });
});

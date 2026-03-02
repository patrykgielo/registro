@extends('layouts.app')

@section('content')
<div class="booking-wizard min-h-screen bg-gray-50 pb-8">
    {{-- Header --}}
    <div class="booking-wizard__header bg-white border-b border-gray-200 sticky top-0 z-20">
        <div class="container mx-auto px-4 py-4">
            <div class="flex items-center justify-between">
                {{-- Logo/Back --}}
                <a href="{{ route('home') }}" class="booking-wizard__back-link flex items-center gap-2 text-gray-600 hover:text-gray-900 transition-colors">
                    <x-heroicon-o-arrow-left class="w-5 h-5" />
                    <span class="hidden sm:inline">Strona główna</span>
                </a>

                {{-- Title --}}
                <h1 class="booking-wizard__title text-lg sm:text-xl font-bold text-gray-900">
                    Zarezerwuj usługę
                </h1>

                {{-- Help --}}
                <a href="#" class="booking-wizard__help text-sm text-primary-600 hover:text-primary-700 font-medium">
                    Potrzebujesz pomocy?
                </a>
            </div>
        </div>
    </div>

    {{-- Progress Indicator --}}
    <x-booking-wizard.progress-indicator
        :current-step="$currentStep ?? 1"
        :total-steps="5"
    />

    {{-- Main Content Area --}}
    <div class="booking-wizard__content container mx-auto px-4 py-8">
        <div class="booking-wizard__container max-w-3xl mx-auto">
            {{-- Step Content (injected by child views) --}}
            @yield('step-content')
        </div>
    </div>

</div>

{{-- iOS Spring Animations --}}
@push('styles')
<style>
/* iOS Spring Animation */
.ios-spring {
    transition-timing-function: cubic-bezier(0.68, -0.55, 0.265, 1.55);
}

/* Button press feedback (iOS-like) */
.btn:active:not(:disabled) {
    transform: scale(0.95);
    transition: transform 0.1s cubic-bezier(0.34, 1.56, 0.64, 1);
}

/* Touch targets (minimum 48px for iOS) */
.btn {
    min-height: 48px;
}

.btn--primary {
    min-height: 56px; /* Primary CTAs get 56px */
}
</style>
@endpush

{{-- Session Persistence (Laravel Session) + AJAX Form Handler --}}
@push('scripts')
<script>
// Booking Wizard State Management
const bookingWizard = {
    currentStep: {{ $currentStep ?? 1 }},
    isSubmitting: false,

    // Auto-save state to Laravel session via AJAX
    async saveProgress(step, data) {
        try {
            const response = await fetch('{{ route('booking.save-progress') }}', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}'
                },
                body: JSON.stringify({
                    step: step,
                    data: data
                })
            });
            const result = await response.json();
            return { response, result };
        } catch (error) {
            console.error('Failed to save progress:', error);
            return { response: { ok: false }, result: { success: false, message: 'Błąd połączenia' } };
        }
    },

    // Navigate to next step (client-side only, no page reload)
    goToStep(step) {
        if (this.isSubmitting) return;
        window.location.href = '{{ route('booking.step', ['step' => '__STEP__']) }}'.replace('__STEP__', step);
    }
};

// Intercept form submissions to use AJAX instead of POST-redirect
document.addEventListener('DOMContentLoaded', () => {
    const wizardForm = document.getElementById('{{ $formId ?? 'booking-form' }}');

    if (wizardForm) {
        // Step 5 (Review/Confirmation) should NOT use AJAX - let it submit naturally
        // This allows the controller to redirect to the confirmation page
        if (bookingWizard.currentStep === 5) {
            console.log('Step 5 detected - allowing natural form submission (no AJAX)');
            return; // Exit early - don't attach AJAX handler
        }

        wizardForm.addEventListener('submit', async (event) => {
            event.preventDefault();

            if (bookingWizard.isSubmitting) return;

            // CRITICAL: Step 3 (Vehicle & Location) requires validation
            if (bookingWizard.currentStep === 3) {
                // Dispatch custom event to trigger Alpine validation (inline errors + scroll)
                const validationEvent = new CustomEvent('validate-step3', {
                    detail: { valid: false },
                    bubbles: true
                });
                window.dispatchEvent(validationEvent);

                // Get Alpine.js component data from form element
                const alpineData = wizardForm._x_dataStack?.[0];

                if (alpineData) {
                    // Check if form can be submitted (service area validation)
                    if (typeof alpineData.validateLocationBeforeSubmit === 'function') {
                        if (!alpineData.validateLocationBeforeSubmit()) {
                            console.log('Step 3 validation failed - blocking submission');
                            return; // Block submission
                        }
                    }
                }

                // Check the validation result (set by Alpine handler)
                if (wizardForm.dataset.validationFailed === 'true') {
                    console.log('Step 3 validation failed via data attribute');
                    wizardForm.dataset.validationFailed = ''; // Reset flag
                    return; // Block submission
                }
            }

            // CRITICAL: Step 4 (Contact) requires client-side validation before AJAX
            if (bookingWizard.currentStep === 4) {
                // Dispatch custom event to trigger Alpine validation
                const validationEvent = new CustomEvent('validate-step4', {
                    detail: { valid: false },
                    bubbles: true
                });
                wizardForm.dispatchEvent(validationEvent);

                // Check the validation result (set by Alpine handler)
                if (wizardForm.dataset.validationFailed === 'true') {
                    console.log('Step 4 validation failed - blocking submission');
                    wizardForm.dataset.validationFailed = ''; // Reset flag
                    return; // Block submission - Alpine already showed inline errors
                }
            }

            // CRITICAL: Step 4 (Contact) requires client-side validation before AJAX
            if (bookingWizard.currentStep === 4) {
                // Dispatch custom event to trigger Alpine validation
                const validationEvent = new CustomEvent('validate-step4', {
                    detail: { valid: false },
                    bubbles: true
                });
                wizardForm.dispatchEvent(validationEvent);

                // Check the validation result (set by Alpine handler)
                if (wizardForm.dataset.validationFailed === 'true') {
                    console.log('Step 4 validation failed - blocking submission');
                    wizardForm.dataset.validationFailed = ''; // Reset flag
                    return; // Block submission - Alpine already showed inline errors
                }
            }

            bookingWizard.isSubmitting = true;

            const formData = new FormData(wizardForm);
            const data = Object.fromEntries(formData.entries());

            // Get submit button reference (it's outside form, in sticky footer)
            const submitBtn = document.querySelector('button[form="{{ $formId ?? 'booking-form' }}"]')
                           || document.querySelector('.booking-wizard__next[type="submit"]');
            let originalText = null;

            try {
                if (!submitBtn) {
                    console.error('Submit button not found');
                    bookingWizard.isSubmitting = false;
                    return;
                }

                // Show loading state
                originalText = submitBtn.innerHTML;
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<svg class="animate-spin h-5 w-5 mx-auto" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>';

                // Save progress via AJAX
                const { response, result } = await bookingWizard.saveProgress(bookingWizard.currentStep, data);

                if (response.ok && result.success !== false) {
                    // Success - reset state BEFORE navigation (prevent race condition)
                    bookingWizard.isSubmitting = false;

                    // Navigate to next step WITHOUT page reload warning
                    bookingWizard.goToStep(bookingWizard.currentStep + 1);
                } else {
                    // Validation errors - reset state and display them
                    bookingWizard.isSubmitting = false;
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = originalText;

                    if (result.errors) {
                        // Show validation errors
                        let errorMessages = Object.values(result.errors).flat().join('\n');
                        alert(result.message + '\n\n' + errorMessages);
                    } else {
                        alert(result.message || 'Wystąpił błąd podczas zapisywania. Spróbuj ponownie.');
                    }
                }
            } catch (error) {
                console.error('Form submission error:', error);

                // Restore button state
                bookingWizard.isSubmitting = false;
                if (submitBtn && originalText) {
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = originalText;
                }

                alert('Wystąpił błąd połączenia. Sprawdź połączenie internetowe i spróbuj ponownie.');
            }
        });
    }
});

// Legacy function for backward compatibility
function saveBookingProgress(step, data) {
    return bookingWizard.saveProgress(step, data);
}

// ===== SESSION TIMEOUT HANDLING =====
// Warn user if session is about to expire (Laravel default: 120 min)
const sessionTimeoutHandler = {
    // Session lifetime in milliseconds (from Laravel config, default 120 min)
    sessionLifetime: {{ config('session.lifetime', 120) }} * 60 * 1000,
    // Warn 5 minutes before expiration
    warningTime: 5 * 60 * 1000,
    lastActivity: Date.now(),
    warningShown: false,
    timeoutWarningModal: null,

    init() {
        // Track user activity
        ['click', 'keypress', 'scroll', 'mousemove'].forEach(event => {
            document.addEventListener(event, () => this.updateActivity(), { passive: true });
        });

        // Check session periodically (every 60 seconds)
        setInterval(() => this.checkSession(), 60000);

        // Create warning modal
        this.createWarningModal();

        console.log('Session timeout handler initialized');
    },

    updateActivity() {
        this.lastActivity = Date.now();
        if (this.warningShown) {
            this.hideWarning();
        }
    },

    checkSession() {
        const elapsed = Date.now() - this.lastActivity;
        const remaining = this.sessionLifetime - elapsed;

        if (remaining <= this.warningTime && !this.warningShown) {
            this.showWarning(remaining);
        }

        if (remaining <= 0) {
            this.handleExpired();
        }
    },

    createWarningModal() {
        const modal = document.createElement('div');
        modal.id = 'session-timeout-warning';
        modal.className = 'fixed inset-0 z-[9999] hidden items-center justify-center bg-black/50';
        modal.innerHTML = `
            <div class="bg-white rounded-2xl p-6 max-w-sm mx-4 shadow-2xl">
                <div class="flex items-center gap-3 mb-4">
                    <div class="w-12 h-12 rounded-full bg-yellow-100 flex items-center justify-center flex-shrink-0">
                        <svg class="w-6 h-6 text-yellow-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    </div>
                    <div>
                        <h3 class="text-lg font-bold text-gray-900">Sesja wygasa</h3>
                        <p class="text-sm text-gray-600">Twoja sesja wkrótce wygaśnie z powodu braku aktywności.</p>
                    </div>
                </div>
                <div class="text-center mb-4">
                    <span class="text-2xl font-bold text-primary-600" id="session-countdown">5:00</span>
                </div>
                <button
                    onclick="sessionTimeoutHandler.refreshSession()"
                    class="w-full px-4 py-3 bg-primary-600 text-white font-semibold rounded-xl hover:bg-primary-700 transition-colors"
                >
                    Przedłuż sesję
                </button>
            </div>
        `;
        document.body.appendChild(modal);
        this.timeoutWarningModal = modal;
    },

    showWarning(remaining) {
        this.warningShown = true;
        this.timeoutWarningModal.classList.remove('hidden');
        this.timeoutWarningModal.classList.add('flex');

        // Start countdown
        this.updateCountdown(remaining);
        this.countdownInterval = setInterval(() => {
            const elapsed = Date.now() - this.lastActivity;
            const remaining = this.sessionLifetime - elapsed;
            if (remaining > 0) {
                this.updateCountdown(remaining);
            } else {
                this.handleExpired();
            }
        }, 1000);
    },

    hideWarning() {
        this.warningShown = false;
        this.timeoutWarningModal.classList.add('hidden');
        this.timeoutWarningModal.classList.remove('flex');
        if (this.countdownInterval) {
            clearInterval(this.countdownInterval);
        }
    },

    updateCountdown(remaining) {
        const minutes = Math.floor(remaining / 60000);
        const seconds = Math.floor((remaining % 60000) / 1000);
        const countdownEl = document.getElementById('session-countdown');
        if (countdownEl) {
            countdownEl.textContent = `${minutes}:${seconds.toString().padStart(2, '0')}`;
        }
    },

    async refreshSession() {
        try {
            // Make a simple request to refresh the session
            const response = await fetch('{{ route("booking.restore-progress") }}', {
                method: 'GET',
                headers: {
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    'Accept': 'application/json'
                }
            });

            if (response.ok) {
                this.lastActivity = Date.now();
                this.hideWarning();
                console.log('Session refreshed successfully');
            } else {
                throw new Error('Failed to refresh session');
            }
        } catch (error) {
            console.error('Session refresh failed:', error);
            this.handleExpired();
        }
    },

    handleExpired() {
        this.hideWarning();

        // Show expired message and redirect
        alert('Twoja sesja rezerwacji wygasła. Zostaniesz przekierowany na stronę główną.');
        window.location.href = '{{ route("home") }}';
    }
};

// Initialize session timeout handler
document.addEventListener('DOMContentLoaded', () => {
    sessionTimeoutHandler.init();
});
</script>
@endpush
@endsection

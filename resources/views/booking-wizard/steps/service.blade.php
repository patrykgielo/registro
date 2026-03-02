@extends('booking-wizard.layout', [
    'currentStep' => 1,
    'nextButtonText' => 'Wybierz termin',
    'formId' => 'service-selection-form',
])

@section('step-content')
<div class="service-selection fade-in -mx-4 sm:-mx-6 lg:-mx-8 px-4 sm:px-6 lg:px-8 py-8 bg-section-dark rounded-xl">
    {{-- Step Title --}}
    <div class="service-selection__header text-center mb-8">
        <h2 class="service-selection__title text-3xl sm:text-4xl font-bold text-white mb-3">
            Wybierz usługę
        </h2>
        <p class="service-selection__subtitle text-lg text-white/70">
            Wybierz usługę detailingu dla Twojego pojazdu
        </p>
    </div>

    {{-- Form --}}
    <form
        id="service-selection-form"
        method="POST"
        action="{{ route('booking.step.store', ['step' => 1]) }}"
        class="service-selection__form"
    >
        @csrf

        {{-- Service Cards Grid --}}
        <div class="service-selection__grid grid grid-cols-1 md:grid-cols-2 gap-6">
            @foreach($services as $service)
                <label
                    class="service-selection__card-wrapper cursor-pointer"
                    for="service-{{ $service->id }}"
                >
                    <input
                        type="radio"
                        id="service-{{ $service->id }}"
                        name="service_id"
                        value="{{ $service->id }}"
                        class="service-selection__radio sr-only"
                        {{ old('service_id', session('booking.service_id')) == $service->id ? 'checked' : '' }}
                        required
                        onchange="this.form.requestSubmit()"
                    >

                    {{-- Service Card (using existing ios/service-card component with BEM) --}}
                    <x-ios.service-card
                        :service="$service"
                        :show-cta="false"
                        variant="dark"
                    />
                </label>
            @endforeach
        </div>

        {{-- Validation Error (Dark Theme) --}}
        @error('service_id')
            <div class="service-selection__error mt-4 p-4 bg-red-500/20 border border-red-400/30 rounded-xl text-red-300 text-sm">
                <div class="flex items-center gap-2">
                    <x-heroicon-s-exclamation-circle class="w-5 h-5 flex-shrink-0" />
                    <span>{{ $message }}</span>
                </div>
            </div>
        @enderror
    </form>

    {{-- Trust Signals (below cards) - Dark Theme --}}
    <div class="service-selection__trust-signals mt-8 pt-8 border-t border-white/20">
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-6">
            {{-- Trust Signal 1: Total Bookings --}}
            <div class="service-selection__trust-item flex items-center gap-3 text-white/70">
                <div class="service-selection__trust-icon w-12 h-12 rounded-full bg-[#0AB1EA]/20 flex items-center justify-center flex-shrink-0">
                    <x-heroicon-s-check-circle class="w-6 h-6 text-[#0AB1EA]" />
                </div>
                <div>
                    <div class="text-2xl font-bold text-white">{{ $totalBookings ?? 0 }}+</div>
                    <div class="text-sm">Zadowolonych klientów</div>
                </div>
            </div>

            {{-- Trust Signal 2: Free Cancellation --}}
            <div class="service-selection__trust-item flex items-center gap-3 text-white/70">
                <div class="service-selection__trust-icon w-12 h-12 rounded-full bg-[#0AB1EA]/20 flex items-center justify-center flex-shrink-0">
                    <x-heroicon-s-shield-check class="w-6 h-6 text-[#0AB1EA]" />
                </div>
                <div>
                    <div class="text-lg font-bold text-white">Darmowa anulacja</div>
                    <div class="text-sm">Do 24h przed wizytą</div>
                </div>
            </div>

            {{-- Trust Signal 3: Secure Payment --}}
            <div class="service-selection__trust-item flex items-center gap-3 text-white/70">
                <div class="service-selection__trust-icon w-12 h-12 rounded-full bg-[#0AB1EA]/20 flex items-center justify-center flex-shrink-0">
                    <x-heroicon-s-lock-closed class="w-6 h-6 text-[#0AB1EA]" />
                </div>
                <div>
                    <div class="text-lg font-bold text-white">Bezpiecznie</div>
                    <div class="text-sm">Twoje dane są chronione</div>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Auto-select on card click (better UX than clicking radio) --}}
@push('scripts')
<script>
// Auto-submit when service card clicked
document.querySelectorAll('.service-selection__card-wrapper').forEach(card => {
    card.addEventListener('click', function() {
        const radio = this.querySelector('input[type="radio"]');
        if (radio && !radio.checked) {
            radio.checked = true;
            radio.form.requestSubmit();
        }
    });
});

// Visual feedback on selected card (using CTA blue for dark theme)
document.querySelectorAll('.service-selection__radio').forEach(radio => {
    radio.addEventListener('change', function() {
        // Remove selection from all cards
        document.querySelectorAll('.service-card').forEach(card => {
            card.classList.remove('ring-4', 'ring-[#0AB1EA]', 'border-[#0AB1EA]');
        });

        // Add selection to current card
        if (this.checked) {
            const card = this.closest('.service-selection__card-wrapper').querySelector('.service-card');
            card.classList.add('ring-4', 'ring-[#0AB1EA]', 'border-[#0AB1EA]');
        }
    });

    // Trigger on page load for pre-selected service
    if (radio.checked) {
        radio.dispatchEvent(new Event('change'));
    }
});

// Save progress to session
const serviceRadios = document.querySelectorAll('input[name="service_id"]');
serviceRadios.forEach(radio => {
    radio.addEventListener('change', function() {
        saveBookingProgress(1, {
            service_id: this.value
        });
    });
});
</script>
@endpush

{{-- Fade-in animation --}}
@push('styles')
<style>
.fade-in {
    animation: fadeIn 0.5s ease-out;
}

@keyframes fadeIn {
    from {
        opacity: 0;
        transform: translateY(20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

/* Service card selection state */
.service-card {
    transition: all 0.3s cubic-bezier(0.68, -0.55, 0.265, 1.55);
}

.service-selection__card-wrapper:hover .service-card {
    transform: translateY(-4px);
    box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1), 0 8px 10px -6px rgba(0, 0, 0, 0.1);
}

.service-selection__card-wrapper:active .service-card {
    transform: scale(0.98);
}
</style>
@endpush
@endsection

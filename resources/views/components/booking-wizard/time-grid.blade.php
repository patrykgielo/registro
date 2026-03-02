@props([
    'date' => null,
    'serviceId' => null,
    'selectedTime' => null,
])

<div
    class="time-grid"
    x-data="timeGridWidget(@js($date), @js($serviceId), @js($selectedTime))"
    @date-selected.window="loadTimeSlots($event.detail.date)"
>
    {{-- Hidden input for form submission --}}
    <input
        type="hidden"
        name="time_slot"
        x-model="selectedTime"
        required
    >

    {{-- Header --}}
    <div class="time-grid__header mb-6" x-show="date">
        <h3 class="time-grid__title text-xl font-bold text-gray-900 mb-1">
            Dostępne Godziny
        </h3>
        <p class="time-grid__subtitle text-sm text-gray-600" x-text="formatDate(date)"></p>
    </div>

    {{-- Loading State (Skeleton) --}}
    <div class="time-grid__loading" x-show="loading" x-cloak>
        <div class="time-grid__slots grid grid-cols-4 gap-3">
            <template x-for="i in 12" :key="i">
                <div class="skeleton skeleton--rect h-14 rounded-xl"></div>
            </template>
        </div>
    </div>

    {{-- Time Slots Grid --}}
    <div class="time-grid__slots-wrapper" x-show="!loading && timeSlots.length > 0" x-cloak>
        <div class="time-grid__slots grid grid-cols-3 sm:grid-cols-4 gap-3">
            <template x-for="slot in timeSlots" :key="slot.time">
                <button
                    type="button"
                    @click="selectTimeSlot(slot)"
                    :disabled="!slot.available"
                    :class="{
                        '!bg-primary-400 !border-primary-400 !text-white shadow-lg': selectedTime === slot.time,
                        '!border-primary-400 !bg-primary-50': selectedTime !== slot.time && !slot.available === false
                    }"
                    class="time-grid__slot relative flex flex-col items-center justify-center min-h-[56px] px-4 py-3 bg-white border-2 border-gray-200 rounded-xl transition-all duration-200 ios-spring text-gray-900 font-medium disabled:opacity-30 disabled:cursor-not-allowed disabled:bg-gray-50 hover:border-primary-400 hover:bg-primary-50 hover:shadow-md"
                >
                    <span class="time-grid__slot-time text-base font-bold" x-text="slot.time"></span>

                    {{-- Unavailable status --}}
                    <template x-if="!slot.available">
                        <span class="time-grid__slot-status text-xs text-gray-500 mt-1">
                            Niedostępne
                        </span>
                    </template>

                    {{-- Urgency indicator (only X left) --}}
                    <template x-if="slot.available && slot.spotsLeft && slot.spotsLeft <= 3">
                        <span class="time-grid__slot-urgency text-xs text-error font-semibold mt-1 flex items-center gap-1">
                            🔥 Tylko <span x-text="slot.spotsLeft"></span>
                        </span>
                    </template>
                </button>
            </template>
        </div>
    </div>

    {{-- Empty State (No slots available) --}}
    <div
        class="time-grid__empty text-center py-12"
        x-show="!loading && date && timeSlots.length === 0"
        x-cloak
    >
        <div class="time-grid__empty-icon w-16 h-16 rounded-full bg-gray-100 flex items-center justify-center mx-auto mb-4">
            <svg class="w-8 h-8 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
        </div>
        <p class="time-grid__empty-text text-lg font-medium text-gray-900 mb-2">
            Brak dostępnych terminów
        </p>
        <p class="text-sm text-gray-600 mb-4">
            Na ten dzień wszystkie terminy są zajęte. Wybierz inny dzień.
        </p>
        <button
            type="button"
            @click="$dispatch('clear-date-selection')"
            class="time-grid__empty-action btn btn--secondary"
        >
            Wybierz Inny Dzień
        </button>
    </div>

    {{-- Placeholder (No date selected yet) --}}
    <div
        class="time-grid__placeholder text-center py-12"
        x-show="!date"
        x-cloak
    >
        <div class="time-grid__placeholder-icon w-16 h-16 rounded-full bg-primary-100 flex items-center justify-center mx-auto mb-4">
            <svg class="w-8 h-8 text-primary-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
            </svg>
        </div>
        <p class="text-lg font-medium text-gray-900 mb-2">
            Wybierz datę z kalendarza
        </p>
        <p class="text-sm text-gray-600">
            Dostępne godziny pojawią się po wyborze daty
        </p>
    </div>
</div>

@push('scripts')
<script>
function timeGridWidget(initialDate, serviceId, initialSelectedTime) {
    return {
        date: initialDate,
        serviceId: serviceId,
        selectedTime: initialSelectedTime,
        timeSlots: [],
        loading: false,

        init() {
            if (this.date) {
                this.loadTimeSlots(this.date);
            }
        },

        async loadTimeSlots(date) {
            if (!date || !this.serviceId) return;

            this.date = date;
            this.loading = true;
            this.timeSlots = [];

            try {
                const response = await fetch(`/booking/available-slots?service_id=${this.serviceId}&date=${date}`);
                const data = await response.json();
                this.timeSlots = data.slots || [];


                // Auto-select if only one slot available
                if (this.timeSlots.length === 1 && this.timeSlots[0].available) {
                    this.selectTimeSlot(this.timeSlots[0]);
                }
            } catch (error) {
                console.error('Failed to load time slots:', error);
            } finally {
                this.loading = false;
            }
        },

        selectTimeSlot(slot) {
            if (!slot.available) {
                return;
            }

            this.selectedTime = slot.time;

            // Save to session
            this.saveProgress();

            // Haptic feedback (iOS)
            if (window.navigator && window.navigator.vibrate) {
                window.navigator.vibrate(10);
            }
        },

        formatDate(dateStr) {
            if (!dateStr) return '';
            const date = new Date(dateStr);
            return date.toLocaleDateString('pl-PL', {
                weekday: 'long',
                year: 'numeric',
                month: 'long',
                day: 'numeric'
            });
        },

        saveProgress() {
            fetch('{{ route('booking.save-progress') }}', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}'
                },
                body: JSON.stringify({
                    step: 2,
                    data: {
                        date: this.date,
                        time_slot: this.selectedTime
                    }
                })
            });
        }
    }
}
</script>
@endpush

@push('styles')
<style>
/* Time Grid Component */
.time-grid__slots {
    @apply grid gap-3;
}

/* 3 columns on very small screens, 4 on sm+ (research: 4 per row mobile) */
@media (max-width: 480px) {
    .time-grid__slots {
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 0.5rem;
    }
}

@media (min-width: 481px) {
    .time-grid__slots {
        grid-template-columns: repeat(4, minmax(0, 1fr));
    }
}

.time-grid__slot {
    /* Ensure 56px minimum touch target (iOS guideline) */
    min-height: 56px;
    min-width: 64px;
}

.time-grid__slot:not(:disabled):not(.time-grid__slot--selected):hover {
    @apply border-primary-400 bg-primary-50 shadow-md;
}

.time-grid__slot:not(:disabled):active {
    transform: scale(0.95); /* iOS press feedback */
}

.time-grid__slot--selected {
    @apply bg-primary-400 border-primary-400 text-white shadow-lg;
}

.time-grid__slot--selected:hover {
    @apply bg-primary-600 border-primary-600;
}

.time-grid__slot--unavailable {
    @apply opacity-30 cursor-not-allowed bg-gray-50 border-gray-200;
}

/* Skeleton loader (shimmer effect) */
.skeleton {
    @apply bg-gray-200 rounded;
    animation: shimmer 2s infinite;
    background: linear-gradient(
        90deg,
        #f0f0f0 25%,
        #e0e0e0 50%,
        #f0f0f0 75%
    );
    background-size: 200% 100%;
}

@keyframes shimmer {
    0% {
        background-position: -100% 0;
    }
    100% {
        background-position: 100% 0;
    }
}

.skeleton--rect {
    @apply h-14 w-full;
}

/* Alpine x-cloak (hide until initialized) */
[x-cloak] {
    display: none !important;
}
</style>
@endpush

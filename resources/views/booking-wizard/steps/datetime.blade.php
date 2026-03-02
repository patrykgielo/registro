@extends('booking-wizard.layout', [
    'currentStep' => 2,
    'nextButtonText' => 'Dalej',
    'formId' => 'datetime-selection-form',
    'backUrl' => route('booking.step', ['step' => 1]),
])

@section('step-content')
<div class="datetime-selection fade-in">
    {{-- Step Title --}}
    <div class="datetime-selection__header text-center mb-8">
        <h2 class="datetime-selection__title text-3xl sm:text-4xl font-bold text-gray-900 mb-3">
            Wybierz datę i godzinę
        </h2>
        <p class="datetime-selection__subtitle text-lg text-gray-600">
            {{ $service->name }} ({{ $service->duration_minutes }} min)
        </p>
    </div>

    {{-- Form --}}
    <form
        id="datetime-selection-form"
        method="POST"
        action="{{ route('booking.step.store', ['step' => 2]) }}"
        class="datetime-selection__form"
        x-data="{ canSubmit: false }"
        @time-selected.window="canSubmit = true"
    >
        @csrf

        {{-- Service Info - Full width, above grid --}}
        <div class="datetime-selection__service-info mb-6 bg-orange-50 rounded-xl p-4 border border-orange-200">
            <div class="flex items-start gap-3">
                <div class="datetime-selection__service-icon w-12 h-12 rounded-xl bg-gradient-to-br from-orange-500 to-orange-600 flex items-center justify-center flex-shrink-0">
                    <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                    </svg>
                </div>
                <div>
                    <h4 class="font-bold text-gray-900 mb-1">{{ $service->name }}</h4>
                    <div class="text-sm text-gray-600 space-y-1">
                        <div class="flex items-center gap-2">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                            <span>{{ $service->duration_minutes }} minut</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1" />
                            </svg>
                            <span>{{ number_format($service->price_from ?? $service->price, 0, ',', ' ') }} zł</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="datetime-selection__grid grid grid-cols-1 lg:grid-cols-2 gap-8">
            {{-- Left Column: Calendar --}}
            <div class="datetime-selection__calendar-section">
                <div class="bg-white rounded-2xl p-6 shadow-md border border-gray-200">
                    <h3 class="text-lg font-bold text-gray-900 mb-4 flex items-center gap-2">
                        <svg class="w-5 h-5 text-orange-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                        </svg>
                        Wybierz Datę
                    </h3>

                    <x-booking-wizard.calendar
                        :service-id="$service->id"
                        :selected-date="session('booking.date')"
                        min-date="today"
                    />
                </div>
            </div>

            {{-- Right Column: Time Slots --}}
            <div class="datetime-selection__timeslots-section">
                <div class="bg-white rounded-2xl p-6 shadow-md border border-gray-200">
                    <h3 class="text-lg font-bold text-gray-900 mb-4 flex items-center gap-2">
                        <svg class="w-5 h-5 text-orange-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        Wybierz Godzinę
                    </h3>

                    <x-booking-wizard.time-grid
                        :date="session('booking.date')"
                        :service-id="$service->id"
                        :selected-time="session('booking.time_slot')"
                        @time-selected.window="canSubmit = true"
                    />
                </div>
            </div>
        </div>

        {{-- Validation Errors --}}
        @if($errors->any())
            <div class="datetime-selection__errors mt-6 p-4 bg-red-50 border border-red-200 rounded-xl text-red-700">
                <div class="flex items-start gap-2">
                    <svg class="w-5 h-5 flex-shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" />
                    </svg>
                    <div>
                        <p class="font-semibold mb-1">Proszę popraw następujące błędy:</p>
                        <ul class="list-disc list-inside space-y-1 text-sm">
                            @foreach($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            </div>
        @endif

        {{-- Navigation Buttons --}}
        <div class="booking-wizard__form-actions mt-8 space-y-4">
            <button
                type="submit"
                class="booking-wizard__next w-full min-h-14 px-6 py-4 bg-primary-500 hover:bg-primary-600
                       text-white font-semibold text-lg rounded-xl
                       transition-all duration-200 ease-out
                       active:scale-[0.98]
                       focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-500
                       flex items-center justify-center gap-2"
            >
                <span>Dalej</span>
                <x-heroicon-m-arrow-right class="w-5 h-5" />
            </button>

            <a href="{{ route('booking.change-service') }}"
               class="w-full min-h-11 px-6 py-3 bg-gray-100 hover:bg-gray-200
                      text-gray-700 font-medium rounded-xl
                      transition-all duration-200 ease-out
                      flex items-center justify-center gap-2">
                <x-heroicon-m-arrow-uturn-left class="w-5 h-5" />
                <span>Zmień usługę</span>
            </a>
        </div>
    </form>
</div>

@push('scripts')
<script>
// Dispatch event when time slot selected (from time-grid component)
document.addEventListener('alpine:initialized', () => {
    // Hook into Alpine component
    const timeGrid = document.querySelector('[x-data*="timeGridWidget"]');
    if (timeGrid) {
        timeGrid.addEventListener('click', (e) => {
            if (e.target.closest('.time-grid__slot:not(:disabled)')) {
                window.dispatchEvent(new CustomEvent('time-selected'));
            }
        });
    }
});
</script>
@endpush
@endsection

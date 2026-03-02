@props([
    'currentStep' => 1,
    'totalSteps' => 5
])

@php
    $steps = [
        1 => ['name' => 'Usługa', 'icon' => 'sparkles'],
        2 => ['name' => 'Termin', 'icon' => 'calendar'],
        3 => ['name' => 'Szczegóły', 'icon' => 'pencil'],
        4 => ['name' => 'Kontakt', 'icon' => 'user'],
        5 => ['name' => 'Podsumowanie', 'icon' => 'check-circle'],
    ];
@endphp

<div class="progress-indicator bg-white border-b border-gray-200 py-4">
    <div class="container mx-auto px-4">
        {{-- Desktop: Horizontal stepper --}}
        <div class="progress-indicator__desktop hidden md:flex items-center justify-center gap-2">
            @foreach($steps as $stepNumber => $step)
                {{-- Step Circle --}}
                <div class="progress-indicator__step-wrapper flex items-center">
                    <div @class([
                        'progress-indicator__step',
                        'relative flex flex-col items-center',
                        'transition-all duration-300',
                    ])>
                        {{-- Circle --}}
                        <div @class([
                            'progress-indicator__circle',
                            'flex items-center justify-center',
                            'w-12 h-12 rounded-full',
                            'border-2 transition-all duration-300',
                            'progress-indicator__circle--completed' => $stepNumber < $currentStep,
                            'progress-indicator__circle--active' => $stepNumber === $currentStep,
                            'progress-indicator__circle--pending' => $stepNumber > $currentStep,
                            'bg-orange-500 border-orange-500' => $stepNumber < $currentStep,
                            'bg-white border-orange-500 ring-4 ring-orange-100' => $stepNumber === $currentStep,
                            'bg-white border-gray-300' => $stepNumber > $currentStep,
                        ])>
                            @if($stepNumber < $currentStep)
                                {{-- Completed: Checkmark --}}
                                <x-heroicon-s-check class="w-6 h-6 text-white" />
                            @else
                                {{-- Pending/Active: Icon --}}
                                @switch($step['icon'])
                                    @case('sparkles')
                                        <x-heroicon-o-sparkles @class([
                                            'w-6 h-6',
                                            'text-orange-500' => $stepNumber === $currentStep,
                                            'text-gray-400' => $stepNumber > $currentStep,
                                        ]) />
                                        @break
                                    @case('calendar')
                                        <x-heroicon-o-calendar @class([
                                            'w-6 h-6',
                                            'text-orange-500' => $stepNumber === $currentStep,
                                            'text-gray-400' => $stepNumber > $currentStep,
                                        ]) />
                                        @break
                                    @case('pencil')
                                        <x-heroicon-o-pencil @class([
                                            'w-6 h-6',
                                            'text-orange-500' => $stepNumber === $currentStep,
                                            'text-gray-400' => $stepNumber > $currentStep,
                                        ]) />
                                        @break
                                    @case('user')
                                        <x-heroicon-o-user @class([
                                            'w-6 h-6',
                                            'text-orange-500' => $stepNumber === $currentStep,
                                            'text-gray-400' => $stepNumber > $currentStep,
                                        ]) />
                                        @break
                                    @case('check-circle')
                                        <x-heroicon-o-check-circle @class([
                                            'w-6 h-6',
                                            'text-orange-500' => $stepNumber === $currentStep,
                                            'text-gray-400' => $stepNumber > $currentStep,
                                        ]) />
                                        @break
                                @endswitch
                            @endif
                        </div>

                        {{-- Label --}}
                        <span @class([
                            'progress-indicator__label',
                            'mt-2 text-xs font-medium',
                            'text-orange-600' => $stepNumber === $currentStep,
                            'text-gray-600' => $stepNumber < $currentStep,
                            'text-gray-400' => $stepNumber > $currentStep,
                        ])>
                            {{ $step['name'] }}
                        </span>
                    </div>

                    {{-- Connecting Line --}}
                    @if($stepNumber < $totalSteps)
                        <div @class([
                            'progress-indicator__line',
                            'w-12 h-0.5 mx-2',
                            'transition-all duration-300',
                            'bg-orange-500' => $stepNumber < $currentStep,
                            'bg-gray-300' => $stepNumber >= $currentStep,
                        ])></div>
                    @endif
                </div>
            @endforeach
        </div>

        {{-- Mobile: Compact progress bar --}}
        <div class="progress-indicator__mobile md:hidden">
            {{-- Step X of Y --}}
            <div class="flex items-center justify-between mb-3">
                <span class="text-sm font-medium text-gray-600">
                    Krok {{ $currentStep }} z {{ $totalSteps }}
                </span>
                <span class="text-sm font-bold text-orange-600">
                    {{ $steps[$currentStep]['name'] }}
                </span>
            </div>

            {{-- Progress bar --}}
            <div class="progress-indicator__bar relative w-full h-2 bg-gray-200 rounded-full overflow-hidden">
                <div
                    class="progress-indicator__bar-fill absolute top-0 left-0 h-full bg-gradient-to-r from-orange-500 to-orange-600 rounded-full transition-all duration-500 ios-spring"
                    style="width: {{ ($currentStep / $totalSteps) * 100 }}%"
                ></div>
            </div>
        </div>
    </div>
</div>

{{-- Styles for progress indicator --}}
@push('styles')
<style>
/* Progress indicator animations */
.progress-indicator__circle {
    transition: all 0.3s cubic-bezier(0.68, -0.55, 0.265, 1.55);
}

.progress-indicator__circle--active {
    animation: pulse 2s infinite;
}

@keyframes pulse {
    0%, 100% {
        box-shadow: 0 0 0 0 rgba(249, 115, 22, 0.4);
    }
    50% {
        box-shadow: 0 0 0 8px rgba(249, 115, 22, 0);
    }
}

.progress-indicator__line {
    transition: background-color 0.5s ease;
}

.progress-indicator__label {
    transition: color 0.3s ease;
}
</style>
@endpush

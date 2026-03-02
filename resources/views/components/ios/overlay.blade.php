@props([
    'show' => false,                 // Controls visibility (Alpine.js binding)
    'opacity' => '50',               // Opacity percentage (0-100)
    'zIndex' => '40',                // Z-index value
])

{{--
    Backdrop overlay component
    - Used for mobile drawer and modals
    - Click to close behavior
    - Smooth fade animation
    - Customizable opacity and z-index
--}}

<div
    x-show="show"
    x-transition:enter="transition-opacity ease-out duration-300"
    x-transition:enter-start="opacity-0"
    x-transition:enter-end="opacity-100"
    x-transition:leave="transition-opacity ease-in duration-300"
    x-transition:leave-start="opacity-100"
    x-transition:leave-end="opacity-0"
    class="fixed inset-0 bg-black/{{ $opacity }} z-{{ $zIndex }}"
    style="display: none;"
    aria-hidden="true"
    {{ $attributes }}
></div>

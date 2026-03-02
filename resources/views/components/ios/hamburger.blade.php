@props([
    'open' => false,                 // Controls animation state (Alpine.js binding)
    'class' => '',                   // Additional CSS classes
])

{{--
    Animated hamburger icon that transforms from three lines to X shape
    Touch target: 44x44px (Apple HIG compliance)
    Animation: Lines rotate and translate to form X when open
--}}

<div class="flex flex-col justify-center items-center w-11 h-11 space-y-1.5 {{ $class }}" {{ $attributes }}>
    {{-- Line 1: Rotates 45deg and moves down when open --}}
    <span
        :class="{ 'rotate-45 translate-y-2': open }"
        class="block w-6 h-0.5 bg-gray-700 transition-all duration-300 ease-in-out"
    ></span>

    {{-- Line 2: Fades out when open --}}
    <span
        :class="{ 'opacity-0': open }"
        class="block w-6 h-0.5 bg-gray-700 transition-all duration-300 ease-in-out"
    ></span>

    {{-- Line 3: Rotates -45deg and moves up when open --}}
    <span
        :class="{ '-rotate-45 -translate-y-2': open }"
        class="block w-6 h-0.5 bg-gray-700 transition-all duration-300 ease-in-out"
    ></span>
</div>

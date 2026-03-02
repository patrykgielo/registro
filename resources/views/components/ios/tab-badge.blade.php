@props([
    'count' => 0,  // Badge count (0 = hidden, 1-99 = exact, 100+ = "99+")
])

@php
    // Only show badge if count > 0
    $shouldShow = $count > 0;

    // Format count: 1-99 exact, 100+ shows "99+"
    $displayCount = $count > 99 ? '99+' : (string) $count;

    // Badge classes - iOS red, absolute positioning
    $badgeClasses = 'absolute -top-1 -right-1 bg-[#FF3B30] text-white text-[10px] font-semibold rounded-full min-w-[16px] h-4 px-1 flex items-center justify-center transition-transform duration-300';
@endphp

@if($shouldShow)
    <span
        class="{{ $badgeClasses }}"
        style="transition-timing-function: cubic-bezier(0.36, 0.66, 0.04, 1);"
        aria-label="{{ $count }} new notifications"
    >
        {{ $displayCount }}
    </span>
@endif

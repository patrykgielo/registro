@props([
    'rating' => 0,
    'totalReviews' => 0,
    'size' => 'sm', // sm, md, lg
])

@php
    // Size mapping for star icons
    $sizeMap = [
        'sm' => 'w-4 h-4',
        'md' => 'w-5 h-5',
        'lg' => 'w-6 h-6',
    ];
    $iconSize = $sizeMap[$size] ?? $sizeMap['sm'];

    // Text size mapping
    $textSizeMap = [
        'sm' => 'text-sm',
        'md' => 'text-base',
        'lg' => 'text-lg',
    ];
    $textSize = $textSizeMap[$size] ?? $textSizeMap['sm'];
@endphp

@if($totalReviews > 0)
<div class="star-rating flex items-center gap-1">
    {{-- Star icons --}}
    <div class="star-rating__stars flex items-center gap-0.5">
        @for($i = 1; $i <= 5; $i++)
            @if($i <= floor($rating))
                <x-heroicon-s-star class="star-rating__star star-rating__star--filled {{ $iconSize }} text-yellow-400" />
            @elseif($i == ceil($rating) && $rating % 1 >= 0.5)
                <x-heroicon-s-star class="star-rating__star star-rating__star--half {{ $iconSize }} text-yellow-400" />
            @else
                <x-heroicon-o-star class="star-rating__star star-rating__star--empty {{ $iconSize }} text-gray-300" />
            @endif
        @endfor
    </div>

    {{-- Rating value --}}
    <span class="star-rating__value {{ $textSize }} font-bold text-gray-900 ml-1">
        {{ number_format($rating, 1) }}
    </span>

    {{-- Review count --}}
    <span class="star-rating__count text-xs text-gray-500">
        ({{ $totalReviews }} {{ $totalReviews === 1 ? 'opinia' : 'opinii' }})
    </span>
</div>
@endif

@props([
    'items' => [],
])

@php
    // Items should be array of ['label' => 'Text', 'url' => 'http://...'] or ['label' => 'Text'] for current page
@endphp

<nav class="container mx-auto px-4 md:px-6 py-6" aria-label="Breadcrumb">
    <ol class="flex items-center space-x-2 text-sm text-gray-600">
        @foreach($items as $index => $item)
            @if($index > 0)
                {{-- Chevron Separator --}}
                <li aria-hidden="true">
                    <x-heroicon-m-chevron-right class="w-4 h-4 text-gray-400" />
                </li>
            @endif

            <li>
                @if(isset($item['url']))
                    <a href="{{ $item['url'] }}"
                       class="hover:text-primary transition-colors duration-200 ios-spring">
                        {{ $item['label'] }}
                    </a>
                @else
                    <span class="text-gray-900 font-semibold" aria-current="page">
                        {{ $item['label'] }}
                    </span>
                @endif
            </li>
        @endforeach
    </ol>
</nav>

<style>
    /* iOS Spring Animation */
    .ios-spring {
        transition-timing-function: cubic-bezier(0.36, 0.66, 0.04, 1);
    }

    /* Accessibility: Reduced Motion */
    @media (prefers-reduced-motion: reduce) {
        .ios-spring {
            transition: none !important;
        }
    }
</style>

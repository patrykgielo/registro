@props(['item', 'url', 'dark' => false])

@php
    use Illuminate\Support\Facades\Storage;

    $image = $item->featured_image ?? $item->before_image ?? null;
@endphp

<article class="cms-content-card {{ $dark
    ? 'service-card-dark shadow-dark-glow hover:shadow-dark-glow-hover'
    : 'bg-white shadow-md hover:shadow-xl' }}">

    @if($image)
        <div class="overflow-hidden">
            <img src="{{ Storage::url($image) }}"
                 alt="{{ $item->title ?? $item->name }}"
                 class="cms-card-image"
                 loading="lazy">
        </div>
    @endif

    <div class="p-6 flex flex-col flex-1">
        <h3 class="text-xl font-bold mb-2 {{ $dark ? 'text-white' : 'text-gray-900' }}">
            {{ $item->title ?? $item->name }}
        </h3>

        <p class="text-sm mb-4 flex-1 {{ $dark ? 'text-white/70' : 'text-gray-600' }}">
            {{ Str::limit($item->excerpt ?? $item->body ?? '', 120) }}
        </p>

        @if($item->slug ?? false)
            <a href="{{ $url }}"
               class="inline-flex items-center gap-2 font-semibold text-sm {{ $dark ? 'text-[#0AB1EA] hover:text-[#0AB1EA]/80' : 'text-primary-600 hover:text-primary-700' }} transition-colors">
                Zobacz szczegóły
                <x-heroicon-m-arrow-right class="w-4 h-4" />
            </a>
        @endif
    </div>
</article>

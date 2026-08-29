@props(['location', 'dark' => false])

@php
    use Illuminate\Support\Facades\Storage;
    use Illuminate\Support\Str;

    $address = collect([
        trim(($location->street ?? '').' '.($location->building ?? '')),
        trim(($location->postal_code ?? '').' '.($location->city ?? '')),
    ])->filter(fn ($line) => $line !== '')->implode(', ');

    $hours = collect($location->opening_hours ?? [])
        ->filter(fn ($entry) => is_array($entry) && (Str::of($entry['label'] ?? '')->trim()->isNotEmpty() || Str::of($entry['hours'] ?? '')->trim()->isNotEmpty()))
        ->values();

    $phoneHref = $location->phone ? 'tel:'.preg_replace('/[^\d+]/', '', $location->phone) : null;
    $emailHref = $location->email ? 'mailto:'.$location->email : null;

    $galleryImages = collect($location->gallery ?? [])->filter()->values();
    $galleryPreviewCount = 4;
    $galleryPreview = $galleryImages->take($galleryPreviewCount);
    $galleryRemaining = max($galleryImages->count() - $galleryPreviewCount, 0);

    // No dedicated route for a single location exists yet (out of Faza 1 scope) —
    // coordinates first (more precise pin), address text as fallback so the link
    // still works for branches whose position hasn't been placed on the map yet.
    $mapUrl = null;
    if ($location->latitude !== null && $location->longitude !== null) {
        // Cast off the decimal:8 cast's fixed-width string (e.g. "52.22970000")
        // — Google Maps accepts either, but the trailing zeros are just noise.
        $mapUrl = 'https://www.google.com/maps/search/?api=1&query='.((float) $location->latitude).','.((float) $location->longitude);
    } elseif ($address !== '') {
        $mapUrl = 'https://www.google.com/maps/search/?api=1&query='.urlencode($address.' '.$location->name);
    }

    $titleClasses = $dark ? 'text-white' : 'text-gray-900';
    $bodyClasses = $dark ? 'text-white/70' : 'text-gray-600';
    $iconClasses = $dark ? 'text-white/60' : 'text-gray-500';
    $linkClasses = $dark ? 'text-[#0AB1EA] hover:text-[#0AB1EA]/80' : 'text-brand hover:text-brand-hover';
    // Contrast verified numerically (WCAG 2.2 AA, small text >= 4.5:1):
    // light 6.87:1  -- gray-600 #4a5565 on gray-100 #f3f4f6
    // dark  9.40:1  -- text-white/80 and bg-white/10 flattened over the card background
    //                  --color-dark-bg-raised oklch(20% 0.01 250) = rgb(19,22,26), NOT black.
    //                  Assuming #000000 here inflates the result -- see dark-theme.md.
    // The "+N" gallery overlay is 5.74:1 -- white on bg-black/60 over a fully white photo.
    $badgeClasses = $dark
        ? 'bg-white/10 text-white/80 border border-white/20'
        : 'bg-gray-100 text-gray-600 border border-gray-200';
@endphp

<article class="cms-content-card {{ $dark
    ? 'service-card-dark shadow-dark-glow hover:shadow-dark-glow-hover'
    : 'bg-white shadow-md hover:shadow-xl' }}">

    @if($location->photo)
        <div class="overflow-hidden">
            <img src="{{ Storage::url($location->photo) }}"
                 alt="{{ __('Siedziba :name', ['name' => $location->name]) }}"
                 class="cms-card-image"
                 loading="lazy">
        </div>
    @endif

    <div class="p-6 flex flex-col flex-1 gap-3">
        <div class="flex items-center gap-2 flex-wrap">
            <h3 class="text-xl font-bold {{ $titleClasses }}">
                {{ $location->name }}
            </h3>

            @if($location->code)
                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-medium tracking-wide {{ $badgeClasses }}">
                    {{ $location->code }}
                </span>
            @endif
        </div>

        @if($address !== '')
            <p class="flex items-start gap-2 text-sm {{ $bodyClasses }}">
                <x-heroicon-o-map-pin class="w-4 h-4 {{ $iconClasses }} flex-shrink-0 mt-0.5" />
                <span>{{ $address }}</span>
            </p>
        @endif

        @if($location->description)
            <p class="text-sm {{ $bodyClasses }}">
                {{ Str::limit($location->description, 120) }}
            </p>
        @endif

        @if($hours->isNotEmpty())
            <div class="flex items-start gap-2 text-sm {{ $bodyClasses }}">
                <x-heroicon-o-clock class="w-4 h-4 {{ $iconClasses }} flex-shrink-0 mt-0.5" />
                <ul class="space-y-0.5">
                    @foreach($hours as $entry)
                        <li>
                            <span class="font-medium">{{ $entry['label'] ?? '' }}</span>
                            @if(($entry['label'] ?? '') !== '' && ($entry['hours'] ?? '') !== '')
                                <span aria-hidden="true">&mdash;</span>
                            @endif
                            {{ $entry['hours'] ?? '' }}
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if($galleryImages->isNotEmpty())
            <div class="grid grid-cols-4 gap-1.5" role="group" aria-label="{{ __('Zdjęcia lokalizacji :name', ['name' => $location->name]) }}">
                @foreach($galleryPreview as $index => $image)
                    <div class="relative aspect-square overflow-hidden rounded-lg">
                        <img src="{{ Storage::url($image) }}"
                             alt="{{ __('Zdjęcie :n z galerii lokalizacji :name', ['n' => $index + 1, 'name' => $location->name]) }}"
                             class="w-full h-full object-cover"
                             loading="lazy">
                        @if($loop->last && $galleryRemaining > 0)
                            <span class="absolute inset-0 flex items-center justify-center bg-black/60 text-white text-xs font-semibold">
                                +{{ $galleryRemaining }}
                            </span>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif

        <div class="flex flex-wrap items-center gap-x-4 gap-y-2 mt-auto pt-3 text-sm font-semibold">
            @if($phoneHref)
                <a href="{{ $phoneHref }}"
                   class="inline-flex items-center gap-1.5 min-h-11 {{ $linkClasses }} transition-colors"
                   aria-label="{{ __('Zadzwoń: :phone', ['phone' => $location->phone]) }}">
                    <x-heroicon-o-phone class="w-4 h-4 flex-shrink-0" />
                    {{ $location->phone }}
                </a>
            @endif

            @if($emailHref)
                <a href="{{ $emailHref }}"
                   class="inline-flex items-center gap-1.5 min-h-11 {{ $linkClasses }} transition-colors"
                   aria-label="{{ __('Napisz e-mail: :email', ['email' => $location->email]) }}">
                    <x-heroicon-o-envelope class="w-4 h-4 flex-shrink-0" />
                    {{ $location->email }}
                </a>
            @endif

            @if($mapUrl)
                <a href="{{ $mapUrl }}"
                   target="_blank"
                   rel="noopener noreferrer"
                   class="inline-flex items-center gap-1.5 min-h-11 {{ $linkClasses }} transition-colors"
                   aria-label="{{ __('Zobacz :name na mapie (otwiera się w nowej karcie)', ['name' => $location->name]) }}">
                    <x-heroicon-m-map class="w-4 h-4 flex-shrink-0" />
                    {{ __('Zobacz na mapie') }}
                </a>
            @endif
        </div>
    </div>
</article>

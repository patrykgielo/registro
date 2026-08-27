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
        <h3 class="text-xl font-bold {{ $titleClasses }}">
            {{ $location->name }}
        </h3>

        @if($address !== '')
            <p class="flex items-start gap-2 text-sm {{ $bodyClasses }}">
                <x-heroicon-o-map-pin class="w-4 h-4 {{ $iconClasses }} flex-shrink-0 mt-0.5" />
                <span>{{ $address }}</span>
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

        <div class="flex flex-wrap items-center gap-x-4 gap-y-2 mt-auto pt-3 text-sm font-semibold">
            @if($phoneHref)
                <a href="{{ $phoneHref }}"
                   class="inline-flex items-center gap-1.5 min-h-11 {{ $linkClasses }} transition-colors"
                   aria-label="{{ __('Zadzwoń: :phone', ['phone' => $location->phone]) }}">
                    <x-heroicon-o-phone class="w-4 h-4 flex-shrink-0" />
                    {{ $location->phone }}
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

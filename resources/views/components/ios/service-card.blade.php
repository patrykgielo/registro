@props([
    'service' => null,
    'icon' => 'sparkles',
    'title' => '',
    'description' => '',
    'price' => null,
    'duration' => null,
    'url' => '#',
    'showCta' => true,
    'variant' => 'default', // 'default' | 'dark'
])

@php
    use App\Enums\ServiceType;

    // Extract from service model if provided
    if ($service) {
        $title = $service->name;
        $description = $service->excerpt ?? Str::limit($service->description, 100);
        $isRental = $service->service_type === ServiceType::ItemRental;
        $price = $isRental ? $service->price_per_day : ($service->price_from ?? $service->price);
        $duration = $isRental ? null : $service->duration_minutes;
        $url = route('service.show', $service->slug ?? $service->id);
        $icon = $service->icon ?? ($isRental ? 'cube' : 'sparkles');

        // Conversion optimization data
        $averageRating = $service->average_rating ?? 0;
        $totalReviews = $service->total_reviews ?? 0;
        $isPopular = $service->is_popular ?? false;
        $bookingCountWeek = $isRental ? 0 : ($service->booking_count_week ?? 0);
        $features = $service->features ?? [];

        // Featured image for background
        $featuredImage = $service->featured_image
            ? asset('storage/' . $service->featured_image)
            : null;

        // Rental-specific display data
        $rentalPrice = $isRental ? $service->formatted_rental_price : null;
        $quantityAvailable = $isRental ? $service->quantity_total : null;

    } else {
        $isRental = false;
        $averageRating = 0;
        $totalReviews = 0;
        $isPopular = false;
        $bookingCountWeek = 0;
        $features = [];
        $featuredImage = null;
        $rentalPrice = null;
        $quantityAvailable = null;
    }

    // Dark variant detection - force dark when featured image is present
    $isDark = $variant === 'dark' || $featuredImage;

    // Variant-based classes
    $cardClasses = $isDark
        ? 'service-card-dark shadow-dark-glow hover:shadow-dark-glow-hover hover:border-[#0AB1EA]/30'
        : 'bg-white shadow-md hover:shadow-2xl border border-gray-100 hover:border-primary-300';

    $titleClasses = $isDark ? 'text-white group-hover:text-[#0AB1EA]' : 'text-gray-900 group-hover:text-orange-600';
    $descriptionClasses = $isDark ? 'text-white/70' : 'text-gray-600';
    $priceLabelClasses = $isDark ? 'text-white/70' : 'text-gray-600';
    $priceValueClasses = $isDark ? 'text-white' : 'text-gray-900';
    $ctaClasses = $isDark ? 'btn-cta-dark' : 'bg-warning text-white hover:bg-warning/90';
    $durationClasses = $isDark ? 'badge-duration-dark' : 'bg-gray-50 text-gray-600';
    $durationIconClasses = $isDark ? 'text-white/60' : 'text-gray-500';
    $featuresListClasses = $isDark ? 'features-list-dark' : 'bg-gray-50';
    $featureTextClasses = $isDark ? 'text-white/80' : 'text-gray-700';
    $featureIconClasses = $isDark ? 'text-[#0AB1EA]' : 'text-green-500';
    $urgencyClasses = $isDark ? 'urgency-footer-dark text-white/80' : 'bg-orange-50 text-gray-600';
    $urgencyIconClasses = $isDark ? 'text-[#0AB1EA]' : 'text-orange-500';
    $urgencyCountClasses = $isDark ? 'text-[#0AB1EA]' : 'text-orange-700';
    $iconBgColor = $isDark ? 'bg-[#0AB1EA]' : 'bg-primary-500';
@endphp

<article
    @class([
        'service-card',
        'service-card--popular' => $isPopular,
        'group relative rounded-lg p-6',
        'service-card-bg-image' => $featuredImage,
        $cardClasses,
        'hover:-translate-y-2 transition-all duration-300',
        'ios-spring cursor-pointer overflow-hidden',
    ])
    @if($featuredImage)
        style="background-image: url('{{ $featuredImage }}')"
    @endif
    x-data="{ hover: false }"
    @mouseenter="hover = true"
    @mouseleave="hover = false"
    @click="window.location.href = '{{ $url }}'"
>
    {{-- Overlay for background image --}}
    @if($featuredImage)
        <div class="service-card-bg-overlay"></div>
    @endif

    {{-- Popularity Badge --}}
    @if($isPopular)
    <div class="service-card__badge absolute top-4 right-4 z-10 bg-warning text-white text-xs font-bold px-3 py-1.5 rounded-full shadow-lg flex items-center gap-1">
        <x-heroicon-s-star class="service-card__badge-icon w-3 h-3" />
        <span class="service-card__badge-text">Najpopularniejsze</span>
    </div>
    @endif

    {{-- Card Content (z-10 to appear above overlay) --}}
    <div class="relative z-10">
        {{-- Icon Container (iOS App Icon Style) --}}
        <div class="service-card__icon flex items-center justify-center w-16 h-16 rounded-lg {{ $iconBgColor }} mb-4 transition-transform duration-300 ios-spring group-hover:scale-110 group-hover:rotate-3 shadow-lg">
            <x-dynamic-component :component="'heroicon-s-' . ($icon ?? 'sparkles')" class="service-card__icon-svg w-8 h-8 text-white" />
        </div>

        {{-- Star Rating (extracted component, hidden for now per user request) --}}
        {{--
        <div class="service-card__rating mb-3">
            <x-ios.star-rating
                :rating="$averageRating"
                :total-reviews="$totalReviews"
                size="sm"
            />
        </div>
        --}}

        {{-- Service Title --}}
        <h3 class="service-card__title text-xl font-bold {{ $titleClasses }} mb-2 transition-colors duration-200">
            {{ $title }}
        </h3>

        {{-- Description --}}
        <p class="service-card__description {{ $descriptionClasses }} text-sm mb-3 line-clamp-2 leading-relaxed">
            {{ $description }}
        </p>

        {{-- Duration Badge (time_slot) or Rental Price Badge (item_rental) --}}
        @if($isRental && ($service?->price_on_request))
        <div class="service-card__duration flex items-center gap-1.5 text-xs mb-4 {{ $durationClasses }} px-3 py-1.5 rounded-lg w-fit">
            <x-heroicon-m-chat-bubble-left-ellipsis class="service-card__duration-icon w-4 h-4 {{ $durationIconClasses }}" />
            <span class="service-card__duration-text font-medium">Cena do potwierdzenia</span>
        </div>
        @elseif($isRental && $rentalPrice)
        <div class="service-card__duration flex items-center gap-1.5 text-xs mb-4 {{ $durationClasses }} px-3 py-1.5 rounded-lg w-fit">
            <x-heroicon-m-banknotes class="service-card__duration-icon w-4 h-4 {{ $durationIconClasses }}" />
            <span class="service-card__duration-text font-medium">{{ $rentalPrice }}</span>
        </div>
        @elseif($duration)
        <div class="service-card__duration flex items-center gap-1.5 text-xs mb-4 {{ $durationClasses }} px-3 py-1.5 rounded-lg w-fit">
            <x-heroicon-m-clock class="service-card__duration-icon w-4 h-4 {{ $durationIconClasses }}" />
            <span class="service-card__duration-text font-medium">{{ $duration }} min</span>
        </div>
        @endif

        {{-- Features List (visible on all devices) --}}
        @if(is_array($features) && count($features) > 0)
        <ul class="service-card__features space-y-1.5 md:space-y-2 mb-4 {{ $featuresListClasses }} rounded-lg p-3 md:p-4">
            @foreach(array_slice($features, 0, 4) as $feature)
            <li class="service-card__feature flex items-start gap-2 text-xs md:text-sm {{ $featureTextClasses }}">
                <x-heroicon-s-check-circle class="service-card__feature-icon w-4 h-4 {{ $featureIconClasses }} flex-shrink-0 mt-0.5" />
                <span class="service-card__feature-text leading-relaxed">{{ $feature }}</span>
            </li>
            @endforeach
        </ul>
        @endif

        {{-- Price --}}
        @if($price && !($service?->price_on_request))
        <div class="service-card__price flex items-baseline gap-1 mb-4">
            <span class="service-card__price-value text-3xl font-bold {{ $priceValueClasses }}">{{ number_format($price, 0, ',', ' ') }}</span>
            <span class="service-card__price-currency text-sm {{ $priceLabelClasses }} font-medium">zł</span>
        </div>
        @endif

        {{-- CTA Button --}}
        @if($showCta)
        <a
            href="{{ $url }}"
            class="service-card__cta flex items-center justify-center gap-2 w-full {{ $ctaClasses }} font-bold text-sm py-3.5 px-4 rounded-lg hover:shadow-lg transition-all duration-200 ios-spring"
            @click.stop
        >
            <span class="service-card__cta-text">Zobacz Szczegóły</span>
            <x-heroicon-m-arrow-right class="service-card__cta-icon w-4 h-4" />
        </a>
        @endif

        {{-- Urgency Footer --}}
        @if($bookingCountWeek > 0)
        <div class="service-card__urgency flex items-center justify-center gap-1.5 mt-3 text-xs {{ $urgencyClasses }} py-2 px-3 rounded-lg">
            <x-heroicon-m-fire class="service-card__urgency-icon w-4 h-4 {{ $urgencyIconClasses }}" />
            <span class="service-card__urgency-text">
                Zarezerwowano <strong class="service-card__urgency-count {{ $urgencyCountClasses }} font-bold">{{ $bookingCountWeek }} razy</strong> w tym tygodniu
            </span>
        </div>
        @endif
    </div>
</article>

@extends('layouts.app')

@push('head')
    <meta property="og:title" content="{{ $service->meta_title ?? $service->name }}">
    <meta property="og:description" content="{{ $service->meta_description ?? $service->excerpt }}">
    <meta property="og:type" content="website">
    <meta property="og:url" content="{{ route('service.show', $service) }}">
    @if($service->featured_image)
        <meta property="og:image" content="{{ Storage::url($service->featured_image) }}">
    @endif
    <meta name="description" content="{{ $service->meta_description ?? $service->excerpt }}">
    <title>{{ $service->meta_title ?? $service->name . ' - ' . config('app.name') }}</title>
    <script type="application/ld+json">{!! $schemaService !!}</script>
    <script type="application/ld+json">{!! $schemaBreadcrumbs !!}</script>
@endpush

@php
    $isRental = $service->service_type === \App\Enums\ServiceType::ItemRental;
    $bookingEnabled = app(\App\Support\Settings\SettingsManager::class)->isBookingEnabled();
    $contactPhone = app(\App\Support\Settings\SettingsManager::class)->contactInformation()['phone'] ?? null;
    $specs = $service->metadata['specs'] ?? [];
@endphp

@section('content')

{{-- Breadcrumbs --}}
<x-layout.container class="pt-6 pb-2">
    <nav class="text-sm text-text-muted" aria-label="Breadcrumb">
        <ol class="flex items-center gap-2">
            <li><a href="{{ route('home') }}" class="hover:text-text-primary transition-colors">Strona główna</a></li>
            <li><x-heroicon-m-chevron-right class="h-4 w-4" /></li>
            <li><a href="{{ route('services.index') }}" class="hover:text-text-primary transition-colors">Usługi</a></li>
            <li><x-heroicon-m-chevron-right class="h-4 w-4" /></li>
            <li class="text-text-primary font-medium truncate">{{ $service->name }}</li>
        </ol>
    </nav>
</x-layout.container>

@if($isRental)
    {{-- ═══════════════════════════════════════════════════════════
         RENTAL ITEM — Pattern B: Sticky Sidebar
         ═══════════════════════════════════════════════════════════ --}}
    <x-layout.section spacing="sm">
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8 lg:gap-12 items-start">

            {{-- LEFT COLUMN: Image + Info + Specs (scrollable) --}}
            <div class="lg:col-span-2 space-y-8">

                {{-- Image --}}
                @if($service->featured_image)
                    <x-media.image :src="$service->featured_image" :alt="$service->name" aspect="16/9" rounded="xl" />
                @else
                    <div class="aspect-[16/9] rounded-xl bg-surface-sunken flex items-center justify-center">
                        <x-heroicon-o-photo class="h-16 w-16 text-text-muted" />
                    </div>
                @endif

                {{-- Title + Badges --}}
                <div>
                    <h1 class="text-3xl md:text-4xl font-bold text-text-primary tracking-tight mb-3">
                        {{ $service->name }}
                    </h1>

                    <div class="flex flex-wrap items-center gap-2 mb-4">
                        @if($service->brand)
                            <x-ui.badge variant="brand">{{ $service->brand }}</x-ui.badge>
                        @endif
                        @if($service->category)
                            <x-ui.badge variant="default">{{ $service->category->name }}</x-ui.badge>
                        @endif
                        @if($service->quantity_total)
                            <x-ui.badge variant="info" dot>{{ $service->quantity_total }} szt. w magazynie</x-ui.badge>
                        @endif
                    </div>

                    @if($service->excerpt ?? $service->description)
                        <p class="text-text-secondary leading-relaxed">
                            {{ $service->excerpt ?? $service->description }}
                        </p>
                    @endif
                </div>

                {{-- Body Content (prose) --}}
                @if($service->body && trim(strip_tags($service->body)))
                    <div class="prose prose-lg prose-registro max-w-none">
                        {!! clean($service->body) !!}
                    </div>
                @endif

                {{-- Technical Specifications --}}
                @php
                    // Filter out empty repeater entries (null label/value)
                    $filteredSpecs = collect($specs)->filter(function ($spec, $index) {
                        if (is_array($spec)) {
                            return !empty($spec['label']) || !empty($spec['value']);
                        }
                        return !empty($spec); // legacy format
                    })->all();
                @endphp
                @if(!empty($filteredSpecs))
                    <div>
                        <h2 class="text-lg font-semibold text-text-primary mb-4">Specyfikacja techniczna</h2>
                        <div class="rounded-xl border border-border overflow-hidden">
                            @foreach($filteredSpecs as $index => $spec)
                                @php
                                    // Support both new format [{label, value, unit}] and legacy {key: value}
                                    $isNewFormat = is_array($spec) && isset($spec['label']);
                                    $label = $isNewFormat ? (string) ($spec['label'] ?? '') : ucfirst(str_replace('_', ' ', $index));
                                    $value = $isNewFormat ? (string) ($spec['value'] ?? '') : (string) $spec;
                                    $unit = $isNewFormat ? (string) ($spec['unit'] ?? '') : '';
                                @endphp
                                <div @class([
                                    'flex items-center justify-between px-4 py-3 text-sm',
                                    'bg-surface-sunken' => $loop->even,
                                    'bg-surface-raised' => $loop->odd,
                                ])>
                                    <span class="text-text-secondary font-medium">{{ $label }}</span>
                                    <span class="text-text-primary font-semibold">{{ $value }}{{ $unit ? " {$unit}" : '' }}</span>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

                {{-- Builder Blocks --}}
                @if($service->content)
                    @foreach($service->content as $block)
                        @php $blockType = $block['type'] ?? ''; @endphp

                        @if($blockType === 'text_block')
                            <x-content-blocks.text-block :data="$block['data']" />
                        @elseif($blockType === 'content_grid')
                            <x-content-blocks.content-grid :data="$block['data']" />
                        @elseif($blockType === 'image' && !empty($block['data']['image']))
                            <div>
                                <x-media.image :src="$block['data']['image']" :alt="$block['data']['alt'] ?? ''" rounded="xl" />
                                @if(!empty($block['data']['caption']))
                                    <p class="text-sm text-text-muted text-center mt-3">{{ $block['data']['caption'] }}</p>
                                @endif
                            </div>
                        @endif
                    @endforeach
                @endif

                {{-- Related Products --}}
                @if($relatedServices->count() > 0)
                    <div>
                        <h2 class="text-lg font-semibold text-text-primary mb-4">Podobne produkty</h2>
                        <x-layout.grid cols="2" gap="4">
                            @foreach($relatedServices as $related)
                                <x-ui.card hover href="{{ route('service.show', $related) }}" class="group">
                                    @if($related->featured_image)
                                        <div class="-mx-6 -mt-6 mb-4 overflow-hidden rounded-t-xl">
                                            <x-media.image :src="$related->featured_image" :alt="$related->name" aspect="16/9" rounded="none" />
                                        </div>
                                    @endif
                                    <h3 class="font-semibold text-text-primary group-hover:text-brand transition-colors text-sm">{{ $related->name }}</h3>
                                    @if($related->price_per_day)
                                        <p class="text-sm text-text-muted mt-1">od {{ number_format($related->price_per_day, 0, ',', ' ') }} zł/dzień</p>
                                    @endif
                                </x-ui.card>
                            @endforeach
                        </x-layout.grid>
                    </div>
                @endif
            </div>

            {{-- RIGHT COLUMN: Sticky Sidebar (pricing + CTA) --}}
            <div class="lg:col-span-1">
                @php
                    $calendarId = 'cal-heading-' . $service->id;
                    $calApiUrl  = route('rental.calendar', $service);
                    $todayStr   = now()->toDateString();
                    $curYear    = (int) now()->year;
                    $curMonth   = (int) now()->month;
                @endphp
                <div
                    class="sticky top-20"
                    x-data="availabilityCalendar({
                        apiUrl:       '{{ $calApiUrl }}',
                        today:        '{{ $todayStr }}',
                        currentYear:  {{ $curYear }},
                        currentMonth: {{ $curMonth }},
                        pricePerDay:  {{ (float) $service->price_per_day }},
                        pricePerDayLong: {{ (float) ($service->price_per_day_long ?? 0) }},
                        thresholdDays: {{ (int) ($service->price_threshold_days ?? 0) }},
                        pricePerWeek: {{ (float) ($service->price_per_week ?? 0) }},
                        depositAmount: {{ (float) ($service->deposit_amount ?? 0) }},
                    })"
                    x-init="init()"
                >
                    <x-ui.card class="space-y-6">

                        {{-- Tiered Pricing Grid --}}
                        <div>
                            <h3 class="text-sm font-semibold text-text-muted uppercase tracking-wider mb-3">Cennik</h3>
                            <div class="grid grid-cols-2 gap-2">
                                {{-- Per day --}}
                                @if($service->price_per_day)
                                    <div class="rounded-lg bg-surface-sunken p-3 text-center">
                                        <div class="text-xl font-bold text-text-primary">{{ number_format($service->price_per_day, 0, ',', ' ') }} zł</div>
                                        <div class="text-xs text-text-muted mt-0.5">za dzień</div>
                                    </div>
                                @endif

                                {{-- Per day long (tiered) --}}
                                @if($service->price_per_day_long && $service->price_threshold_days)
                                    <div class="rounded-lg bg-success/5 border border-success/20 p-3 text-center">
                                        <div class="text-xl font-bold text-success">{{ number_format($service->price_per_day_long, 0, ',', ' ') }} zł</div>
                                        <div class="text-xs text-success/70 mt-0.5">od {{ $service->price_threshold_days }}+ dni</div>
                                    </div>
                                @endif

                                {{-- Per hour --}}
                                @if($service->price_per_hour)
                                    <div class="rounded-lg bg-surface-sunken p-3 text-center">
                                        <div class="text-lg font-bold text-text-primary">{{ number_format($service->price_per_hour, 0, ',', ' ') }} zł</div>
                                        <div class="text-xs text-text-muted mt-0.5">za godzinę</div>
                                    </div>
                                @endif

                                {{-- Per week --}}
                                @if($service->price_per_week)
                                    <div class="rounded-lg bg-surface-sunken p-3 text-center">
                                        <div class="text-lg font-bold text-text-primary">{{ number_format($service->price_per_week, 0, ',', ' ') }} zł</div>
                                        <div class="text-xs text-text-muted mt-0.5">za tydzień</div>
                                    </div>
                                @endif
                            </div>
                        </div>

                        {{-- Deposit --}}
                        @if($service->deposit_amount)
                            <div class="flex items-center justify-between py-3 border-t border-border">
                                <span class="text-sm text-text-secondary">Kaucja zwrotna</span>
                                <span class="text-sm font-semibold text-text-primary">{{ number_format($service->deposit_amount, 0, ',', ' ') }} zł</span>
                            </div>
                        @endif

                        {{-- Price breakdown (when dates selected) --}}
                        <div x-show="rentalDays > 0" x-transition class="rounded-lg bg-surface-sunken p-4 space-y-1.5 text-sm">
                            <div class="flex justify-between">
                                <span class="text-text-secondary">
                                    <span x-text="rentalDays"></span> <span x-text="rentalDays === 1 ? 'dzień' : 'dni'"></span>
                                    &times; <span x-text="formatPrice(unitPrice)"></span> zł
                                </span>
                                <span class="font-medium text-text-primary" x-text="formatPrice(totalPrice) + ' zł'"></span>
                            </div>
                            <template x-if="isDiscounted">
                                <div class="text-xs text-success">Rabat długoterminowy aktywny</div>
                            </template>
                            @if($service->deposit_amount)
                            <div class="flex justify-between text-text-secondary">
                                <span>Kaucja zwrotna</span>
                                <span>+ {{ number_format($service->deposit_amount, 2, ',', ' ') }} zł</span>
                            </div>
                            @endif
                        </div>

                        {{-- CTA --}}
                        <p x-show="!selectedStart" class="text-xs text-text-muted text-center">Wybierz daty w kalendarzu poniżej</p>
                        <div class="space-y-3">
                            <a
                                :href="bookingUrl"
                                :class="canBook
                                    ? 'bg-brand text-white hover:bg-brand-hover'
                                    : 'bg-surface-sunken text-text-muted pointer-events-none'"
                                class="inline-flex items-center justify-center font-medium rounded-xl transition-all duration-200 text-base px-6 py-3 gap-2 w-full"
                                :aria-disabled="!canBook"
                            >
                                <span x-text="selectedStart && selectedEnd ? 'Zarezerwuj online' : 'Zarezerwuj online'"></span>
                            </a>
                            @if($contactPhone)
                                <x-ui.button variant="secondary" href="tel:{{ $contactPhone }}" size="lg" icon="phone" class="w-full">
                                    Lub zadzwoń: {{ $contactPhone }}
                                </x-ui.button>
                            @endif
                        </div>

                        {{-- Availability badge --}}
                        @if($service->quantity_total && $service->quantity_total > 0)
                            <div class="flex items-center gap-2 text-sm text-success pt-2 border-t border-border">
                                <span class="h-2 w-2 rounded-full bg-success"></span>
                                Dostępny ({{ $service->quantity_total }} szt.)
                            </div>
                        @endif
                        {{-- ─── Availability Calendar (inside same card) ─── --}}
                        <div
                            class="border-t border-border pt-5 -mx-6 px-6"
                            role="region"
                            aria-label="Kalendarz dostępności"
                        >
                        {{-- Header: month nav --}}
                        <div class="flex items-center justify-between pb-3">
                            <div role="group" aria-label="Nawigacja kalendarza" class="flex items-center gap-1">
                                <button
                                    @click="prevMonth()"
                                    :disabled="isPrevDisabled"
                                    :aria-disabled="isPrevDisabled"
                                    aria-label="Poprzedni miesiąc"
                                    class="flex items-center justify-center w-9 h-9 rounded-lg text-text-muted
                                           hover:text-text-primary hover:bg-surface-sunken
                                           disabled:opacity-30 disabled:cursor-not-allowed
                                           transition-colors duration-150 ease-out
                                           focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand focus-visible:ring-offset-1"
                                >
                                    <svg class="w-4 h-4" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10 12L6 8l4-4"/></svg>
                                </button>

                                <h3
                                    :id="'{{ $calendarId }}'"
                                    x-text="headingLabel"
                                    aria-live="polite"
                                    aria-atomic="true"
                                    class="w-36 text-center text-sm font-semibold text-text-primary select-none"
                                ></h3>

                                <button
                                    @click="nextMonth()"
                                    aria-label="Następny miesiąc"
                                    class="flex items-center justify-center w-9 h-9 rounded-lg text-text-muted
                                           hover:text-text-primary hover:bg-surface-sunken
                                           transition-colors duration-150 ease-out
                                           focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand focus-visible:ring-offset-1"
                                >
                                    <svg class="w-4 h-4" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 4l4 4-4 4"/></svg>
                                </button>
                            </div>
                        </div>

                        <div class="px-4 pb-4">

                            {{-- Day-of-week headers (Mon–Sun, Polish, Monday-first) --}}
                            <div class="grid grid-cols-7 mb-1" role="row" aria-hidden="true">
                                <div class="flex items-center justify-center h-7">
                                    <abbr title="Poniedziałek" class="text-xs uppercase tracking-wide text-text-muted no-underline">Pn</abbr>
                                </div>
                                <div class="flex items-center justify-center h-7">
                                    <abbr title="Wtorek" class="text-xs uppercase tracking-wide text-text-muted no-underline">Wt</abbr>
                                </div>
                                <div class="flex items-center justify-center h-7">
                                    <abbr title="Środa" class="text-xs uppercase tracking-wide text-text-muted no-underline">Śr</abbr>
                                </div>
                                <div class="flex items-center justify-center h-7">
                                    <abbr title="Czwartek" class="text-xs uppercase tracking-wide text-text-muted no-underline">Cz</abbr>
                                </div>
                                <div class="flex items-center justify-center h-7">
                                    <abbr title="Piątek" class="text-xs uppercase tracking-wide text-text-muted no-underline">Pt</abbr>
                                </div>
                                <div class="flex items-center justify-center h-7">
                                    <abbr title="Sobota" class="text-xs uppercase tracking-wide text-text-muted no-underline">Sb</abbr>
                                </div>
                                <div class="flex items-center justify-center h-7">
                                    <abbr title="Niedziela" class="text-xs uppercase tracking-wide text-text-muted no-underline">Nd</abbr>
                                </div>
                            </div>

                            {{-- Calendar grid — skeleton while loading --}}
                            <div
                                x-show="loading"
                                role="status"
                                :aria-busy="loading"
                                aria-label="Ładowanie kalendarza"
                                class="grid grid-cols-7 gap-1"
                            >
                                <template x-for="n in 42" :key="n">
                                    <div class="aspect-square rounded-lg bg-surface-sunken animate-pulse"></div>
                                </template>
                            </div>

                            {{-- Calendar grid — populated cells --}}
                            <div
                                x-show="!loading"
                                x-transition:enter="transition ease-out duration-200"
                                x-transition:enter-start="opacity-0"
                                x-transition:enter-end="opacity-100"
                                role="grid"
                                :aria-labelledby="'{{ $calendarId }}'"
                                class="grid grid-cols-7 gap-1"
                            >
                                <template x-for="(cell, idx) in dayCells" :key="idx">
                                    <div
                                        :role="cell.day ? 'gridcell' : 'presentation'"
                                        :aria-label="cell.ariaLabel"
                                        :aria-disabled="cell.ariaDisabled"
                                        :aria-current="cell.ariaCurrent"
                                        @click="cell.clickable && selectDate(cell.dateStr)"
                                        :class="[cell.classes, selectionClass(cell.dateStr) || 'rounded-lg']"
                                        class="relative aspect-square flex items-center justify-center text-sm font-medium select-none transition-all duration-150"
                                    >
                                        <span x-text="cell.day" :class="cell.dayClasses"></span>

                                        {{-- Partial: remaining units badge --}}
                                        <template x-if="cell.showBadge">
                                            <span
                                                x-text="cell.availableQty"
                                                class="absolute bottom-0.5 right-0.5 text-[9px] font-bold leading-none text-warning"
                                                aria-hidden="true"
                                            ></span>
                                        </template>
                                    </div>
                                </template>
                            </div>

                            {{-- Legend --}}
                            <div class="flex items-center justify-center gap-4 mt-4 pt-3 border-t border-border">
                                <span class="flex items-center gap-1.5 text-xs text-text-muted">
                                    <span class="w-3 h-3 rounded-full bg-success/60 shrink-0" aria-hidden="true"></span>
                                    Dostępne
                                </span>
                                <span class="flex items-center gap-1.5 text-xs text-text-muted">
                                    <span class="w-3 h-3 rounded-full bg-warning/60 shrink-0" aria-hidden="true"></span>
                                    Ograniczone
                                </span>
                                <span class="flex items-center gap-1.5 text-xs text-text-muted">
                                    <span class="w-3 h-3 rounded-full bg-border-strong shrink-0" aria-hidden="true"></span>
                                    Niedostępne
                                </span>
                            </div>
                        </div>
                        {{-- Availability result for selected range --}}
                        <div x-show="selectedStart && selectedEnd && !rangeChecking && rangeAvailableQty !== null" x-transition class="mt-4 text-sm" aria-live="polite">
                            <p x-show="rangeAvailableQty > 0" class="text-success flex items-center gap-1.5">
                                <span class="h-2 w-2 rounded-full bg-success shrink-0"></span>
                                Dostępnych: <span x-text="rangeAvailableQty"></span> szt.
                            </p>
                            <p x-show="rangeAvailableQty === 0" class="text-error flex items-center gap-1.5">
                                <span class="h-2 w-2 rounded-full bg-error shrink-0"></span>
                                Brak dostępności w wybranym terminie
                            </p>
                        </div>
                        <div x-show="rangeChecking" class="mt-4 text-sm text-text-muted">Sprawdzam dostępność...</div>
                        </div>
                        {{-- ─── /Availability Calendar ─── --}}
                    </x-ui.card>
                </div>
            </div>
        </div>
    </x-layout.section>

@else
    {{-- ═══════════════════════════════════════════════════════════
         TIME_SLOT SERVICE — Standard 2-column layout
         ═══════════════════════════════════════════════════════════ --}}
    <x-layout.section spacing="sm">
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-12 items-start">

            {{-- Left: Image --}}
            <div>
                @if($service->featured_image)
                    <x-media.image :src="$service->featured_image" :alt="$service->name" aspect="4/3" rounded="xl" />
                @else
                    <div class="aspect-[4/3] rounded-xl bg-surface-sunken flex items-center justify-center">
                        <x-heroicon-o-photo class="h-16 w-16 text-text-muted" />
                    </div>
                @endif
            </div>

            {{-- Right: Info + CTA --}}
            <div>
                <h1 class="text-3xl md:text-4xl font-bold text-text-primary tracking-tight mb-4">
                    {{ $service->name }}
                </h1>

                @if($service->excerpt)
                    <p class="text-lg text-text-secondary mb-6">{{ $service->excerpt }}</p>
                @endif

                @if($service->duration_minutes)
                    <div class="mb-4">
                        <x-ui.badge variant="default" icon="clock">{{ $service->formatted_duration }}</x-ui.badge>
                    </div>
                @endif

                @if($service->price)
                    <div class="text-3xl font-bold text-text-primary mb-8">
                        {{ $service->price_from ? 'od ' : '' }}{{ number_format($service->price_from ?? $service->price, 0, ',', ' ') }} zł
                    </div>
                @endif

                @auth
                    @if($bookingEnabled)
                        <x-ui.button href="{{ route('booking.step', ['step' => 1]) }}" size="lg" icon-right="arrow-right" class="w-full sm:w-auto">
                            Zarezerwuj termin
                        </x-ui.button>
                    @elseif($contactPhone)
                        <x-ui.button href="tel:{{ $contactPhone }}" size="lg" icon="phone" class="w-full sm:w-auto">
                            Zadzwoń: {{ $contactPhone }}
                        </x-ui.button>
                    @endif
                @else
                    <x-ui.button href="{{ route('register') }}" size="lg" icon-right="arrow-right" class="w-full sm:w-auto">
                        Zarejestruj się, aby zarezerwować
                    </x-ui.button>
                @endauth
            </div>
        </div>
    </x-layout.section>

    {{-- Body Content --}}
    @if($service->body && trim(strip_tags($service->body)))
        <x-layout.section spacing="sm">
            <div class="max-w-3xl mx-auto prose prose-lg prose-registro">
                {!! clean($service->body) !!}
            </div>
        </x-layout.section>
    @endif

    {{-- Builder Blocks --}}
    @if($service->content)
        @foreach($service->content as $block)
            @php $blockType = $block['type'] ?? ''; @endphp

            @if($blockType === 'text_block')
                <x-content-blocks.text-block :data="$block['data']" />
            @elseif($blockType === 'service_features')
                <x-content-blocks.service-features
                    :heading="$block['data']['heading'] ?? 'Co zawiera usługa'"
                    :layout="$block['data']['layout'] ?? 'simple'"
                    :service="$service"
                />
            @elseif($blockType === 'content_grid')
                <x-content-blocks.content-grid :data="$block['data']" />
            @elseif($blockType === 'image' && !empty($block['data']['image']))
                <x-layout.section spacing="sm">
                    <div class="max-w-4xl mx-auto">
                        <x-media.image :src="$block['data']['image']" :alt="$block['data']['alt'] ?? ''" rounded="xl" />
                        @if(!empty($block['data']['caption']))
                            <p class="text-sm text-text-muted text-center mt-3">{{ $block['data']['caption'] }}</p>
                        @endif
                    </div>
                </x-layout.section>
            @elseif($blockType === 'quote')
                <x-layout.section spacing="sm">
                    <blockquote class="max-w-3xl mx-auto border-l-4 border-brand pl-6 py-4">
                        <p class="text-xl text-text-secondary italic leading-relaxed">{{ $block['data']['quote'] }}</p>
                        @if(!empty($block['data']['author']))
                            <footer class="mt-4 text-sm text-text-muted">
                                <strong class="text-text-primary">{{ $block['data']['author'] }}</strong>
                                @if(!empty($block['data']['author_title']))
                                    <span> — {{ $block['data']['author_title'] }}</span>
                                @endif
                            </footer>
                        @endif
                    </blockquote>
                </x-layout.section>
            @elseif($blockType === 'cta' && !empty($block['data']['heading']))
                <x-layout.section spacing="sm">
                    <x-ui.card class="max-w-3xl mx-auto text-center bg-brand-subtle">
                        <h3 class="text-2xl font-bold text-text-primary mb-3">{{ $block['data']['heading'] }}</h3>
                        @if(!empty($block['data']['description']))
                            <p class="text-text-secondary mb-6">{{ $block['data']['description'] }}</p>
                        @endif
                        @if(!empty($block['data']['button_url']))
                            <x-ui.button href="{{ $block['data']['button_url'] }}" icon-right="arrow-right">
                                {{ $block['data']['button_text'] ?? 'Dowiedz się więcej' }}
                            </x-ui.button>
                        @endif
                    </x-ui.card>
                </x-layout.section>
            @endif
        @endforeach
    @endif

    {{-- Related Services --}}
    @if($relatedServices->count() > 0)
        <x-layout.section>
            <h2 class="text-2xl font-bold text-text-primary mb-8 text-center">Powiązane usługi</h2>
            <x-layout.grid cols="3" gap="8">
                @foreach($relatedServices as $related)
                    <x-ui.card hover href="{{ route('service.show', $related) }}" class="group">
                        @if($related->featured_image)
                            <div class="-mx-6 -mt-6 mb-4 overflow-hidden rounded-t-xl">
                                <x-media.image :src="$related->featured_image" :alt="$related->name" aspect="16/9" rounded="none" />
                            </div>
                        @endif
                        <h3 class="font-semibold text-text-primary group-hover:text-brand transition-colors">{{ $related->name }}</h3>
                        @if($related->price)
                            <p class="text-sm text-text-muted mt-1">od {{ number_format($related->price, 0, ',', ' ') }} zł</p>
                        @endif
                    </x-ui.card>
                @endforeach
            </x-layout.grid>
        </x-layout.section>
    @endif
@endif

@endsection

@if($isRental)
@push('scripts')
<script>
function availabilityCalendar({ apiUrl, today, currentYear, currentMonth, pricePerDay, pricePerDayLong, thresholdDays, pricePerWeek, depositAmount }) {
    return {
        // ── State ─────────────────────────────────────────────────
        year:         currentYear,
        month:        currentMonth,   // 1-indexed
        today:        today,          // "YYYY-MM-DD"
        loading:      true,
        data:         {},             // { "YYYY-MM-DD": { status, available_quantity } }
        selectedStart: null,          // "YYYY-MM-DD" or null
        selectedEnd:   null,          // "YYYY-MM-DD" or null
        rangeAvailableQty: null,      // int or null (from AJAX)
        rangeChecking: false,         // loading state

        pricePerDay:     pricePerDay ?? 0,
        pricePerDayLong: pricePerDayLong ?? 0,
        thresholdDays:   thresholdDays ?? 0,
        pricePerWeek:    pricePerWeek ?? 0,
        depositAmount:   depositAmount ?? 0,

        get rentalDays() {
            if (!this.selectedStart || !this.selectedEnd) return 0;
            const s = new Date(this.selectedStart + 'T00:00:00');
            const e = new Date(this.selectedEnd + 'T00:00:00');
            return Math.max(0, Math.round((e - s) / 86400000) + 1);
        },
        get unitPrice() {
            if (this.pricePerDayLong > 0 && this.thresholdDays > 0 && this.rentalDays >= this.thresholdDays) return this.pricePerDayLong;
            return this.pricePerDay;
        },
        get totalPrice() {
            if (this.rentalDays <= 0) return 0;
            if (this.pricePerWeek > 0 && this.rentalDays >= 7) {
                const wpd = this.pricePerWeek / 7;
                if (wpd < this.unitPrice) {
                    return Math.floor(this.rentalDays / 7) * this.pricePerWeek + (this.rentalDays % 7) * this.unitPrice;
                }
            }
            return this.unitPrice * this.rentalDays;
        },
        get isDiscounted() {
            return this.pricePerDayLong > 0 && this.thresholdDays > 0 && this.rentalDays >= this.thresholdDays;
        },
        formatPrice(v) { return v.toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, ' '); },

        get canBook() {
            return this.selectedStart && this.selectedEnd && this.rangeAvailableQty > 0 && !this.rangeChecking;
        },
        get bookingUrl() {
            const base = '{{ route("rental.step1", $service) }}';
            if (!this.selectedStart || !this.selectedEnd) return base;
            return base + '?start_date=' + this.selectedStart + '&end_date=' + this.selectedEnd;
        },

        // ── Date selection ───────────────────────────────────────
        selectDate(dateStr) {
            if (!this.selectedStart || (this.selectedStart && this.selectedEnd)) {
                this.selectedStart = dateStr;
                this.selectedEnd = null;
            } else if (dateStr === this.selectedStart) {
                this.selectedEnd = dateStr; // single-day rental
            } else if (dateStr < this.selectedStart) {
                this.selectedEnd = this.selectedStart;
                this.selectedStart = dateStr;
            } else {
                this.selectedEnd = dateStr;
            }
        },
        isInRange(dateStr) {
            if (!this.selectedStart) return false;
            if (!this.selectedEnd) return dateStr === this.selectedStart;
            return dateStr >= this.selectedStart && dateStr <= this.selectedEnd;
        },
        selectionClass(dateStr) {
            if (!dateStr || !this.selectedStart) return '';
            const isStart = dateStr === this.selectedStart;
            const isEnd   = dateStr === this.selectedEnd;
            const inRange = this.isInRange(dateStr);
            if (!inRange) return '';
            // Single day (start === end or no end yet)
            if (isStart && (!this.selectedEnd || isEnd)) return 'bg-brand text-white rounded-lg shadow-md';
            // Start of range
            if (isStart) return 'bg-brand text-white rounded-l-lg shadow-sm';
            // End of range
            if (isEnd) return 'bg-brand text-white rounded-r-lg shadow-sm';
            // Mid range — must be CLEARLY visible, not transparent
            return 'bg-brand/40 text-brand rounded-none';
        },

        // ── Computed: heading label ────────────────────────────────
        get headingLabel() {
            const d = new Date(this.year, this.month - 1, 1);
            const raw = d.toLocaleDateString('pl-PL', { month: 'long', year: 'numeric' });
            // Capitalise first letter (toLocaleDateString returns lowercase in pl)
            return raw.charAt(0).toUpperCase() + raw.slice(1);
        },

        // ── Computed: disable prev nav when already at current month ─
        get isPrevDisabled() {
            const [ty, tm] = this.today.split('-').map(Number);
            return this.year < ty || (this.year === ty && this.month <= tm);
        },

        // ── Computed: day cells for the grid ──────────────────────
        get dayCells() {
            const daysInMonth  = new Date(this.year, this.month, 0).getDate();
            // Monday-first: 0=Mon … 6=Sun
            const firstDayRaw  = new Date(this.year, this.month - 1, 1).getDay();
            const leadingBlanks = (firstDayRaw + 6) % 7; // 0=Mon offset

            const cells = [];

            // Leading blank cells
            for (let i = 0; i < leadingBlanks; i++) {
                cells.push({ day: null, classes: '', dayClasses: '', ariaLabel: null, ariaDisabled: null, ariaCurrent: null, showBadge: false, availableQty: null });
            }

            for (let d = 1; d <= daysInMonth; d++) {
                const mm   = String(this.month).padStart(2, '0');
                const dd   = String(d).padStart(2, '0');
                const key  = `${this.year}-${mm}-${dd}`;
                const info = this.data[key] ?? null;

                const isToday = key === this.today;
                const isPast  = key < this.today;
                const status  = info?.status ?? (isPast ? 'unavailable' : 'available');
                const qty     = info?.available_quantity ?? null;

                // Build classes
                const clickable = !isPast && status !== 'unavailable';
                let cellCls = clickable ? 'cursor-pointer ' : 'cursor-default ';
                let dayTextCls = '';

                if (isPast) {
                    cellCls    += 'opacity-40 ';
                    dayTextCls += 'line-through ';
                }

                if (status === 'available' && !isPast) {
                    cellCls += 'bg-success/30 text-success font-semibold ';
                } else if (status === 'partial' && !isPast) {
                    cellCls += 'bg-warning/30 text-warning font-semibold ';
                } else if (!isPast) {
                    cellCls += 'bg-surface-sunken text-text-muted cursor-not-allowed ';
                } else {
                    cellCls += 'text-text-muted ';
                }

                // Today — thick ring, clearly visible
                if (isToday) {
                    cellCls += 'ring-2 ring-brand ring-offset-2 ';
                }

                // Aria
                const polishMonths = ['stycznia','lutego','marca','kwietnia','maja','czerwca','lipca','sierpnia','września','października','listopada','grudnia'];
                const statusLabel = status === 'available' ? 'dostępny'
                    : status === 'partial' ? `ograniczona dostępność, ${qty} szt.`
                    : 'niedostępny';
                const ariaLabel = `${d} ${polishMonths[this.month - 1]} ${this.year}, ${statusLabel}`;

                cells.push({
                    day:          d,
                    dateStr:      key,
                    clickable,
                    classes:      cellCls.trim(),
                    dayClasses:   dayTextCls.trim(),
                    ariaLabel,
                    ariaDisabled: status === 'unavailable' ? 'true' : null,
                    ariaCurrent:  isToday ? 'date' : null,
                    showBadge:    status === 'partial' && qty !== null && !isPast,
                    availableQty: qty,
                });
            }

            // Trailing blanks to complete last row (always render full 6-row grid for stable height)
            const totalCells = 42;
            while (cells.length < totalCells) {
                cells.push({ day: null, classes: '', dayClasses: '', ariaLabel: null, ariaDisabled: null, ariaCurrent: null, showBadge: false, availableQty: null });
            }

            return cells;
        },

        // ── Lifecycle ─────────────────────────────────────────────
        init() {
            this.fetchMonth();
            this.$watch('selectedEnd', (val) => {
                if (val) this.checkRangeAvailability();
                else this.rangeAvailableQty = null;
            });
        },

        async checkRangeAvailability() {
            if (!this.selectedStart || !this.selectedEnd) return;
            this.rangeChecking = true;
            this.rangeAvailableQty = null;
            try {
                const url = `{{ route('rental.availability', $service) }}?start_date=${this.selectedStart}&end_date=${this.selectedEnd}`;
                const res = await fetch(url, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
                if (!res.ok) throw new Error();
                const json = await res.json();
                this.rangeAvailableQty = json.available_quantity;
            } catch { this.rangeAvailableQty = 0; }
            finally { this.rangeChecking = false; }
        },

        // ── Navigation ────────────────────────────────────────────
        prevMonth() {
            if (this.isPrevDisabled) return;
            if (this.month === 1) { this.year--; this.month = 12; }
            else                  { this.month--; }
            this.fetchMonth();
        },

        nextMonth() {
            if (this.month === 12) { this.year++; this.month = 1; }
            else                   { this.month++; }
            this.fetchMonth();
        },

        // ── Data fetch ────────────────────────────────────────────
        async fetchMonth() {
            this.loading = true;
            const url = `${apiUrl}?year=${this.year}&month=${this.month}`;
            try {
                const res = await fetch(url, {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });
                if (!res.ok) throw new Error(`HTTP ${res.status}`);
                const raw = await res.json();
                // Normalise: handle both flat { "YYYY-MM-DD": {...} } response shapes
                this.data = raw;
            } catch (e) {
                // Graceful degradation: leave data empty, cells render as default
                this.data = {};
            } finally {
                this.loading = false;
            }
        },
    };
}
</script>
@endpush
@endif

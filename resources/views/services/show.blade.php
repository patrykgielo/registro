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
                @if(!empty($specs))
                    <div>
                        <h2 class="text-lg font-semibold text-text-primary mb-4">Specyfikacja techniczna</h2>
                        <div class="rounded-xl border border-border overflow-hidden">
                            @foreach($specs as $index => $spec)
                                @php
                                    // Support both new format [{label, value, unit}] and legacy {key: value}
                                    $isNewFormat = is_array($spec) && isset($spec['label']);
                                    $label = $isNewFormat ? $spec['label'] : ucfirst(str_replace('_', ' ', $index));
                                    $value = $isNewFormat ? $spec['value'] : $spec;
                                    $unit = $isNewFormat ? ($spec['unit'] ?? '') : '';
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
                <div class="sticky top-20">
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

                        {{-- CTA --}}
                        <div class="space-y-3">
                            <x-ui.button href="{{ route('rental.step1', $service) }}" size="lg" icon-right="arrow-right" class="w-full">
                                Zarezerwuj online
                            </x-ui.button>
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

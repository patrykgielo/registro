@extends('layouts.app')

@section('content')

{{-- Page header --}}
<x-layout.section spacing="sm" class="bg-surface-sunken">
    <x-layout.container>
        <div class="flex items-center gap-3">
            <h1 class="text-3xl font-bold text-text-primary tracking-tight">
                Twój koszyk
            </h1>
            @if($cart->items->count() > 0)
                <span class="inline-flex items-center justify-center h-7 min-w-[1.75rem] px-2 rounded-full bg-brand text-text-inverse text-sm font-semibold tabular-nums"
                      aria-label="{{ $cart->items->count() }} {{ $cart->items->count() === 1 ? 'pozycja' : ($cart->items->count() <= 4 ? 'pozycje' : 'pozycji') }}">
                    {{ $cart->items->count() }}
                </span>
            @endif
        </div>
    </x-layout.container>
</x-layout.section>

{{-- Main content --}}
<x-layout.section spacing="default">
    @if($cart->items->count() > 0)
        {{-- Two-column layout: items list + order summary --}}
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8 items-start">

            {{-- ── Cart items list ── --}}
            <div class="lg:col-span-2 space-y-4" role="list" aria-label="Pozycje w koszyku">

                @foreach($cart->items as $item)
                    <article
                        class="rounded-xl border border-border bg-surface-raised shadow-xs overflow-hidden"
                        role="listitem"
                        aria-label="{{ $item->service->name }}"
                    >
                        <div class="flex gap-0 sm:gap-4">

                            {{-- Thumbnail --}}
                            <div class="hidden sm:block w-36 shrink-0 bg-surface-sunken">
                                @if($item->service->featured_image)
                                    <img
                                        src="{{ Storage::url($item->service->featured_image) }}"
                                        alt="{{ $item->service->name }}"
                                        class="w-full h-full object-cover aspect-square"
                                        loading="lazy"
                                        decoding="async"
                                    >
                                @else
                                    <div class="w-full h-full min-h-[144px] flex items-center justify-center">
                                        <x-heroicon-o-photo class="h-10 w-10 text-text-muted" aria-hidden="true" />
                                    </div>
                                @endif
                            </div>

                            {{-- Content --}}
                            <div class="flex-1 p-5 min-w-0">
                                <div class="flex items-start justify-between gap-4">
                                    <div class="min-w-0">
                                        <h2 class="text-base font-semibold text-text-primary truncate">
                                            {{ $item->service->name }}
                                        </h2>

                                        {{-- Rental period --}}
                                        <dl class="mt-1.5 flex flex-wrap gap-x-4 gap-y-1 text-sm text-text-secondary">
                                            <div class="flex items-center gap-1.5">
                                                <x-heroicon-m-calendar-days class="h-4 w-4 text-text-muted shrink-0" aria-hidden="true" />
                                                <dt class="sr-only">Okres wynajmu</dt>
                                                <dd>
                                                    <time datetime="{{ $item->start_date }}">{{ \Carbon\Carbon::parse($item->start_date)->format('d.m.Y') }}</time>
                                                    <span aria-hidden="true"> – </span>
                                                    <time datetime="{{ $item->end_date }}">{{ \Carbon\Carbon::parse($item->end_date)->format('d.m.Y') }}</time>
                                                </dd>
                                            </div>
                                            <div class="flex items-center gap-1.5">
                                                <x-heroicon-m-clock class="h-4 w-4 text-text-muted shrink-0" aria-hidden="true" />
                                                <dt class="sr-only">Liczba dni</dt>
                                                <dd>{{ $item->rental_days }} {{ $item->rental_days === 1 ? 'dzień' : ($item->rental_days <= 4 ? 'dni' : 'dni') }}</dd>
                                            </div>
                                        </dl>

                                        {{-- Unit price --}}
                                        <p class="mt-1.5 text-sm text-text-muted">
                                            {{ number_format($item->unit_price, 2, ',', ' ') }}&nbsp;zł/dzień
                                            @if($item->rental_days > 1)
                                                <span aria-hidden="true"> × {{ $item->rental_days }} dni</span>
                                            @endif
                                        </p>
                                    </div>

                                    {{-- Total price (top-right) --}}
                                    <div class="shrink-0 text-right">
                                        <span class="text-lg font-bold text-text-primary tabular-nums">
                                            {{ number_format($item->total_price, 2, ',', ' ') }}&nbsp;zł
                                        </span>
                                    </div>
                                </div>

                                {{-- Quantity + Remove row --}}
                                <div class="mt-4 flex items-center justify-between gap-3 flex-wrap">

                                    {{-- Quantity form --}}
                                    <form
                                        action="{{ route('cart.update', $item) }}"
                                        method="POST"
                                        class="flex items-center gap-2"
                                        aria-label="Zmień ilość: {{ $item->service->name }}"
                                    >
                                        @csrf
                                        @method('PATCH')
                                        <label
                                            for="quantity-{{ $item->id }}"
                                            class="text-sm text-text-secondary"
                                        >
                                            Ilość:
                                        </label>
                                        <input
                                            type="number"
                                            id="quantity-{{ $item->id }}"
                                            name="quantity"
                                            value="{{ $item->quantity }}"
                                            min="1"
                                            step="1"
                                            class="w-16 h-9 px-2 text-sm text-center rounded-lg border border-border bg-surface-raised text-text-primary
                                                   focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50
                                                   transition-colors duration-200"
                                            aria-label="Ilość sztuk: {{ $item->service->name }}"
                                        >
                                        <button
                                            type="submit"
                                            class="h-9 px-3 text-sm font-medium rounded-lg border border-border bg-surface-raised text-text-secondary
                                                   hover:bg-surface-sunken hover:border-border-strong hover:text-text-primary
                                                   focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50 focus-visible:ring-offset-2
                                                   transition-all duration-200 cursor-pointer"
                                            aria-label="Zaktualizuj ilość: {{ $item->service->name }}"
                                        >
                                            Aktualizuj
                                        </button>
                                    </form>

                                    {{-- Remove form --}}
                                    <form
                                        action="{{ route('cart.remove', $item) }}"
                                        method="POST"
                                        aria-label="Usuń z koszyka: {{ $item->service->name }}"
                                    >
                                        @csrf
                                        @method('DELETE')
                                        <button
                                            type="submit"
                                            class="inline-flex items-center gap-1.5 h-9 px-3 text-sm font-medium rounded-lg
                                                   text-error hover:bg-error/5 hover:text-error
                                                   focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-error/50 focus-visible:ring-offset-2
                                                   transition-all duration-200 cursor-pointer"
                                            aria-label="Usuń {{ $item->service->name }} z koszyka"
                                        >
                                            <x-heroicon-m-trash class="h-4 w-4 shrink-0" aria-hidden="true" />
                                            Usuń
                                        </button>
                                    </form>

                                </div>
                            </div>
                        </div>
                    </article>
                @endforeach

            </div>

            {{-- ── Order summary sidebar ── --}}
            <aside aria-label="Podsumowanie zamówienia">
                <x-ui.card class="sticky top-6">
                    <h2 class="text-base font-semibold text-text-primary mb-4">
                        Podsumowanie
                    </h2>

                    <dl class="space-y-2 text-sm">
                        @foreach($cart->items as $item)
                            <div class="flex justify-between gap-3">
                                <dt class="text-text-secondary truncate min-w-0 flex-1">
                                    {{ $item->service->name }}
                                    @if($item->quantity > 1)
                                        <span class="text-text-muted"> ×&thinsp;{{ $item->quantity }}</span>
                                    @endif
                                </dt>
                                <dd class="shrink-0 font-medium text-text-primary tabular-nums">
                                    {{ number_format($item->total_price, 2, ',', ' ') }}&nbsp;zł
                                </dd>
                            </div>
                        @endforeach
                    </dl>

                    <div class="mt-4 pt-4 border-t border-border">
                        <div class="flex justify-between items-baseline gap-3">
                            <span class="text-sm font-medium text-text-secondary">Razem</span>
                            <span class="text-xl font-bold text-text-primary tabular-nums">
                                {{ number_format($cart->items->sum('total_price'), 2, ',', ' ') }}&nbsp;zł
                            </span>
                        </div>
                        <p class="mt-1 text-xs text-text-muted">Ceny brutto (w tym VAT {{ app(\App\Support\Settings\SettingsManager::class)->vatRate() }}%)</p>
                    </div>

                    <div class="mt-6">
                        <x-ui.button
                            href="{{ route('checkout.show') }}"
                            size="lg"
                            icon-right="arrow-right"
                            class="w-full justify-center"
                        >
                            Do kasy
                        </x-ui.button>
                    </div>

                    <div class="mt-3 text-center">
                        <a
                            href="{{ route('services.index') }}"
                            class="text-sm text-text-muted hover:text-brand transition-colors duration-200
                                   focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50 rounded"
                        >
                            Kontynuuj zakupy
                        </a>
                    </div>
                </x-ui.card>
            </aside>

        </div>

    @else

        {{-- ── Empty state ── --}}
        <div class="max-w-md mx-auto text-center py-16">
            <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-surface-sunken mb-6">
                <x-heroicon-o-shopping-cart class="h-8 w-8 text-text-muted" aria-hidden="true" />
            </div>
            <h2 class="text-xl font-semibold text-text-primary mb-2">
                Koszyk jest pusty
            </h2>
            <p class="text-text-secondary mb-8">
                Nie masz jeszcze żadnych produktów w koszyku. Przeglądaj nasze usługi i dodaj coś do koszyka.
            </p>
            <x-ui.button href="{{ route('services.index') }}" icon="arrow-left">
                Przeglądaj usługi
            </x-ui.button>
        </div>

    @endif
</x-layout.section>

@endsection

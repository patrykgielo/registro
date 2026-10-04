@extends('layouts.app')

@section('content')

{{-- ────────────────────────────────────────────────────────────────────────────
     Hero
     ──────────────────────────────────────────────────────────────────────────── --}}
<x-layout.section spacing="lg" class="bg-surface-sunken">
    <div class="max-w-3xl mx-auto text-center">
        <h1 class="text-4xl md:text-5xl font-bold text-text-primary tracking-tight mb-4">
            {{ __('rentals.index.title') }}
        </h1>
        <p class="text-lg md:text-xl text-text-secondary">
            {{ __('rentals.index.intro') }}
        </p>
    </div>
</x-layout.section>

{{-- ────────────────────────────────────────────────────────────────────────────
     Category grid
     ──────────────────────────────────────────────────────────────────────────── --}}
<x-layout.section>
    @if($categories->isNotEmpty())
        <div class="mb-8">
            <h2 class="text-2xl font-bold text-text-primary tracking-tight">
                {{ __('rentals.categories') }}
            </h2>
        </div>

        <x-layout.grid cols="3" gap="6">
            @foreach($categories as $category)
                <x-ui.card
                    hover
                    href="{{ route('rental.category', $category) }}"
                    class="group flex flex-col gap-4"
                    data-animate
                    data-animate-delay="{{ $loop->index * 60 }}"
                    aria-label="{{ $category->name }}{{ $category->services_count > 0 ? ', ' . trans_choice('common.positions', $category->services_count) : '' }}"
                >
                    {{-- Icon --}}
                    <div
                        class="flex items-center justify-center w-12 h-12 rounded-xl bg-brand-subtle text-brand
                               transition-transform duration-200 ease-out group-hover:scale-105"
                        aria-hidden="true"
                    >
                        @if($category->icon)
                            <x-dynamic-component
                                :component="'heroicon-m-' . $category->icon"
                                class="h-6 w-6"
                            />
                        @else
                            <x-heroicon-m-archive-box class="h-6 w-6" />
                        @endif
                    </div>

                    {{-- Name + count --}}
                    <div class="flex-1">
                        <h3 class="text-base font-semibold text-text-primary group-hover:text-brand transition-colors duration-200">
                            {{ $category->name }}
                        </h3>
                        <p class="text-sm text-text-muted mt-0.5">
                            @if($category->services_count > 0)
                                {{ trans_choice('common.positions', $category->services_count) }}
                            @else
                                {{ __('rentals.no_items') }}
                            @endif
                        </p>
                    </div>

                    {{-- Przeglądaj cue --}}
                    <div class="flex items-center gap-1.5 text-sm font-medium text-brand mt-auto">
                        <span>{{ __('rentals.browse') }}</span>
                        <x-heroicon-m-arrow-right
                            class="h-4 w-4 transition-transform duration-200 ease-out group-hover:translate-x-0.5"
                            aria-hidden="true"
                        />
                    </div>
                </x-ui.card>
            @endforeach
        </x-layout.grid>
    @else
        {{-- Empty state --}}
        <div class="max-w-md mx-auto text-center py-16">
            <x-heroicon-o-archive-box class="h-16 w-16 text-text-muted mx-auto mb-4" aria-hidden="true" />
            <h3 class="text-xl font-semibold text-text-primary mb-2">{{ __('rentals.index.empty_title') }}</h3>
            <p class="text-text-secondary">
                {{ __('rentals.index.empty_text') }}
            </p>
        </div>
    @endif
</x-layout.section>

{{-- ────────────────────────────────────────────────────────────────────────────
     Featured / latest items (only when there is something to show)
     ──────────────────────────────────────────────────────────────────────────── --}}
@if($featuredServices->isNotEmpty())
    <x-layout.section class="bg-surface-sunken">
        <div class="mb-8">
            <h2 class="text-2xl font-bold text-text-primary tracking-tight">
                {{ __('rentals.index.latest') }}
            </h2>
            <p class="text-text-secondary mt-1">{{ __('rentals.index.latest_sub') }}</p>
        </div>

        <x-layout.grid cols="3" gap="8">
            @foreach($featuredServices as $service)
                <x-ios.service-card :service="$service" :available-quantity="$locationAvailability[$service->id] ?? null" />
            @endforeach
        </x-layout.grid>
    </x-layout.section>
@endif

@endsection

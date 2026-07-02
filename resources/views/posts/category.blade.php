@extends('layouts.app')

@section('content')

{{-- ────────────────────────────────────────────────────────────────────────────
     Breadcrumb
     ──────────────────────────────────────────────────────────────────────────── --}}
<x-layout.container class="pt-6 pb-2">
    <nav class="text-sm text-text-muted" aria-label="Breadcrumb">
        <ol class="flex items-center gap-2 flex-wrap">
            <li>
                <a
                    href="{{ route('home') }}"
                    class="hover:text-text-primary transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand rounded"
                >
                    Strona główna
                </a>
            </li>
            <li aria-hidden="true"><x-heroicon-m-chevron-right class="h-4 w-4 shrink-0" /></li>
            <li class="text-text-muted">
                Aktualności
            </li>
            <li aria-hidden="true"><x-heroicon-m-chevron-right class="h-4 w-4 shrink-0" /></li>
            <li class="text-text-primary font-medium truncate max-w-[200px]" aria-current="page">
                {{ $category->name }}
            </li>
        </ol>
    </nav>
</x-layout.container>

{{-- ────────────────────────────────────────────────────────────────────────────
     2-column layout: sidebar + content
     ──────────────────────────────────────────────────────────────────────────── --}}
<x-layout.section spacing="sm">
    <div class="flex gap-8 lg:gap-12 items-start">

        {{-- ─────────────────────────────────────────────────────────────────
             Sidebar — category list (sticky on lg+, collapses to horizontal
             scroll on mobile)
             ───────────────────────────────────────────────────────────────── --}}
        <aside
            class="hidden lg:block lg:w-56 xl:w-64 shrink-0 sticky top-20"
            aria-label="Kategorie aktualności"
        >
            <div class="rounded-xl border border-border bg-surface-raised shadow-xs overflow-hidden">
                <div class="px-4 py-3 border-b border-border">
                    <p class="text-xs font-semibold text-text-muted uppercase tracking-wider">
                        Kategorie
                    </p>
                </div>
                <nav>
                    <ul role="list">
                        @foreach($allCategories as $cat)
                            @php
                                $isActive = $cat->id === $category->id;
                            @endphp
                            <li>
                                <a
                                    href="{{ route('post.category', $cat) }}"
                                    class="flex items-center justify-between gap-3 px-4 py-2.5 text-sm transition-colors focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-brand
                                        {{ $isActive
                                            ? 'bg-brand-subtle text-brand font-semibold'
                                            : 'text-text-secondary hover:text-text-primary hover:bg-surface-sunken'
                                        }}"
                                    aria-current="{{ $isActive ? 'page' : 'false' }}"
                                >
                                    <span class="flex items-center gap-2 min-w-0">
                                        <x-heroicon-m-newspaper
                                            class="h-4 w-4 shrink-0 {{ $isActive ? 'text-brand' : 'text-text-muted' }}"
                                            aria-hidden="true"
                                        />
                                        <span class="truncate">{{ $cat->name }}</span>
                                    </span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </nav>
            </div>
        </aside>

        {{-- ─────────────────────────────────────────────────────────────────
             Mobile: horizontal category pills (visible below lg)
             ───────────────────────────────────────────────────────────────── --}}
        <div class="lg:hidden -mx-4 sm:-mx-6 mb-6 w-screen px-4 sm:px-6" aria-label="Kategorie aktualności">
            <div class="flex items-center gap-2 overflow-x-auto pb-2 scrollbar-hide" role="list">
                @foreach($allCategories as $cat)
                    @php $isActive = $cat->id === $category->id; @endphp
                    <a
                        href="{{ route('post.category', $cat) }}"
                        role="listitem"
                        class="shrink-0 inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-sm font-medium transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand
                            {{ $isActive
                                ? 'bg-brand text-text-inverse'
                                : 'bg-surface-raised border border-border text-text-secondary hover:text-text-primary hover:border-border-strong'
                            }}"
                        aria-current="{{ $isActive ? 'page' : 'false' }}"
                    >
                        {{ $cat->name }}
                    </a>
                @endforeach
            </div>
        </div>

        {{-- ─────────────────────────────────────────────────────────────────
             Main content area
             ───────────────────────────────────────────────────────────────── --}}
        <div class="flex-1 min-w-0">

            {{-- Category heading --}}
            <div class="mb-6">
                <h1 class="text-2xl md:text-3xl font-bold text-text-primary tracking-tight mb-1">
                    {{ $category->name }}
                </h1>
                @if($category->description)
                    <p class="text-text-secondary mt-2 leading-relaxed">
                        {{ $category->description }}
                    </p>
                @endif
            </div>

            {{-- Posts grid --}}
            @if($items->isNotEmpty())
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8">
                    @foreach($items as $item)
                        <x-cms.card :item="$item" :url="route('post.show', $item->slug)" :dark="false" />
                    @endforeach
                </div>

                <div class="mt-10">
                    {{ $items->links() }}
                </div>
            @else
                {{-- Empty state --}}
                <div class="text-center py-16 rounded-xl border border-border bg-surface-raised">
                    <x-heroicon-o-newspaper
                        class="h-14 w-14 text-text-muted mx-auto mb-4"
                        aria-hidden="true"
                    />
                    <h3 class="text-lg font-semibold text-text-primary mb-2">
                        Brak dostępnych wpisów
                    </h3>
                    <p class="text-text-secondary text-sm mb-6">
                        Brak dostępnych wpisów w kategorii „{{ $category->name }}".
                    </p>
                    <x-ui.button
                        variant="secondary"
                        href="{{ route('home') }}"
                        icon="arrow-left"
                    >
                        Wróć do strony głównej
                    </x-ui.button>
                </div>
            @endif

        </div>
    </div>
</x-layout.section>

@endsection

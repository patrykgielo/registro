@extends('layouts.app')

{{--
    Faza 6 krok 6.2 (86cbahqgv, plan-wdrozenia.md) — the "pytanie, nie błąd"
    confirmation the acceptance criterion requires before a non-empty cart's
    pickup location actually changes. Rendered by
    LocationSelectionController::store() itself (same route as the switcher,
    see that controller's own docblock) instead of a second endpoint — this
    page's own form posts BACK to `location.select` with `confirmed=1`.

    $preview is CartService::previewLocationChange()'s read-only projection:
    list<array{item: CartItem, requested: int, available: int, kept: int}>.
    Nothing here has been persisted yet — setLocation() re-validates under
    lock the moment the customer actually confirms (see that method's own
    docblock for why this preview cannot simply be trusted as-is).
--}}

@section('content')

<x-layout.section spacing="default">
    <x-layout.container class="max-w-2xl">
        <h1 class="text-2xl font-bold text-text-primary tracking-tight mb-2">
            Zmienić oddział odbioru?
        </h1>
        <p class="text-text-secondary mb-6">
            Masz pozycje w koszyku {{ $currentLocation ? 'z odbiorem w oddziale „'.$currentLocation->name.'"' : '' }}.
            Przełączenie na oddział <strong>{{ $newLocation->name }}</strong> zmieni miejsce odbioru
            całego zamówienia — sprawdziliśmy dostępność Twoich pozycji w nowym oddziale poniżej.
        </p>

        <div class="rounded-xl border border-border bg-surface-raised divide-y divide-border mb-6" role="list" aria-label="Wpływ zmiany oddziału na koszyk">
            @foreach($preview as $decision)
                @php
                    $item = $decision['item'];
                    $unavailable = $decision['kept'] < $decision['requested'];
                @endphp
                <div class="flex items-center justify-between gap-4 px-4 py-3" role="listitem">
                    <div class="min-w-0">
                        <p class="font-medium text-text-primary truncate">{{ $item->service->name }}</p>
                        <p class="text-sm text-text-muted">
                            {{ $item->start_date->format('d.m.Y') }} – {{ $item->end_date->format('d.m.Y') }}
                        </p>
                    </div>
                    <div class="shrink-0 text-right">
                        @if($decision['kept'] === 0)
                            <span class="text-sm font-medium text-error">
                                Niedostępne w tym oddziale — pozycja zostanie usunięta
                            </span>
                        @elseif($unavailable)
                            <span class="text-sm font-medium text-warning">
                                Ilość zmniejszona: {{ $decision['requested'] }} → {{ $decision['kept'] }}
                            </span>
                        @else
                            <span class="text-sm text-text-secondary">
                                {{ $decision['requested'] }} szt. — dostępne
                            </span>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>

        <div class="flex flex-wrap gap-3">
            <form method="POST" action="{{ route('location.select') }}">
                @csrf
                <input type="hidden" name="location_id" value="{{ $newLocation->id }}">
                <input type="hidden" name="redirect_to" value="{{ $redirectTo }}">
                <input type="hidden" name="confirmed" value="1">
                <x-ui.button type="submit">
                    Potwierdź zmianę oddziału
                </x-ui.button>
            </form>

            <x-ui.button variant="ghost" href="{{ $redirectTo }}">
                Anuluj
            </x-ui.button>
        </div>
    </x-layout.container>
</x-layout.section>

@endsection

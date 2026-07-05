@extends('layouts.app')

@php
    /**
     * Status badge config:
     * [bg, text, ring] — all semantic or safe-listed status utility classes
     */
    $statusConfig = [
        'pending_payment' => ['bg-warning/10',   'text-warning',  'ring-warning/20',  'Oczekuje na płatność'],
        'paid'            => ['bg-success/10',   'text-success',  'ring-success/20',  'Opłacone'],
        'confirmed'       => ['bg-success/10',   'text-success',  'ring-success/20',  'Potwierdzone'],
        'in_progress'     => ['bg-info/10',      'text-info',     'ring-info/20',     'Sprzęt u klienta'],
        'completed'       => ['bg-surface-sunken', 'text-text-muted', 'ring-border', 'Zakończone'],
        'cancelled'       => ['bg-error/10',     'text-error',    'ring-error/20',    'Anulowane'],
        'refunded'        => ['bg-brand-subtle', 'text-brand',    'ring-brand/20',    'Zwrócone'],
    ];

    $defaultStatus = ['bg-surface-sunken', 'text-text-muted', 'ring-border', 'Nieznany'];
@endphp

@section('content')

{{-- Page header --}}
<x-layout.section spacing="sm" class="bg-surface-sunken">
    <x-layout.container>
        <div class="flex items-center gap-3">
            <h1 class="text-3xl font-bold text-text-primary tracking-tight">
                Moje zamówienia
            </h1>
            @if($orders->total() > 0)
                <span
                    class="inline-flex items-center justify-center h-7 min-w-[1.75rem] px-2 rounded-full bg-brand text-text-inverse text-sm font-semibold tabular-nums"
                    aria-label="{{ $orders->total() }} {{ $orders->total() === 1 ? 'zamówienie' : ($orders->total() <= 4 ? 'zamówienia' : 'zamówień') }}"
                >
                    {{ $orders->total() }}
                </span>
            @endif
        </div>
    </x-layout.container>
</x-layout.section>

{{-- Main content --}}
<x-layout.section spacing="default">
    <x-layout.container>

        @if($orders->isEmpty())

            {{-- ── Empty state ── --}}
            <div class="max-w-md mx-auto text-center py-16">
                <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-surface-sunken mb-6"
                     aria-hidden="true">
                    <x-heroicon-o-clipboard-document-list class="h-8 w-8 text-text-muted" aria-hidden="true" />
                </div>
                <h2 class="text-xl font-semibold text-text-primary mb-2">
                    Brak zamówień
                </h2>
                <p class="text-text-secondary mb-8">
                    Nie masz jeszcze żadnych zamówień.
                </p>
                <x-ui.button href="{{ url('/uslugi') }}" icon="arrow-left">
                    Przeglądaj usługi
                </x-ui.button>
            </div>

        @else

            {{-- ── Desktop: table ── --}}
            <div class="hidden md:block">
                <div class="rounded-xl border border-border bg-surface-raised shadow-xs overflow-hidden">
                    <table class="w-full text-sm" aria-label="Lista zamówień">
                        <thead>
                            <tr class="border-b border-border bg-surface-sunken">
                                <th scope="col"
                                    class="px-5 py-3.5 text-left text-xs font-semibold text-text-muted uppercase tracking-wider">
                                    Numer zamówienia
                                </th>
                                <th scope="col"
                                    class="px-5 py-3.5 text-left text-xs font-semibold text-text-muted uppercase tracking-wider">
                                    Data
                                </th>
                                <th scope="col"
                                    class="px-5 py-3.5 text-left text-xs font-semibold text-text-muted uppercase tracking-wider">
                                    Status
                                </th>
                                <th scope="col"
                                    class="px-5 py-3.5 text-right text-xs font-semibold text-text-muted uppercase tracking-wider">
                                    Suma
                                </th>
                                <th scope="col" class="px-5 py-3.5">
                                    <span class="sr-only">Akcje</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            @foreach($orders as $order)
                                @php
                                    [$statusBg, $statusText, $statusRing, $statusLabel] =
                                        $statusConfig[$order->status] ?? $defaultStatus;
                                @endphp
                                <tr class="hover:bg-surface-sunken transition-colors duration-150">
                                    <td class="px-5 py-4 font-medium text-text-primary tabular-nums">
                                        #{{ $order->order_number }}
                                    </td>
                                    <td class="px-5 py-4 text-text-secondary">
                                        <time datetime="{{ $order->created_at->toIso8601String() }}">
                                            {{ $order->created_at->format('d.m.Y H:i') }}
                                        </time>
                                    </td>
                                    <td class="px-5 py-4">
                                        <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset
                                                     {{ $statusBg }} {{ $statusText }} {{ $statusRing }}">
                                            {{ $statusLabel }}
                                        </span>
                                    </td>
                                    <td class="px-5 py-4 text-right font-semibold text-text-primary tabular-nums">
                                        {{ number_format($order->total_amount, 2, ',', ' ') }}&nbsp;zł
                                    </td>
                                    <td class="px-5 py-4 text-right">
                                        <a
                                            href="{{ route('orders.show', $order) }}"
                                            class="inline-flex items-center gap-1.5 text-sm font-medium text-brand
                                                   hover:text-brand-hover
                                                   focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50 focus-visible:rounded
                                                   transition-colors duration-200"
                                            aria-label="Szczegóły zamówienia #{{ $order->order_number }}"
                                        >
                                            Szczegóły
                                            <x-heroicon-m-arrow-right class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                        </a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- ── Mobile: cards ── --}}
            <div class="md:hidden space-y-3" role="list" aria-label="Lista zamówień">
                @foreach($orders as $order)
                    @php
                        [$statusBg, $statusText, $statusRing, $statusLabel] =
                            $statusConfig[$order->status] ?? $defaultStatus;
                    @endphp
                    <article
                        class="rounded-xl border border-border bg-surface-raised shadow-xs overflow-hidden"
                        role="listitem"
                        aria-label="Zamówienie #{{ $order->order_number }}"
                    >
                        <div class="p-4">
                            <div class="flex items-start justify-between gap-3 mb-3">
                                <div>
                                    <p class="text-sm font-semibold text-text-primary tabular-nums">
                                        #{{ $order->order_number }}
                                    </p>
                                    <time
                                        datetime="{{ $order->created_at->toIso8601String() }}"
                                        class="text-xs text-text-muted"
                                    >
                                        {{ $order->created_at->format('d.m.Y H:i') }}
                                    </time>
                                </div>
                                <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset
                                             {{ $statusBg }} {{ $statusText }} {{ $statusRing }}">
                                    {{ $statusLabel }}
                                </span>
                            </div>

                            <div class="flex items-center justify-between gap-3">
                                <span class="text-lg font-bold text-text-primary tabular-nums">
                                    {{ number_format($order->total_amount, 2, ',', ' ') }}&nbsp;zł
                                </span>
                                <a
                                    href="{{ route('orders.show', $order) }}"
                                    class="inline-flex items-center gap-1.5 min-h-11 px-4 text-sm font-medium rounded-lg
                                           border border-border bg-surface-raised text-text-secondary
                                           hover:bg-surface-sunken hover:border-border-strong hover:text-text-primary
                                           focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50 focus-visible:ring-offset-2
                                           transition-all duration-200"
                                    aria-label="Szczegóły zamówienia #{{ $order->order_number }}"
                                >
                                    Szczegóły
                                    <x-heroicon-m-arrow-right class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                </a>
                            </div>
                        </div>
                    </article>
                @endforeach
            </div>

            {{-- ── Pagination ── --}}
            @if($orders->hasPages())
                <div class="mt-8" aria-label="Nawigacja po stronach">
                    {{ $orders->links() }}
                </div>
            @endif

        @endif

    </x-layout.container>
</x-layout.section>

@endsection

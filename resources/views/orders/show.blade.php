@extends('layouts.app')

@php
    $statusConfig = [
        'pending_payment' => ['bg-warning/10',    'text-warning',   'ring-warning/20',   'Oczekuje na płatność'],
        'paid'            => ['bg-success/10',    'text-success',   'ring-success/20',   'Opłacone'],
        'confirmed'       => ['bg-success/10',    'text-success',   'ring-success/20',   'Potwierdzone'],
        'in_progress'     => ['bg-info/10',       'text-info',      'ring-info/20',      'W realizacji'],
        'completed'       => ['bg-surface-sunken', 'text-text-muted', 'ring-border',     'Zakończone'],
        'cancelled'       => ['bg-error/10',      'text-error',     'ring-error/20',     'Anulowane'],
        'refunded'        => ['bg-brand-subtle',  'text-brand',     'ring-brand/20',     'Zwrócone'],
    ];

    [$statusBg, $statusText, $statusRing, $statusLabel] =
        $statusConfig[$order->status] ?? ['bg-surface-sunken', 'text-text-muted', 'ring-border', 'Nieznany'];
@endphp

@section('content')

{{-- Page header --}}
<x-layout.section spacing="sm" class="bg-surface-sunken">
    <x-layout.container>
        <div class="flex items-center gap-3">
            <a
                href="{{ route('orders.index') }}"
                class="inline-flex items-center justify-center h-8 w-8 rounded-lg text-text-muted
                       hover:text-text-primary hover:bg-surface-raised border border-transparent hover:border-border
                       transition-all duration-200 ease-out
                       focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50 focus-visible:ring-offset-2"
                aria-label="Wróć do moich zamówień"
            >
                <x-heroicon-m-arrow-left class="h-4 w-4" aria-hidden="true" />
            </a>
            <div class="flex flex-wrap items-center gap-3 min-w-0">
                <h1 class="text-3xl font-bold text-text-primary tracking-tight">
                    Zamówienie <span class="tabular-nums">#{{ $order->order_number }}</span>
                </h1>
                <span
                    class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset
                           {{ $statusBg }} {{ $statusText }} {{ $statusRing }}"
                    role="status"
                    aria-label="Status: {{ $statusLabel }}"
                >
                    {{ $statusLabel }}
                </span>
            </div>
        </div>
        <p class="mt-1.5 ml-11 text-sm text-text-muted">
            <time datetime="{{ $order->created_at->toIso8601String() }}">
                {{ $order->created_at->format('d.m.Y H:i') }}
            </time>
            @if($order->paid_at)
                &middot;
                Opłacono
                <time datetime="{{ $order->paid_at->toIso8601String() }}">
                    {{ $order->paid_at->format('d.m.Y H:i') }}
                </time>
            @endif
            @if($order->cancelled_at)
                &middot;
                Anulowano
                <time datetime="{{ $order->cancelled_at->toIso8601String() }}">
                    {{ $order->cancelled_at->format('d.m.Y H:i') }}
                </time>
            @endif
        </p>
    </x-layout.container>
</x-layout.section>

{{-- Main content --}}
<x-layout.section spacing="default">
    <x-layout.container>
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8 items-start">

            {{-- ── Left column: items + contact + invoice ── --}}
            <div class="lg:col-span-2 space-y-6">

                {{-- ─── Order items ─── --}}
                <section aria-labelledby="items-heading">
                    <x-ui.card>
                        <h2 id="items-heading" class="text-base font-semibold text-text-primary mb-5">
                            Pozycje zamówienia
                        </h2>

                        {{-- Desktop table --}}
                        <div class="hidden sm:block overflow-x-auto -mx-1">
                            <table class="w-full text-sm" aria-label="Pozycje zamówienia">
                                <thead>
                                    <tr class="border-b border-border">
                                        <th scope="col"
                                            class="pb-3 text-left text-xs font-semibold text-text-muted uppercase tracking-wider px-1">
                                            Usługa
                                        </th>
                                        <th scope="col"
                                            class="pb-3 text-left text-xs font-semibold text-text-muted uppercase tracking-wider px-1">
                                            Okres
                                        </th>
                                        <th scope="col"
                                            class="pb-3 text-right text-xs font-semibold text-text-muted uppercase tracking-wider px-1">
                                            Ilość
                                        </th>
                                        <th scope="col"
                                            class="pb-3 text-right text-xs font-semibold text-text-muted uppercase tracking-wider px-1">
                                            Cena jedn.
                                        </th>
                                        <th scope="col"
                                            class="pb-3 text-right text-xs font-semibold text-text-muted uppercase tracking-wider px-1">
                                            Łącznie
                                        </th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-border">
                                    @foreach($order->items as $item)
                                        <tr>
                                            <td class="py-3.5 px-1 font-medium text-text-primary">
                                                {{ $item->service_name }}
                                            </td>
                                            <td class="py-3.5 px-1 text-text-secondary">
                                                @if($item->start_date && $item->end_date)
                                                    <dl class="space-y-0.5">
                                                        <div class="flex items-center gap-1.5">
                                                            <x-heroicon-m-calendar-days
                                                                class="h-3.5 w-3.5 text-text-muted shrink-0"
                                                                aria-hidden="true" />
                                                            <dt class="sr-only">Okres wynajmu</dt>
                                                            <dd class="text-xs tabular-nums">
                                                                <time datetime="{{ \Carbon\Carbon::parse($item->start_date)->toDateString() }}">
                                                                    {{ \Carbon\Carbon::parse($item->start_date)->format('d.m.Y') }}
                                                                </time>
                                                                <span aria-hidden="true"> &ndash; </span>
                                                                <time datetime="{{ \Carbon\Carbon::parse($item->end_date)->toDateString() }}">
                                                                    {{ \Carbon\Carbon::parse($item->end_date)->format('d.m.Y') }}
                                                                </time>
                                                            </dd>
                                                        </div>
                                                        @if($item->rental_days)
                                                            <div class="flex items-center gap-1.5">
                                                                <x-heroicon-m-clock
                                                                    class="h-3.5 w-3.5 text-text-muted shrink-0"
                                                                    aria-hidden="true" />
                                                                <dt class="sr-only">Liczba dni</dt>
                                                                <dd class="text-xs text-text-muted">
                                                                    {{ $item->rental_days }}&nbsp;{{ $item->rental_days === 1 ? 'dzień' : 'dni' }}
                                                                </dd>
                                                            </div>
                                                        @endif
                                                    </dl>
                                                @else
                                                    <span class="text-text-muted text-xs">&mdash;</span>
                                                @endif
                                            </td>
                                            <td class="py-3.5 px-1 text-right text-text-secondary tabular-nums">
                                                {{ $item->quantity }}
                                            </td>
                                            <td class="py-3.5 px-1 text-right text-text-secondary tabular-nums">
                                                {{ number_format($item->unit_price, 2, ',', ' ') }}&nbsp;zł
                                            </td>
                                            <td class="py-3.5 px-1 text-right font-semibold text-text-primary tabular-nums">
                                                {{ number_format($item->total_price, 2, ',', ' ') }}&nbsp;zł
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        {{-- Mobile: item cards --}}
                        <div class="sm:hidden space-y-3" role="list" aria-label="Pozycje zamówienia">
                            @foreach($order->items as $item)
                                <article class="rounded-lg border border-border p-3.5" role="listitem">
                                    <div class="flex items-start justify-between gap-3 mb-2">
                                        <p class="text-sm font-semibold text-text-primary">
                                            {{ $item->service_name }}
                                        </p>
                                        <span class="shrink-0 text-sm font-bold text-text-primary tabular-nums">
                                            {{ number_format($item->total_price, 2, ',', ' ') }}&nbsp;zł
                                        </span>
                                    </div>

                                    <dl class="flex flex-wrap gap-x-4 gap-y-1 text-xs text-text-secondary">
                                        @if($item->start_date && $item->end_date)
                                            <div class="flex items-center gap-1">
                                                <x-heroicon-m-calendar-days class="h-3.5 w-3.5 text-text-muted shrink-0" aria-hidden="true" />
                                                <dt class="sr-only">Okres</dt>
                                                <dd class="tabular-nums">
                                                    <time datetime="{{ \Carbon\Carbon::parse($item->start_date)->toDateString() }}">
                                                        {{ \Carbon\Carbon::parse($item->start_date)->format('d.m.Y') }}
                                                    </time>
                                                    <span aria-hidden="true"> &ndash; </span>
                                                    <time datetime="{{ \Carbon\Carbon::parse($item->end_date)->toDateString() }}">
                                                        {{ \Carbon\Carbon::parse($item->end_date)->format('d.m.Y') }}
                                                    </time>
                                                </dd>
                                            </div>
                                        @endif
                                        <div class="flex items-center gap-1">
                                            <dt class="text-text-muted">Ilość:</dt>
                                            <dd class="tabular-nums">{{ $item->quantity }} szt.</dd>
                                        </div>
                                        <div class="flex items-center gap-1">
                                            <dt class="text-text-muted">Cena:</dt>
                                            <dd class="tabular-nums">{{ number_format($item->unit_price, 2, ',', ' ') }}&nbsp;zł/szt.</dd>
                                        </div>
                                    </dl>
                                </article>
                            @endforeach
                        </div>
                    </x-ui.card>
                </section>

                {{-- ─── Contact details ─── --}}
                <section aria-labelledby="contact-heading">
                    <x-ui.card>
                        <h2 id="contact-heading" class="text-base font-semibold text-text-primary mb-5">
                            Dane kontaktowe
                        </h2>

                        <dl class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
                            <div>
                                <dt class="text-xs font-medium text-text-muted uppercase tracking-wider mb-1">
                                    Imię i nazwisko
                                </dt>
                                <dd class="text-text-primary font-medium">
                                    {{ $order->customer_first_name }} {{ $order->customer_last_name }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-xs font-medium text-text-muted uppercase tracking-wider mb-1">
                                    Adres e-mail
                                </dt>
                                <dd>
                                    <a
                                        href="mailto:{{ $order->customer_email }}"
                                        class="text-brand hover:text-brand-hover
                                               focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50 focus-visible:rounded
                                               transition-colors duration-200"
                                    >
                                        {{ $order->customer_email }}
                                    </a>
                                </dd>
                            </div>
                            @if($order->customer_phone)
                                <div>
                                    <dt class="text-xs font-medium text-text-muted uppercase tracking-wider mb-1">
                                        Telefon
                                    </dt>
                                    <dd>
                                        <a
                                            href="tel:{{ $order->customer_phone }}"
                                            class="text-text-primary hover:text-brand
                                                   focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50 focus-visible:rounded
                                                   transition-colors duration-200"
                                        >
                                            {{ $order->customer_phone }}
                                        </a>
                                    </dd>
                                </div>
                            @endif
                        </dl>
                    </x-ui.card>
                </section>

                {{-- ─── Invoice details (conditional) ─── --}}
                @if($order->invoice_requested)
                    <section aria-labelledby="invoice-heading">
                        <x-ui.card>
                            <h2 id="invoice-heading" class="text-base font-semibold text-text-primary mb-5">
                                Dane do faktury
                            </h2>

                            <dl class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
                                @if($order->invoice_company_name)
                                    <div class="sm:col-span-2">
                                        <dt class="text-xs font-medium text-text-muted uppercase tracking-wider mb-1">
                                            Nazwa firmy
                                        </dt>
                                        <dd class="text-text-primary font-medium">
                                            {{ $order->invoice_company_name }}
                                        </dd>
                                    </div>
                                @endif
                                @if($order->invoice_nip)
                                    <div>
                                        <dt class="text-xs font-medium text-text-muted uppercase tracking-wider mb-1">
                                            NIP
                                        </dt>
                                        <dd class="text-text-primary tabular-nums">
                                            {{ $order->invoice_nip }}
                                        </dd>
                                    </div>
                                @endif
                                @if(($order->company_regon ?? null))
                                    <div>
                                        <dt class="text-xs font-medium text-text-muted uppercase tracking-wider mb-1">
                                            REGON
                                        </dt>
                                        <dd class="text-text-primary tabular-nums">
                                            {{ $order->company_regon }}
                                        </dd>
                                    </div>
                                @endif
                                @if(($order->company_krs ?? null))
                                    <div>
                                        <dt class="text-xs font-medium text-text-muted uppercase tracking-wider mb-1">
                                            KRS
                                        </dt>
                                        <dd class="text-text-primary tabular-nums">
                                            {{ $order->company_krs }}
                                        </dd>
                                    </div>
                                @endif
                                @if($order->invoice_street && $order->invoice_street_number)
                                    <div class="sm:col-span-2">
                                        <dt class="text-xs font-medium text-text-muted uppercase tracking-wider mb-1">
                                            Ulica
                                        </dt>
                                        <dd class="text-text-primary">
                                            {{ $order->invoice_street }} {{ $order->invoice_street_number }}
                                        </dd>
                                    </div>
                                @endif
                                @if($order->invoice_postal_code && $order->invoice_city)
                                    <div class="sm:col-span-2">
                                        <dt class="text-xs font-medium text-text-muted uppercase tracking-wider mb-1">
                                            Miejscowość
                                        </dt>
                                        <dd class="text-text-primary">
                                            {{ $order->invoice_postal_code }} {{ $order->invoice_city }}
                                        </dd>
                                    </div>
                                @endif
                            </dl>
                        </x-ui.card>
                    </section>
                @endif

            </div>

            {{-- ── Right column: order summary ── --}}
            <aside aria-label="Podsumowanie zamówienia">
                <x-ui.card class="sticky top-6">
                    <h2 class="text-base font-semibold text-text-primary mb-4">
                        Podsumowanie
                    </h2>

                    <dl class="space-y-2 text-sm">
                        @foreach($order->items as $item)
                            <div class="flex justify-between gap-3">
                                <dt class="text-text-secondary truncate min-w-0 flex-1">
                                    {{ $item->service_name }}
                                    @if($item->quantity > 1)
                                        <span class="text-text-muted"> &times;&thinsp;{{ $item->quantity }}</span>
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
                                {{ number_format($order->total_amount, 2, ',', ' ') }}&nbsp;zł
                            </span>
                        </div>
                        <p class="mt-1 text-xs text-text-muted">Ceny brutto</p>
                    </div>

                    {{-- Status info line --}}
                    <div class="mt-5 pt-4 border-t border-border">
                        <div class="flex items-center justify-between gap-2 text-sm">
                            <span class="text-text-secondary">Status</span>
                            <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset
                                         {{ $statusBg }} {{ $statusText }} {{ $statusRing }}">
                                {{ $statusLabel }}
                            </span>
                        </div>
                        @if($order->paid_at)
                            <div class="flex items-center justify-between gap-2 text-sm mt-2.5">
                                <span class="text-text-secondary">Opłacono</span>
                                <time
                                    datetime="{{ $order->paid_at->toIso8601String() }}"
                                    class="text-text-muted tabular-nums text-xs"
                                >
                                    {{ $order->paid_at->format('d.m.Y H:i') }}
                                </time>
                            </div>
                        @endif
                    </div>

                    {{-- Deposit (kaucja) — shown only when deposit_amount > 0 --}}
                    @php
                        $depositAmount = $order->deposit_amount ?? null;
                        $depositStatus = $order->deposit_status ?? null;
                        $depositBadgeConfig = [
                            'pending'        => ['bg-warning/10',      'text-warning',    'ring-warning/20',  'Oczekuje'],
                            'collected'      => ['bg-info/10',         'text-info',       'ring-info/20',     'Pobrana'],
                            'returned'       => ['bg-success/10',      'text-success',    'ring-success/20',  'Zwrócona'],
                            'partial_return' => ['bg-info/10',         'text-info',       'ring-info/20',     'Częściowy zwrot'],
                            'forfeited'      => ['bg-error/10',        'text-error',      'ring-error/20',    'Przepadła'],
                        ];
                        [$depositBg, $depositText, $depositRing, $depositLabel] =
                            $depositBadgeConfig[$depositStatus ?? ''] ?? ['bg-surface-sunken', 'text-text-muted', 'ring-border', $depositStatus];
                    @endphp
                    @if($depositAmount > 0)
                        <div class="border-t border-border mt-5 pt-4">
                            <h3 class="text-sm font-semibold text-text-primary mb-3">
                                Kaucja
                            </h3>
                            <div class="flex items-center justify-between gap-2">
                                <span class="text-sm font-medium text-text-secondary tabular-nums">
                                    {{ number_format($depositAmount, 2, ',', ' ') }}&nbsp;zł
                                </span>
                                @if($depositStatus && $depositStatus !== 'not_required')
                                    <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset
                                                 {{ $depositBg }} {{ $depositText }} {{ $depositRing }}">
                                        {{ $depositLabel }}
                                    </span>
                                @endif
                            </div>
                            <p class="mt-2 text-xs text-text-muted leading-relaxed">
                                Kaucja pobierana przy odbiorze sprzętu. Zwracana po oddaniu w stanie nienaruszonym.
                            </p>
                        </div>
                    @endif

                    {{-- Cancel action — only for pending_payment orders --}}
                    @if($order->status === 'pending_payment')
                        <div class="border-t border-border mt-5 pt-4">
                            <form
                                method="POST"
                                action="{{ route('orders.cancel', $order) }}"
                                onsubmit="return confirm('Czy na pewno chcesz anulować to zamówienie?')"
                            >
                                @csrf
                                <button
                                    type="submit"
                                    class="w-full inline-flex items-center justify-center gap-2 rounded-lg px-4 py-2.5 text-sm font-medium
                                           text-error ring-1 ring-error/30 bg-error/5
                                           hover:bg-error/10 hover:ring-error/50
                                           focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-error/50 focus-visible:ring-offset-2
                                           transition-all duration-200 ease-out"
                                >
                                    <x-heroicon-m-x-circle class="h-4 w-4 shrink-0" aria-hidden="true" />
                                    Anuluj zamówienie
                                </button>
                            </form>
                        </div>
                    @endif
                </x-ui.card>
            </aside>

        </div>

        {{-- ── Back link ── --}}
        <div class="mt-8">
            <a
                href="{{ route('orders.index') }}"
                class="inline-flex items-center gap-2 text-sm text-text-secondary
                       hover:text-text-primary
                       focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50 focus-visible:rounded
                       transition-colors duration-200"
            >
                <x-heroicon-m-arrow-left class="h-4 w-4 shrink-0" aria-hidden="true" />
                Wróć do moich zamówień
            </a>
        </div>

    </x-layout.container>
</x-layout.section>

@endsection

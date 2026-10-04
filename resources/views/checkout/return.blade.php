@extends('layouts.app')

@php
    $isSuccess  = $order && in_array($order->status, ['paid', 'confirmed']);
    $isOfflineAccepted = $order && $order->status === 'pending_payment' && $order->settlement_method === 'offline';
    $isPending  = $order && $order->status === 'pending_payment' && ! $isOfflineAccepted;
    $isCancelled = $order && $order->status === 'cancelled';
    $isNull     = $order === null;
    $isOther    = $order && ! $isSuccess && ! $isOfflineAccepted && ! $isPending && ! $isCancelled;
@endphp

@if($isPending)
    @push('head')
        <meta http-equiv="refresh" content="5">
    @endpush
@endif

@section('content')

{{-- Page header --}}
<x-layout.section spacing="sm" class="bg-surface-sunken">
    <x-layout.container>
        <h1 class="text-3xl font-bold text-text-primary tracking-tight">
            {{ __('checkout.return.title') }}
        </h1>
    </x-layout.container>
</x-layout.section>

{{-- Status content --}}
<x-layout.section spacing="default">
    <x-layout.container>
        <div class="max-w-lg mx-auto">

            @if($isNull)

                {{-- ── Error: order not found ── --}}
                <div
                    role="alert"
                    aria-live="assertive"
                    class="text-center py-12"
                >
                    <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-error/10 mb-6"
                         aria-hidden="true">
                        <x-heroicon-o-exclamation-circle class="h-8 w-8 text-error" aria-hidden="true" />
                    </div>
                    <h2 class="text-xl font-semibold text-text-primary mb-2">
                        {{ __('checkout.return.not_found_title') }}
                    </h2>
                    <p class="text-text-secondary mb-8">
                        {{ __('checkout.return.not_found_text') }}
                    </p>
                    <x-ui.button href="{{ route('cart.show') }}" icon="shopping-cart">
                        {{ __('checkout.back_to_cart') }}
                    </x-ui.button>
                </div>

            @elseif($isSuccess)

                {{-- ── Success ── --}}
                <div
                    role="status"
                    aria-live="polite"
                    class="text-center py-12"
                >
                    <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-success/10 mb-6"
                         aria-hidden="true">
                        <x-heroicon-o-check-circle class="h-8 w-8 text-success" aria-hidden="true" />
                    </div>
                    <h2 class="text-xl font-semibold text-text-primary mb-2">
                        {{ __('checkout.return.thanks') }}
                    </h2>
                    <p class="text-text-secondary mb-2">
                        {!! __('checkout.return.paid', ['number' => '<span class="font-semibold text-text-primary">#'.e($order->order_number).'</span>']) !!}
                    </p>
                    <p class="text-sm text-text-muted mb-8">
                        {{ __('checkout.return.email_sent') }}
                    </p>
                    <x-ui.button href="{{ route('orders.show', $order) }}" icon-right="arrow-right">
                        {{ __('checkout.return.order_details') }}
                    </x-ui.button>
                </div>

            @elseif($isOfflineAccepted)

                {{-- ── Offline (pay at pickup) accepted ── --}}
                <div
                    role="status"
                    aria-live="polite"
                    class="text-center py-12"
                >
                    <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-success/10 mb-6"
                         aria-hidden="true">
                        <x-heroicon-o-check-circle class="h-8 w-8 text-success" aria-hidden="true" />
                    </div>
                    <h2 class="text-xl font-semibold text-text-primary mb-2">
                        {{ __('checkout.return.accepted_title') }}
                    </h2>
                    <p class="text-text-secondary mb-2">
                        {!! __('checkout.return.reserved', ['number' => '<span class="font-semibold text-text-primary">#'.e($order->order_number).'</span>']) !!}
                    </p>
                    @if($order->expires_at)
                        <p class="text-sm text-text-muted mb-2">
                            {!! __('checkout.return.reservation_valid', ['date' => '<time datetime="'.e($order->expires_at->toIso8601String()).'" class="font-medium tabular-nums">'.e($order->expires_at->format('d.m.Y H:i')).'</time>']) !!}
                        </p>
                    @endif
                    <p class="text-sm text-text-muted mb-8">
                        {{ __('checkout.return.email_sent') }}
                    </p>
                    <x-ui.button href="{{ route('orders.show', $order) }}" icon-right="arrow-right">
                        {{ __('checkout.return.order_details') }}
                    </x-ui.button>
                </div>

            @elseif($isPending)

                {{-- ── Pending ── --}}
                <div
                    role="status"
                    aria-live="polite"
                    aria-label="{{ __('checkout.return.processing_label') }}"
                    class="text-center py-12"
                >
                    <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-warning/10 mb-6"
                         aria-hidden="true">
                        <svg class="h-8 w-8 text-warning animate-spin motion-reduce:animate-none"
                             viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <circle class="opacity-25" cx="12" cy="12" r="10"
                                    stroke="currentColor" stroke-width="4" />
                            <path class="opacity-75" fill="currentColor"
                                  d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
                        </svg>
                    </div>
                    <h2 class="text-xl font-semibold text-text-primary mb-2">
                        {{ __('checkout.return.processing_title') }}
                    </h2>
                    <p class="text-text-secondary mb-2">
                        {{ __('checkout.return.processing_text') }}
                    </p>
                    <p class="text-sm text-text-muted mb-8">
                        {{ __('checkout.return.refresh_notice') }}
                    </p>
                    <x-ui.button href="{{ route('orders.index') }}" variant="secondary">
                        {{ __('checkout.return.my_orders') }}
                    </x-ui.button>
                </div>

            @elseif($isCancelled)

                {{-- ── Cancelled ── --}}
                <div
                    role="alert"
                    aria-live="assertive"
                    class="text-center py-12"
                >
                    <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-error/10 mb-6"
                         aria-hidden="true">
                        <x-heroicon-o-x-circle class="h-8 w-8 text-error" aria-hidden="true" />
                    </div>
                    <h2 class="text-xl font-semibold text-text-primary mb-2">
                        {{ __('checkout.return.cancelled_title') }}
                    </h2>
                    <p class="text-text-secondary mb-8">
                        {{ __('checkout.return.cancelled_text') }}
                    </p>
                    <x-ui.button href="{{ route('cart.show') }}" icon="shopping-cart">
                        {{ __('checkout.back_to_cart') }}
                    </x-ui.button>
                </div>

            @else

                {{-- ── Other / unknown status ── --}}
                <div
                    role="status"
                    aria-live="polite"
                    class="text-center py-12"
                >
                    <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-surface-sunken mb-6"
                         aria-hidden="true">
                        <x-heroicon-o-information-circle class="h-8 w-8 text-text-muted" aria-hidden="true" />
                    </div>
                    <h2 class="text-xl font-semibold text-text-primary mb-2">
                        {{ __('checkout.return.other_title') }}
                    </h2>
                    <p class="text-text-secondary mb-8">
                        {{ __('checkout.return.other_text') }}
                    </p>
                    <x-ui.button href="{{ route('orders.index') }}" icon-right="arrow-right">
                        {{ __('checkout.return.my_orders') }}
                    </x-ui.button>
                </div>

            @endif

        </div>
    </x-layout.container>
</x-layout.section>

@endsection

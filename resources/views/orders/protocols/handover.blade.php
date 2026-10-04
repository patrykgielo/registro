<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <title>{{ __('orders.protocol.handover.title', ['number' => $order->order_number]) }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1a1a1a; margin: 0; padding: 24px; }
        h1 { font-size: 18px; color: #1e3a5f; margin: 0 0 4px; }
        h2 { font-size: 13px; color: #374151; margin-top: 20px; margin-bottom: 6px; border-bottom: 1px solid #d1d5db; padding-bottom: 4px; }
        .meta { font-size: 10px; color: #6b7280; margin-bottom: 16px; }
        .parties { width: 100%; border-collapse: collapse; margin-bottom: 4px; }
        .parties td { vertical-align: top; width: 50%; padding: 8px; border: 1px solid #d1d5db; }
        .parties .label { font-size: 9px; text-transform: uppercase; color: #6b7280; margin-bottom: 4px; }
        table.items { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
        table.items th { background-color: #1e3a5f; color: #ffffff; text-align: left; padding: 6px 8px; font-size: 10px; }
        table.items td { padding: 5px 8px; border-bottom: 1px solid #e5e7eb; }
        .text-right { text-align: right; }
        .statement { margin-top: 10px; line-height: 1.5; }
        .signatures { width: 100%; border-collapse: collapse; margin-top: 50px; }
        .signatures td { width: 50%; padding-top: 6px; text-align: center; font-size: 10px; color: #6b7280; }
        .signatures .line { border-top: 1px solid #1a1a1a; margin: 0 20px 6px; }
        .footer { margin-top: 30px; font-size: 9px; color: #9ca3af; text-align: center; }
    </style>
</head>
<body>

<h1>{{ __('orders.protocol.handover.heading') }}</h1>
<div class="meta">
    {!! __('orders.protocol.order_number', ['number' => '<strong>'.e($order->order_number).'</strong>']) !!}
    &nbsp;|&nbsp;
    {{ __('orders.protocol.generated_at', ['date' => $generatedAt]) }}
</div>

<table class="parties">
    <tr>
        <td>
            <div class="label">{{ __('orders.protocol.lessor') }}</div>
            <strong>{{ $org?->name ?? '—' }}</strong><br>
            @if($pickup['address'])
                {{ $pickup['address'] }}<br>
            @endif
            @if($pickup['phone'])
                {{ __('orders.protocol.phone_short', ['phone' => $pickup['phone']]) }}<br>
            @endif
            @if($pickup['email'])
                {{ __('orders.protocol.email_short', ['email' => $pickup['email']]) }}
            @endif
        </td>
        <td>
            <div class="label">{{ __('orders.protocol.renter') }}</div>
            <strong>{{ trim($order->customer_first_name.' '.$order->customer_last_name) }}</strong><br>
            @if($order->customer_type === 'business' && $order->invoice_company_name)
                {{ $order->invoice_company_name }}
                @if($order->invoice_nip) {{ __('orders.protocol.nip', ['nip' => $order->invoice_nip]) }} @endif
                <br>
            @endif
            @if($order->customer_street)
                {{ $order->customer_street }} {{ $order->customer_building }}{{ $order->customer_apartment ? '/'.$order->customer_apartment : '' }}<br>
                {{ trim(($order->customer_postal_code ?? '').' '.($order->customer_city ?? '')) }}<br>
            @endif
            {{ __('orders.protocol.phone_short', ['phone' => $order->customer_phone ?? '—']) }}<br>
            {{ __('orders.protocol.email_short', ['email' => $order->customer_email]) }}
        </td>
    </tr>
</table>

@if($branch ?? null)
{{-- Faza 6 krok 6.5 — pickup-branch block, distinct from the "Wynajmujący"
company-identity block above which stays unchanged. Rendered only when the
order carries a checkout-time pickup-location snapshot (multi-location
tenant that had locations at the time of this order); absent entirely for
single-/zero-location tenants and orders placed before this feature. --}}
<table class="parties" style="margin-top: 4px;">
    <tr>
        <td style="width:100%;">
            <div class="label">{{ __('orders.protocol.handover.pickup_point') }}</div>
            <strong>{{ $branch['name'] }}</strong>
            @if($branch['address'])
                <br>{{ $branch['address'] }}
            @endif
        </td>
    </tr>
</table>
@endif

<h2>{{ __('orders.protocol.handover.items_heading') }}</h2>
<table class="items">
    <thead>
        <tr>
            <th>{{ __('orders.protocol.col_name') }}</th>
            <th>{{ __('orders.protocol.col_period') }}</th>
            <th class="text-right">{{ __('orders.protocol.col_quantity') }}</th>
            <th class="text-right">{{ __('orders.protocol.col_value') }}</th>
        </tr>
    </thead>
    <tbody>
        @foreach($order->items as $item)
        <tr>
            <td>
                {{ $item->service_name }}
                @if($item->quantity === 1 && $item->service_unit_identifier_snapshot)
                    {{-- quantity === 1 guard: a snapshot recorded against a
                    pre-rozwinięcie-ilości line (quantity > 1, Faza 3 krok
                    3.8, ClickUp 123k99cu2b5) names exactly ONE physical
                    unit, not all of them — printing it unqualified on a
                    multi-piece line would misrepresent which items the
                    signature actually covers. Suppressed entirely rather
                    than a "1 z N szt." label: with no way to say WHICH one,
                    a partial-scope disclaimer would only look reassuring
                    without actually identifying anything. Falls back to
                    today's baseline (equipment identified by name only)
                    for that line. --}}
                    <br><span style="font-size: 9px; color: #6b7280;">{{ __('orders.protocol.unit_no', ['number' => $item->service_unit_identifier_snapshot]) }}</span>
                @endif
            </td>
            <td>
                @if($item->start_date && $item->end_date)
                    {{ $item->start_date->format('d.m.Y') }} – {{ $item->end_date->format('d.m.Y') }}
                @else
                    —
                @endif
            </td>
            <td class="text-right">{{ $item->quantity }}</td>
            <td class="text-right">{{ number_format((float) $item->total_price, 2, ',', ' ') }} {{ __('common.currency') }}</td>
        </tr>
        @endforeach
    </tbody>
</table>

@if(($order->deposit_amount ?? 0) > 0)
    @php
        // Reprinting a handover protocol after the fact must describe the
        // deposit's actual, current state — not assume nothing has happened
        // since handover. All 5 non-"not_required" statuses handled
        // explicitly (deposit_amount > 0 already rules out not_required).
        $depositStatusLine = match ($order->deposit_status) {
            'pending' => __('orders.protocol.handover.deposit.pending'),
            'collected' => __('orders.protocol.handover.deposit.collected'),
            'returned' => __('orders.protocol.handover.deposit.returned'),
            'partial_return' => __('orders.protocol.handover.deposit.partial_return'),
            'forfeited' => __('orders.protocol.handover.deposit.forfeited'),
            default => 'status: '.$order->deposit_status.'.',
        };
    @endphp
<h2>{{ __('orders.protocol.deposit_heading') }}</h2>
<p>
    {!! __('orders.protocol.deposit_amount', ['amount' => '<strong>'.e(number_format((float) $order->deposit_amount, 2, ',', ' ').' '.__('common.currency')).'</strong>']) !!}
    — {{ $depositStatusLine }}
</p>
<p style="font-size: 9px; color: #6b7280;">
    {{ __('orders.protocol.deposit_note') }}
</p>
@endif

<div class="statement">
    {{ __('orders.protocol.handover.statement') }}
</div>

<table class="signatures">
    <tr>
        <td>
            <div class="line"></div>
            {{ __('orders.protocol.sign_lessor') }}
        </td>
        <td>
            <div class="line"></div>
            {{ __('orders.protocol.sign_renter') }}
        </td>
    </tr>
</table>

<div class="footer">
    {{ __('orders.protocol.footer', ['number' => $order->order_number]) }}
</div>

</body>
</html>

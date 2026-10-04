<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <title>{{ __('orders.protocol.return.title', ['number' => $order->order_number]) }}</title>
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
        .notes-box { margin-top: 10px; border: 1px solid #d1d5db; padding: 8px; min-height: 50px; }
        .notes-box .label { font-size: 9px; text-transform: uppercase; color: #6b7280; margin-bottom: 4px; }
        .signatures { width: 100%; border-collapse: collapse; margin-top: 50px; }
        .signatures td { width: 50%; padding-top: 6px; text-align: center; font-size: 10px; color: #6b7280; }
        .signatures .line { border-top: 1px solid #1a1a1a; margin: 0 20px 6px; }
        .footer { margin-top: 30px; font-size: 9px; color: #9ca3af; text-align: center; }
    </style>
</head>
<body>

<h1>{{ __('orders.protocol.return.heading') }}</h1>
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
{{-- Faza 6 krok 6.5 — same branch as the handover protocol: equipment
always returns to the branch that issued it (product decision,
plan-wdrozenia.md), so there is only ever one branch snapshot per order. --}}
<table class="parties" style="margin-top: 4px;">
    <tr>
        <td style="width:100%;">
            <div class="label">{{ __('orders.protocol.return.pickup_point') }}</div>
            <strong>{{ $branch['name'] }}</strong>
            @if($branch['address'])
                <br>{{ $branch['address'] }}
            @endif
        </td>
    </tr>
</table>
@endif

<h2>{{ __('orders.protocol.return.items_heading') }}</h2>
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
        @php
            // $unitMismatches keyed by order_item_id — see
            // OrderProtocolPdfService::unitMismatchesByItemId()'s own
            // docblock. `?? []` guards direct View::make() calls in tests
            // that render this Blade file without going through the
            // service at all (see OrderProtocolPdfServiceTest).
            $mismatch = ($unitMismatches ?? [])[$item->id] ?? null;
        @endphp
        <tr>
            <td>
                {{ $item->service_name }}
                @if($item->quantity === 1)
                    {{-- Same quantity===1 guard as handover.blade.php: a
                    unit number (matched or mismatched) recorded against a
                    pre-rozwinięcie-ilości line names exactly ONE physical
                    unit, never all of them. --}}
                    @if($mismatch)
                        {{-- Faza 3 krok 3.8 requirement #3 (ClickUp
                        123k99cu2b5): a CONFIRMED mismatch at return means
                        order_items.service_unit_identifier_snapshot was
                        overwritten to describe what came BACK — printing
                        only that would silently erase the fact a different
                        unit went out. Showing both, sourced from the
                        immutable state_histories row, is the honest record
                        of what staff actually verified at the counter. --}}
                        <br><span style="font-size: 9px; color: #6b7280;">
                            {{ __('orders.protocol.return.unit_mismatch', ['out' => $mismatch['handed_out_label'] ?? '—', 'in' => $mismatch['returned_label'] ?? '—']) }}
                        </span>
                    @elseif($item->service_unit_identifier_snapshot)
                        <br><span style="font-size: 9px; color: #6b7280;">{{ __('orders.protocol.unit_no', ['number' => $item->service_unit_identifier_snapshot]) }}</span>
                    @endif
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
        // Same reasoning as handover.blade.php: a return protocol can be
        // generated at any point relative to the deposit's own lifecycle —
        // printed at the counter the moment equipment comes back (deposit
        // still 'pending'/'collected', not yet settled) or reprinted later
        // once it has been. All 5 non-"not_required" statuses handled
        // explicitly (deposit_amount > 0 already rules out not_required).
        $depositStatusLine = match ($order->deposit_status) {
            'pending' => __('orders.protocol.return.deposit.pending'),
            'collected' => __('orders.protocol.return.deposit.collected'),
            'returned' => __('orders.protocol.return.deposit.returned'),
            'partial_return' => __('orders.protocol.return.deposit.partial_return'),
            'forfeited' => __('orders.protocol.return.deposit.forfeited'),
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
    {{ __('orders.protocol.return.statement') }}
</div>

<div class="notes-box">
    <div class="label">{{ __('orders.protocol.return.condition') }}</div>
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

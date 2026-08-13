<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <title>Protokół zwrotu — {{ $order->order_number }}</title>
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

<h1>Protokół zwrotu sprzętu</h1>
<div class="meta">
    Numer zamówienia: <strong>{{ $order->order_number }}</strong>
    &nbsp;|&nbsp;
    Dokument sporządzono: {{ $generatedAt }}
</div>

<table class="parties">
    <tr>
        <td>
            <div class="label">Wynajmujący</div>
            <strong>{{ $org?->name ?? '—' }}</strong><br>
            @if($pickup['address'])
                {{ $pickup['address'] }}<br>
            @endif
            @if($pickup['phone'])
                Tel.: {{ $pickup['phone'] }}<br>
            @endif
            @if($pickup['email'])
                E-mail: {{ $pickup['email'] }}
            @endif
        </td>
        <td>
            <div class="label">Najemca</div>
            <strong>{{ trim($order->customer_first_name.' '.$order->customer_last_name) }}</strong><br>
            @if($order->customer_type === 'business' && $order->invoice_company_name)
                {{ $order->invoice_company_name }}
                @if($order->invoice_nip) (NIP: {{ $order->invoice_nip }}) @endif
                <br>
            @endif
            @if($order->customer_street)
                {{ $order->customer_street }} {{ $order->customer_building }}{{ $order->customer_apartment ? '/'.$order->customer_apartment : '' }}<br>
                {{ trim(($order->customer_postal_code ?? '').' '.($order->customer_city ?? '')) }}<br>
            @endif
            Tel.: {{ $order->customer_phone ?? '—' }}<br>
            E-mail: {{ $order->customer_email }}
        </td>
    </tr>
</table>

<h2>Zwracany sprzęt</h2>
<table class="items">
    <thead>
        <tr>
            <th>Nazwa</th>
            <th>Okres wynajmu</th>
            <th class="text-right">Ilość</th>
            <th class="text-right">Wartość</th>
        </tr>
    </thead>
    <tbody>
        @foreach($order->items as $item)
        <tr>
            <td>{{ $item->service_name }}</td>
            <td>
                @if($item->start_date && $item->end_date)
                    {{ $item->start_date->format('d.m.Y') }} – {{ $item->end_date->format('d.m.Y') }}
                @else
                    —
                @endif
            </td>
            <td class="text-right">{{ $item->quantity }}</td>
            <td class="text-right">{{ number_format((float) $item->total_price, 2, ',', ' ') }} zł</td>
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
            'pending' => 'nie została pobrana — do rozliczenia z Najemcą.',
            'collected' => 'pobrana; rozliczenie zwrotu kaucji w toku.',
            'returned' => 'zwrócona Najemcy.',
            'partial_return' => 'zwrócona częściowo.',
            'forfeited' => 'zatrzymana przez Wynajmującego.',
            default => 'status: '.$order->deposit_status.'.',
        };
    @endphp
<h2>Kaucja</h2>
<p>
    Kwota kaucji: <strong>{{ number_format((float) $order->deposit_amount, 2, ',', ' ') }} zł</strong>
    — {{ $depositStatusLine }}
</p>
<p style="font-size: 9px; color: #6b7280;">
    Stan kaucji podany wyżej odzwierciedla dane w chwili sporządzenia tego dokumentu.
</p>
@endif

<div class="statement">
    Najemca zwraca, a Wynajmujący potwierdza przyjęcie wymienionego wyżej sprzętu.
    Poniższe pole zawiera stan sprzętu stwierdzony przy odbiorze zwrotu (uszkodzenia,
    braki, uwagi) — pozostawić puste, jeśli sprzęt zwrócono bez zastrzeżeń.
</div>

<div class="notes-box">
    <div class="label">Stan sprzętu przy zwrocie / uwagi</div>
</div>

<table class="signatures">
    <tr>
        <td>
            <div class="line"></div>
            Podpis Wynajmującego
        </td>
        <td>
            <div class="line"></div>
            Podpis Najemcy
        </td>
    </tr>
</table>

<div class="footer">
    Dokument wygenerowany automatycznie przez system Registro na podstawie danych zamówienia {{ $order->order_number }}.
</div>

</body>
</html>

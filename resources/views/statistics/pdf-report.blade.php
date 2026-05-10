<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <title>Raport statystyk</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1a1a1a; margin: 0; padding: 20px; }
        h1 { font-size: 18px; color: #1e3a5f; margin-bottom: 4px; }
        h2 { font-size: 13px; color: #374151; margin-top: 20px; margin-bottom: 6px; border-bottom: 1px solid #d1d5db; padding-bottom: 4px; }
        .meta { font-size: 10px; color: #6b7280; margin-bottom: 16px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
        th { background-color: #1e3a5f; color: #ffffff; text-align: left; padding: 6px 8px; font-size: 10px; }
        td { padding: 5px 8px; border-bottom: 1px solid #e5e7eb; }
        tr:nth-child(even) td { background-color: #f9fafb; }
        .total-row td { font-weight: bold; background-color: #eff6ff; border-top: 2px solid #1e3a5f; }
        .text-right { text-align: right; }
        .footer { margin-top: 30px; font-size: 9px; color: #9ca3af; text-align: center; }
    </style>
</head>
<body>

<h1>Raport statystyk</h1>
<div class="meta">
    {{ $org ? 'Organizacja: ' . $org->name : 'Wszystkie organizacje' }}
    &nbsp;|&nbsp;
    Okres: {{ $period }}
    &nbsp;|&nbsp;
    Wygenerowano: {{ $generatedAt }}
</div>

<h2>Podsumowanie</h2>
<table>
    <thead>
        <tr>
            <th>Kategoria</th>
            <th class="text-right">Liczba</th>
            <th class="text-right">Przychód (PLN)</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td>Zamówienia</td>
            <td class="text-right">{{ number_format($data['orders']['count']) }}</td>
            <td class="text-right">{{ number_format($data['orders']['revenue'], 2, ',', ' ') }}</td>
        </tr>
        <tr>
            <td>Wizyty</td>
            <td class="text-right">{{ number_format($data['appointments']['count']) }}</td>
            <td class="text-right">{{ number_format($data['appointments']['revenue'], 2, ',', ' ') }}</td>
        </tr>
        <tr>
            <td>Wypożyczenia</td>
            <td class="text-right">{{ number_format($data['rentals']['count']) }}</td>
            <td class="text-right">{{ number_format($data['rentals']['revenue'], 2, ',', ' ') }}</td>
        </tr>
        <tr class="total-row">
            <td>RAZEM</td>
            <td class="text-right">
                {{ number_format($data['orders']['count'] + $data['appointments']['count'] + $data['rentals']['count']) }}
            </td>
            <td class="text-right">{{ number_format($data['total_revenue'], 2, ',', ' ') }}</td>
        </tr>
    </tbody>
</table>

<h2>Dane dzienne</h2>
<table>
    <thead>
        <tr>
            <th>Data</th>
            <th class="text-right">Zamówienia (PLN)</th>
            <th class="text-right">Wizyty (PLN)</th>
            <th class="text-right">Wypożyczenia (PLN)</th>
            <th class="text-right">Razem (PLN)</th>
        </tr>
    </thead>
    <tbody>
        @foreach($data['by_day'] as $date => $row)
        <tr>
            <td>{{ $date }}</td>
            <td class="text-right">{{ number_format($row['orders'], 2, ',', ' ') }}</td>
            <td class="text-right">{{ number_format($row['appointments'], 2, ',', ' ') }}</td>
            <td class="text-right">{{ number_format($row['rentals'], 2, ',', ' ') }}</td>
            <td class="text-right">{{ number_format($row['total'], 2, ',', ' ') }}</td>
        </tr>
        @endforeach
    </tbody>
</table>

<div class="footer">
    Raport wygenerowany automatycznie przez system Registro. Dane oparte na zaksięgowanych płatnościach.
</div>

</body>
</html>

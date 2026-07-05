<?php

declare(strict_types=1);

namespace App\Services\Statistics;

use App\Models\Organization;
use Barryvdh\DomPDF\Facade\Pdf;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Export statistics data to CSV or PDF.
 */
class StatisticsExportService
{
    /**
     * Stream aggregated statistics as a CSV download.
     *
     * @param  array{
     *   orders: array{revenue: float, count: int},
     *   appointments: array{revenue: float, count: int},
     *   rentals: array{revenue: float, count: int},
     *   total_revenue: float,
     *   by_day: array<string, array{orders: float, appointments: float, rentals: float, total: float}>
     * }  $data
     */
    public function toCsv(array $data, string $period): StreamedResponse
    {
        $filename = 'statystyki-'.$period.'-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($data) {
            $handle = fopen('php://output', 'w');

            // BOM for Excel UTF-8 compatibility
            fwrite($handle, "\xEF\xBB\xBF");

            // Summary header
            fputcsv($handle, ['Podsumowanie'], ';');
            fputcsv($handle, ['Kategoria', 'Liczba', 'Przychód (PLN)'], ';');
            fputcsv($handle, ['Zamówienia', $data['orders']['count'], number_format($data['orders']['revenue'], 2, '.', '')], ';');
            fputcsv($handle, ['Wizyty', $data['appointments']['count'], number_format($data['appointments']['revenue'], 2, '.', '')], ';');
            fputcsv($handle, ['Wypożyczenia', $data['rentals']['count'], number_format($data['rentals']['revenue'], 2, '.', '')], ';');
            fputcsv($handle, ['RAZEM', '', number_format($data['total_revenue'], 2, '.', '')], ';');
            fputcsv($handle, [], ';');

            // Daily breakdown
            fputcsv($handle, ['Dane dzienne'], ';');
            fputcsv($handle, ['Data', 'Zamówienia (PLN)', 'Wizyty (PLN)', 'Wypożyczenia (PLN)', 'Razem (PLN)'], ';');

            foreach ($data['by_day'] as $date => $row) {
                fputcsv($handle, [
                    $date,
                    number_format($row['orders'], 2, '.', ''),
                    number_format($row['appointments'], 2, '.', ''),
                    number_format($row['rentals'], 2, '.', ''),
                    number_format($row['total'], 2, '.', ''),
                ], ';');
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * Generate and return a PDF statistics report.
     *
     * @param  array{
     *   orders: array{revenue: float, count: int},
     *   appointments: array{revenue: float, count: int},
     *   rentals: array{revenue: float, count: int},
     *   total_revenue: float,
     *   by_day: array<string, array{orders: float, appointments: float, rentals: float, total: float}>
     * }  $data
     */
    public function toPdf(array $data, ?Organization $org, string $period): \Illuminate\Http\Response
    {
        $pdf = Pdf::loadView('statistics.pdf-report', [
            'data' => $data,
            'org' => $org,
            'period' => $period,
            'generatedAt' => now()->format('Y-m-d H:i'),
        ]);

        $filename = 'statystyki-'.$period.'-'.now()->format('Y-m-d').'.pdf';

        return $pdf->download($filename);
    }
}

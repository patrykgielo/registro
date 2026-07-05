<?php

declare(strict_types=1);

namespace App\Actions\AuditLog;

use App\Models\AuditLog;
use App\Support\Audit\AuditFieldMasker;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams a CSV export of the given AuditLog records, masking known-sensitive
 * fields (PESEL, ID-document numbers) and recording the export itself as an
 * audited event (AuditLog::EVENT_EXPORTED).
 */
class ExportAuditLogsToCsv
{
    /**
     * @param  Collection<int, AuditLog>  $records
     */
    public function execute(Collection $records): StreamedResponse
    {
        $this->recordExportEvent($records);

        $filename = 'audit-logs-'.now()->format('Y-m-d-His').'.csv';
        $headers = [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => "attachment; filename=\"$filename\"",
        ];

        $callback = function () use ($records) {
            $file = fopen('php://output', 'w');
            // UTF-8 BOM for Excel compatibility
            fwrite($file, "\xEF\xBB\xBF");
            fputcsv($file, ['ID', 'Data', 'Zdarzenie', 'Użytkownik', 'Obiekt', 'IP', 'Poprzednie wartości', 'Nowe wartości']);

            foreach ($records as $record) {
                fputcsv($file, [
                    $record->id,
                    $record->created_at->format('Y-m-d H:i:s'),
                    $record->event_label,
                    $record->user?->name ?? 'N/A',
                    class_basename($record->auditable_type),
                    $record->ip_address ?? 'N/A',
                    $record->old_values ? json_encode(AuditFieldMasker::mask($record->old_values), JSON_UNESCAPED_UNICODE) : '-',
                    $record->new_values ? json_encode(AuditFieldMasker::mask($record->new_values), JSON_UNESCAPED_UNICODE) : '-',
                ]);
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    /**
     * @param  Collection<int, AuditLog>  $records
     */
    private function recordExportEvent(Collection $records): void
    {
        AuditLog::create([
            'auditable_type' => AuditLog::class,
            'auditable_id' => 0,
            'event' => AuditLog::EVENT_EXPORTED,
            'new_values' => [
                'exported_count' => $records->count(),
                'exported_ids' => $records->pluck('id')->all(),
            ],
            'user_id' => auth()->id(),
            // Use REMOTE_ADDR directly to avoid header spoofing (same rationale as Auditable trait).
            'ip_address' => request()->server('REMOTE_ADDR'),
            'user_agent' => request()->userAgent(),
        ]);
    }
}

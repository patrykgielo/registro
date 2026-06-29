<?php

declare(strict_types=1);

namespace App\Services\Lifecycle;

use App\Models\Organization;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Generates a full data export ZIP for an organization.
 *
 * Legal basis: Art. 28(3)(g) RODO — the processor (Registro) must return all
 * personal data to the controller (tenant/organization owner) upon service
 * termination. The export contains all business data belonging to the org:
 * orders, appointments, rentals, payments, tenant_payments, and settings.
 *
 * Storage: disk 'local' (private — storage/app/). NEVER disk 'public'.
 * Export data MUST NOT be publicly accessible — it contains customer PII.
 *
 * Format: ZIP containing {dataset}.json + {dataset}.csv per table, plus manifest.json.
 * CSV uses UTF-8 BOM and semicolons for Excel compatibility.
 *
 * Isolation: All queries include `WHERE organization_id = ?` — no cross-tenant data.
 * The withoutGlobalScope('organization') is NOT needed here because we use DB::table()
 * which bypasses Eloquent global scopes entirely, and we always filter by organization_id.
 */
class OrganizationDataExportService
{
    /**
     * Generate the data export ZIP for the given organization.
     *
     * @return string Relative path on disk 'local' (e.g. "exports/org-1/20260630_120000.zip")
     *
     * @throws \RuntimeException If ZipArchive cannot be created
     */
    public function generate(Organization $org): string
    {
        $orgId = $org->id;
        $timestamp = now()->format('Ymd_His');
        $dir = "exports/org-{$orgId}";
        $relativePath = "{$dir}/{$timestamp}.zip";

        Storage::disk('local')->makeDirectory($dir);

        // Use disk()->path() so the resolved path matches the disk's root
        // (config: 'local' root = storage/app/private, NOT storage/app).
        $zipPath = Storage::disk('local')->path($relativePath);

        $zip = new \ZipArchive;
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException("Cannot create ZIP archive at {$zipPath}");
        }

        $tempFiles = [];

        try {
            $zip->addFromString('manifest.json', $this->buildManifest($org, $timestamp));

            $datasets = [
                'orders' => 'orders',
                'appointments' => 'appointments',
                'rentals' => 'rentals',
                'payments' => 'payments',
                'tenant_payments' => 'tenant_payments',
            ];

            foreach ($datasets as $name => $table) {
                [$jsonTmp, $csvTmp] = $this->buildDatasetTempFiles($table, $orgId);
                $tempFiles[] = $jsonTmp;
                $tempFiles[] = $csvTmp;
                $zip->addFile($jsonTmp, "{$name}.json");
                $zip->addFile($csvTmp, "{$name}.csv");
            }

            // Settings: org-specific only (organization_id = $orgId, not global)
            [$jsonTmp, $csvTmp] = $this->buildSettingsTempFiles($orgId);
            $tempFiles[] = $jsonTmp;
            $tempFiles[] = $csvTmp;
            $zip->addFile($jsonTmp, 'settings.json');
            $zip->addFile($csvTmp, 'settings.csv');

            $zip->close();
        } catch (\Throwable $e) {
            $zip->close();
            Storage::disk('local')->delete($relativePath);
            throw $e;
        } finally {
            foreach ($tempFiles as $tmp) {
                if (file_exists($tmp)) {
                    @unlink($tmp);
                }
            }
        }

        return $relativePath;
    }

    private function buildManifest(Organization $org, string $timestamp): string
    {
        $manifest = [
            'organization_id' => $org->id,
            'organization_name' => $org->name,
            'organization_slug' => $org->slug,
            'generated_at' => now()->toIso8601String(),
            'timestamp_key' => $timestamp,
            'legal_basis' => 'Art. 28(3)(g) RODO — procesor zwraca dane administratorowi przy zakończeniu usługi',
            'scope' => 'All business data belonging to the organization. Customer PII included as the organization is the data controller.',
            'datasets' => ['orders', 'appointments', 'rentals', 'payments', 'tenant_payments', 'settings'],
            'retention_note' => 'Receiver must comply with RODO/Polish tax law: retain accounting records (invoices) ≥6 years (Art. 112 VAT), anonymize personal data when purpose expires.',
        ];

        return json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Build JSON and CSV temp files for a standard org-scoped table.
     *
     * Uses chunk(500) to avoid loading entire table into memory.
     * Writes streaming JSON array and semicolon-delimited CSV with UTF-8 BOM.
     *
     * @return array{0: string, 1: string} [jsonTmpPath, csvTmpPath]
     */
    private function buildDatasetTempFiles(string $table, int $orgId): array
    {
        $jsonTmp = (string) tempnam(sys_get_temp_dir(), 'reg_export_');
        $csvTmp = (string) tempnam(sys_get_temp_dir(), 'reg_export_');

        $jsonHandle = fopen($jsonTmp, 'w');
        $csvHandle = fopen($csvTmp, 'w');

        if (! is_resource($jsonHandle) || ! is_resource($csvHandle)) {
            @unlink($jsonTmp);
            @unlink($csvTmp);
            throw new \RuntimeException("Cannot open temp files for {$table} export.");
        }

        try {
            fwrite($csvHandle, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel
            fwrite($jsonHandle, "[\n");

            $isFirst = true;
            $headersWritten = false;

            DB::table($table)
                ->where('organization_id', $orgId)
                ->orderBy('id')
                ->chunk(500, function ($rows) use ($jsonHandle, $csvHandle, &$isFirst, &$headersWritten): void {
                    foreach ($rows as $row) {
                        $arr = (array) $row;

                        if (! $headersWritten) {
                            fputcsv($csvHandle, array_keys($arr), ';');
                            $headersWritten = true;
                        }

                        if (! $isFirst) {
                            fwrite($jsonHandle, ",\n");
                        }
                        fwrite($jsonHandle, '  '.json_encode($arr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                        $isFirst = false;

                        fputcsv($csvHandle, array_map($this->sanitizeCsvValue(...), array_values($arr)), ';');
                    }
                });

            fwrite($jsonHandle, "\n]");
            fclose($jsonHandle);
            fclose($csvHandle);

            return [$jsonTmp, $csvTmp];
        } catch (\Throwable $e) {
            @unlink($jsonTmp);
            @unlink($csvTmp);
            throw $e;
        }
    }

    /**
     * Build JSON and CSV temp files for org-specific settings.
     *
     * Settings table uses organization_id nullable (null = global).
     * We export only the org's own overrides.
     *
     * @return array{0: string, 1: string} [jsonTmpPath, csvTmpPath]
     */
    private function buildSettingsTempFiles(int $orgId): array
    {
        $jsonTmp = (string) tempnam(sys_get_temp_dir(), 'reg_export_');
        $csvTmp = (string) tempnam(sys_get_temp_dir(), 'reg_export_');

        $jsonHandle = fopen($jsonTmp, 'w');
        $csvHandle = fopen($csvTmp, 'w');

        if (! is_resource($jsonHandle) || ! is_resource($csvHandle)) {
            @unlink($jsonTmp);
            @unlink($csvTmp);
            throw new \RuntimeException('Cannot open temp files for settings export.');
        }

        try {
            fwrite($csvHandle, "\xEF\xBB\xBF");
            fwrite($jsonHandle, "[\n");

            $isFirst = true;
            $headersWritten = false;

            DB::table('settings')
                ->where('organization_id', $orgId)
                ->orderBy('id')
                ->chunk(500, function ($rows) use ($jsonHandle, $csvHandle, &$isFirst, &$headersWritten): void {
                    foreach ($rows as $row) {
                        $arr = (array) $row;

                        if (! $headersWritten) {
                            fputcsv($csvHandle, array_keys($arr), ';');
                            $headersWritten = true;
                        }

                        if (! $isFirst) {
                            fwrite($jsonHandle, ",\n");
                        }
                        fwrite($jsonHandle, '  '.json_encode($arr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                        $isFirst = false;

                        fputcsv($csvHandle, array_map($this->sanitizeCsvValue(...), array_values($arr)), ';');
                    }
                });

            fwrite($jsonHandle, "\n]");
            fclose($jsonHandle);
            fclose($csvHandle);

            return [$jsonTmp, $csvTmp];
        } catch (\Throwable $e) {
            @unlink($jsonTmp);
            @unlink($csvTmp);
            throw $e;
        }
    }

    /**
     * Prevent CSV formula injection (CWE-1236).
     *
     * Excel/LibreOffice evaluate cells starting with = + - @ \t \r as formulas.
     * Prefixing with a single quote forces the cell to be treated as plain text.
     * The quote is visible in the formula bar but suppressed in cell display.
     */
    private function sanitizeCsvValue(mixed $v): mixed
    {
        if (is_string($v) && $v !== '' && in_array($v[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'".$v;
        }

        return $v;
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\OrganizationLifecycleLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OrganizationDataExportController extends Controller
{
    /**
     * Stream the organization data export ZIP to the requester.
     *
     * Authorization (either condition must hold):
     *   1. Valid signed URL (issued by organizations:export-data command, valid 7 days)
     *   2. Authenticated super-admin (for platform-side access)
     *
     * The `file` query parameter (path on disk 'local') is signed and cannot be tampered.
     * We still validate it stays within the expected org-scoped directory as defense-in-depth.
     */
    public function download(Request $request, Organization $organization): StreamedResponse
    {
        $user = $request->user();
        $isSuperAdmin = $user?->hasRole('super-admin');

        if (! $isSuperAdmin && ! $request->hasValidSignature()) {
            abort(403, 'Link wygasł lub jest nieprawidłowy. Poproś o nowy eksport danych.');
        }

        $filePath = (string) $request->query('file', '');

        if ($filePath === '') {
            abort(404, 'Nie podano ścieżki pliku eksportu.');
        }

        // Defense-in-depth: even with a valid signature, reject paths outside the org's export dir
        $expectedPrefix = "exports/org-{$organization->id}/";
        if (! str_starts_with($filePath, $expectedPrefix) || str_contains($filePath, '..')) {
            abort(403, 'Nieprawidłowa ścieżka pliku.');
        }

        if (! Storage::disk('local')->exists($filePath)) {
            abort(404, 'Plik eksportu nie istnieje lub został usunięty. Wygeneruj nowy eksport.');
        }

        $filename = "eksport-org-{$organization->id}-{$organization->slug}.zip";

        // Audit trail: the export contains full customer PII (PESEL/NIP). Record every
        // download — including unconditional super-admin direct access (A09 OWASP).
        $via = $isSuperAdmin && ! $request->hasValidSignature() ? 'super-admin-direct' : 'signed-url';
        Log::info('OrganizationDataExportController: export downloaded', [
            'org_id' => $organization->id,
            'org_slug' => $organization->slug,
            'file' => $filePath,
            'via' => $via,
            'actor_id' => $user?->id,
            'ip' => $request->ip(),
        ]);
        OrganizationLifecycleLog::record($organization, 'data_export_downloaded', $user, [
            'via' => $via,
            'file' => $filePath,
        ]);

        return Storage::disk('local')->download($filePath, $filename, [
            'Content-Type' => 'application/zip',
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Organization;
use App\Notifications\OrganizationDataExportReadyNotification;
use App\Services\Lifecycle\OrganizationDataExportService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

class ExportOrganizationDataCommand extends Command
{
    protected $signature = 'organizations:export-data
        {organization : ID lub slug organizacji}';

    protected $description = 'Generuje eksport danych organizacji (ZIP) i wysyła link do właściciela. Art. 28(3)(g) RODO.';

    public function __construct(
        private readonly OrganizationDataExportService $exportService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $org = $this->resolveOrganization($this->argument('organization'));

        if ($org === null) {
            $this->error('Organizacja nie znaleziona: '.$this->argument('organization'));

            return self::FAILURE;
        }

        $owner = $org->owner;

        if ($owner === null) {
            $this->error("Organizacja \"{$org->name}\" (ID: {$org->id}) nie ma właściciela (owner_id = null).");

            return self::FAILURE;
        }

        $this->info("Organizacja: {$org->name} (ID: {$org->id})");
        $this->info("Właściciel: {$owner->name} <{$owner->email}>");
        $this->line('Generowanie eksportu danych...');

        Log::info('organizations:export-data start', [
            'org_id' => $org->id,
            'org_name' => $org->name,
            'owner_id' => $owner->id,
            'owner_email' => $owner->email,
        ]);

        try {
            $relativePath = $this->exportService->generate($org);
        } catch (\Throwable $e) {
            Log::error('organizations:export-data failed', [
                'org_id' => $org->id,
                'exception' => $e->getMessage(),
            ]);
            $this->error('Generowanie eksportu nie powiodło się: '.$e->getMessage());

            return self::FAILURE;
        }

        $signedUrl = URL::temporarySignedRoute(
            'platform.organization.data-export',
            now()->addDays(30),
            [
                'organization' => $org->id,
                'file' => $relativePath,
            ]
        );

        $owner->notify(new OrganizationDataExportReadyNotification($signedUrl, $org->name));

        Log::info('organizations:export-data completed', [
            'org_id' => $org->id,
            'path' => $relativePath,
            'owner_notified' => $owner->email,
            'link_expires_days' => 30,
        ]);

        $this->info('Eksport zapisany: '.Storage::disk('local')->path($relativePath));
        $this->info("Link wysłany do: {$owner->email} (ważny 30 dni)");
        $this->line('');
        $this->line('Bezpośredni URL (dla admina):');
        $this->line($signedUrl);

        return self::SUCCESS;
    }

    private function resolveOrganization(string $identifier): ?Organization
    {
        if (ctype_digit($identifier)) {
            return Organization::find((int) $identifier);
        }

        return Organization::where('slug', $identifier)->first();
    }
}

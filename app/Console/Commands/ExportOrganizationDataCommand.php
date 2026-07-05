<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\ExportOrganizationDataJob;
use App\Models\Organization;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ExportOrganizationDataCommand extends Command
{
    protected $signature = 'organizations:export-data
        {organization : ID lub slug organizacji}';

    protected $description = 'Generuje eksport danych organizacji (ZIP) i wysyła link do właściciela. Art. 28(3)(g) RODO.';

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
        $this->info("Właściciel: {$owner->first_name} {$owner->last_name} <{$owner->email}>");
        $this->line('Generowanie eksportu danych (synchronicznie)...');

        Log::info('organizations:export-data start', [
            'org_id' => $org->id,
            'org_name' => $org->name,
            'owner_id' => $owner->id,
        ]);

        try {
            ExportOrganizationDataJob::dispatchSync($org);
        } catch (\Throwable $e) {
            Log::error('organizations:export-data failed', [
                'org_id' => $org->id,
                'exception' => $e->getMessage(),
            ]);
            $this->error('Generowanie eksportu nie powiodło się: '.$e->getMessage());

            return self::FAILURE;
        }

        Log::info('organizations:export-data completed', [
            'org_id' => $org->id,
            'owner_notified_id' => $owner->id,
            'link_expires_days' => 7,
        ]);

        $this->info("Eksport wygenerowany i notyfikacja z linkiem (ważnym 7 dni) wysłana do: {$owner->email}");
        $this->warn('Link zawiera PEŁNE dane firmy — dostęp wyłącznie dla właściciela konta.');

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

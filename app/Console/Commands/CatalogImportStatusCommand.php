<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\CatalogImportBatch;
use App\Models\CatalogImportItem;
use App\Support\Catalog\ConsoleText;
use App\Support\Catalog\OrganizationResolver;
use Illuminate\Console\Command;

class CatalogImportStatusCommand extends Command
{
    protected $signature = 'catalog:import-status
        {organization? : id:123, slug:abc albo samo id/slug (domyślnie wszystkie)}
        {--batch= : Pokaż szczegóły jednej paczki}';

    protected $description = 'Lista paczek catalog:import (tylko odczyt) albo szczegóły jednej paczki.';

    public function handle(): int
    {
        $query = CatalogImportBatch::query()->orderByDesc('id');

        if ($identifier = $this->argument('organization')) {
            ['org' => $org, 'error' => $error] = OrganizationResolver::resolve((string) $identifier);

            if ($org === null) {
                $this->error($error);

                return self::FAILURE;
            }

            $query->where('organization_id', $org->id);
        }

        if ($this->option('batch') !== null) {
            $batch = (clone $query)->find((int) $this->option('batch'));

            if ($batch === null) {
                $this->error('Paczka nie znaleziona.');

                return self::FAILURE;
            }

            $this->line("Paczka #{$batch->id} (org #{$batch->organization_id}), status {$batch->status}, plik ".ConsoleText::safe($batch->source_filename).", sha256 {$batch->source_sha256}");
            $this->table(['Typ', 'Pochodzenie', 'Liczba', 'Cofnięte'], $batch->items()
                ->selectRaw('entity_type, origin, count(*) as c, sum(case when reverted_at is null then 0 else 1 end) as r')
                ->groupBy('entity_type', 'origin')->get()
                ->map(fn ($r) => [$r->entity_type, $r->origin, $r->c, $r->r])->all());

            return self::SUCCESS;
        }

        $this->table(['ID', 'Org', 'Plik', 'Status', 'Produkty (aktywne)', 'Zaimportowano'], $query->get()->map(fn (CatalogImportBatch $b) => [
            $b->id,
            $b->organization_id,
            ConsoleText::safe($b->source_filename),
            $b->status,
            $b->items()->where('entity_type', CatalogImportItem::TYPE_SERVICE)->whereNull('reverted_at')->count(),
            $b->imported_at?->toDateTimeString() ?? '-',
        ])->all());

        return self::SUCCESS;
    }
}

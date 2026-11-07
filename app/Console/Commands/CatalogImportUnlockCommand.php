<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Catalog\ConsoleText;
use App\Support\Catalog\OrganizationResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class CatalogImportUnlockCommand extends Command
{
    protected $signature = 'catalog:import-unlock
        {organization : id:123, slug:abc albo samo id/slug}
        {--force : Bez pytania o potwierdzenie. WYMAGANE w trybie nieinteraktywnym}';

    protected $description = 'Zwalnia zawieszoną blokadę catalog-import:{org} (po zabitym procesie importu/cofania). UWAGA: zwolnienie przy działającym przebiegu pozwala na równoległe przebiegi tej samej organizacji.';

    public function handle(): int
    {
        // `php artisan cache:clear` does NOT do this: on CACHE_STORE=database it empties `cache`
        // while locks live in `cache_locks`; on Redis the locks sit on the `default` connection
        // (db 0) while the cache is on db 1.
        ['org' => $org, 'error' => $error] = OrganizationResolver::resolve((string) $this->argument('organization'));

        if ($org === null) {
            $this->error($error);

            return self::FAILURE;
        }

        $this->info('Organizacja docelowa: '.ConsoleText::safe($org->name).' (id '.$org->id.', slug '.ConsoleText::safe((string) $org->slug).')');
        $this->warn('Uwaga: zwolnienie blokady podczas działającego importu lub cofania pozwala na równoległe przebiegi tej samej organizacji.');

        $force = (bool) $this->option('force');

        if (! $force && ! $this->input->isInteractive()) {
            $this->error('Tryb nieinteraktywny: dodaj --force. Blokada nie została zwolniona.');

            return self::FAILURE;
        }

        if (! $force && ! $this->confirm('Zwolnić blokadę? Jeśli import lub cofnięcie tej organizacji nadal trwa, zaczną się równolegle.')) {
            $this->line('Anulowano.');

            return self::FAILURE;
        }

        Log::warning('catalog:import-unlock: lock force-released', [
            'org_id' => $org->id,
            'interactive' => $this->input->isInteractive(),
            'force' => $force,
        ]);

        Cache::lock("catalog-import:{$org->id}")->forceRelease();

        $this->info("Blokada catalog-import:{$org->id} zwolniona.");

        return self::SUCCESS;
    }
}

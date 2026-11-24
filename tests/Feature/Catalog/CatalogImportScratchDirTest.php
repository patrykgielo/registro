<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Support\Catalog\CatalogImportConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The scratch root is configurable (config/catalog_import.php `scratch_dir`) so parallel test workers
 * can each own a private one -- a shared root made one worker's stale sweep delete another's dirs.
 */
class CatalogImportScratchDirTest extends TestCase
{
    use CatalogTestHelpers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCatalog();
        $this->fakeImageHost();
    }

    public function test_the_default_scratch_root_is_unchanged(): void
    {
        config(['catalog_import.scratch_dir' => storage_path('app/tmp/catalog-import')]);
        $this->assertSame(storage_path('app/tmp/catalog-import'), CatalogImportConfig::scratchRoot());

        config(['catalog_import.scratch_dir' => null]);
        $this->assertSame(storage_path('app/tmp/catalog-import'), CatalogImportConfig::scratchRoot(), 'unset falls back to the documented default');

        $this->assertSame(
            storage_path('app/tmp/catalog-import'),
            (require base_path('config/catalog_import.php'))['scratch_dir'],
            'the shipped config default (no CATALOG_IMPORT_SCRATCH_DIR) is the historical path',
        );
    }

    public function test_a_path_that_trims_to_nothing_falls_back_to_the_default(): void
    {
        config(['catalog_import.scratch_dir' => '/']);
        $this->assertSame(storage_path('app/tmp/catalog-import'), CatalogImportConfig::scratchRoot(), 'never the filesystem root');

        config(['catalog_import.scratch_dir' => '   ']);
        $this->assertSame(storage_path('app/tmp/catalog-import'), CatalogImportConfig::scratchRoot());
    }

    public function test_the_stale_sweep_runs_in_the_overridden_root(): void
    {
        $override = config('catalog_import.scratch_dir').'/'; // trailing slash is trimmed
        config(['catalog_import.scratch_dir' => $override]);
        $root = rtrim($override, '/');
        $this->assertSame($root, CatalogImportConfig::scratchRoot());

        $org = $this->rentalTenant();
        File::ensureDirectoryExists($root.'/old-run');
        touch($root.'/old-run', time() - 7 * 3600);

        $this->artisan('catalog:import', ['organization' => (string) $org->id, 'file' => $this->catalogFile([$this->product()]), '--image-delay' => '0'])
            ->assertExitCode(0);

        $this->assertDirectoryDoesNotExist($root.'/old-run', 'the sweep ran in the overridden root');
    }

    public function test_the_per_run_directory_is_created_under_the_overridden_root(): void
    {
        // The root does not exist yet and nothing in this test creates it: only the per-run
        // `ensureDirectoryExists(<root>/<uuid>)` can. The uuid dir is removed after the run, the root stays.
        $root = config('catalog_import.scratch_dir');
        $this->assertDirectoryDoesNotExist($root);

        $org = $this->rentalTenant();
        $this->artisan('catalog:import', ['organization' => (string) $org->id, 'file' => $this->catalogFile([$this->product()]), '--image-delay' => '0'])
            ->assertExitCode(0);

        $this->assertDirectoryExists($root, 'the per-run directory was created under the overridden root');
        $this->assertSame([], File::directories($root), 'and removed again after the run');
    }
}

<?php

namespace Tests\Unit;

use App\Support\Catalog\CatalogImportConfig;
use Tests\TestCase;

/**
 * The scratch root is overridden only by tests (tests/TestCase.php gives every paratest worker its own).
 * Without the override it must stay exactly the path production has always used.
 */
class CatalogImportScratchRootTest extends TestCase
{
    public function test_without_an_override_the_root_is_the_original_storage_path(): void
    {
        foreach ([null, '', 0, false, ['x']] as $unset) {
            config(['catalog_import.scratch_root' => $unset]);

            $this->assertSame(storage_path('app/tmp/catalog-import'), CatalogImportConfig::scratchRoot());
        }
    }

    public function test_the_shipped_config_defines_no_override(): void
    {
        $shipped = require base_path('config/catalog_import.php');

        $this->assertArrayNotHasKey('scratch_root', $shipped);
    }

    public function test_an_override_is_used_verbatim(): void
    {
        config(['catalog_import.scratch_root' => '/tmp/some-worker-root']);

        $this->assertSame('/tmp/some-worker-root', CatalogImportConfig::scratchRoot());
    }
}

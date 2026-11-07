<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Actions\Catalog\ImportCatalog;
use App\Actions\Catalog\ImportOptions;
use App\Support\Catalog\CatalogProductValidator;
use App\Support\Catalog\ImageInspector;
use App\Support\Catalog\SafeImageFetcher;
use Closure;
use Illuminate\Cache\Lock;

/**
 * ImportCatalog with every test seam wired to an optional closure:
 * makeLock(int $orgId, int $seconds): Lock, releaseLock(Lock), runWriteTransaction (afterCommit hook),
 * forgetSitemap(int), makeFetcher(ImageInspector, ImportOptions): SafeImageFetcher, budgetClock(): Closure.
 */
class SeamedImport extends ImportCatalog
{
    /** @param  array<string, Closure>  $hooks */
    public function __construct(CatalogProductValidator $validator, public array $hooks = [])
    {
        parent::__construct($validator);
    }

    protected function makeLock(int $organizationId, int $seconds): Lock
    {
        return isset($this->hooks['makeLock']) ? ($this->hooks['makeLock'])($organizationId, $seconds) : parent::makeLock($organizationId, $seconds);
    }

    protected function releaseLock(Lock $lock): void
    {
        isset($this->hooks['releaseLock']) ? ($this->hooks['releaseLock'])($lock) : parent::releaseLock($lock);
    }

    protected function runWriteTransaction(Closure $callback): mixed
    {
        $result = parent::runWriteTransaction($callback);

        if (isset($this->hooks['afterCommit'])) {
            ($this->hooks['afterCommit'])();
        }

        return $result;
    }

    protected function forgetSitemap(int $organizationId): void
    {
        isset($this->hooks['forgetSitemap']) ? ($this->hooks['forgetSitemap'])($organizationId) : parent::forgetSitemap($organizationId);
    }

    protected function makeFetcher(ImageInspector $inspector, ImportOptions $options): SafeImageFetcher
    {
        return isset($this->hooks['makeFetcher']) ? ($this->hooks['makeFetcher'])($inspector, $options) : parent::makeFetcher($inspector, $options);
    }

    protected function budgetClock(): Closure
    {
        return isset($this->hooks['budgetClock']) ? ($this->hooks['budgetClock'])() : parent::budgetClock();
    }
}

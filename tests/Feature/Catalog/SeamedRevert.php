<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Actions\Catalog\RevertCatalogImport;
use Closure;
use Illuminate\Cache\Lock;

/**
 * RevertCatalogImport with every test seam wired to an optional closure (see the method names).
 * `calls` records the order in which seams were hit.
 */
class SeamedRevert extends RevertCatalogImport
{
    /** @var array<int, string> */
    public array $calls = [];

    /** @param  array<string, Closure>  $hooks */
    public function __construct(public array $hooks = []) {}

    private function hook(string $name, array $args, Closure $fallback): mixed
    {
        $this->calls[] = $name;

        return isset($this->hooks[$name]) ? ($this->hooks[$name])(...$args) : $fallback();
    }

    protected function makeLock(int $organizationId): Lock
    {
        return $this->hook('makeLock', [$organizationId], fn () => parent::makeLock($organizationId));
    }

    protected function releaseLock(Lock $lock): void
    {
        $this->hook('releaseLock', [$lock], fn () => parent::releaseLock($lock));
    }

    protected function runRevertTransaction(Closure $callback): mixed
    {
        $this->calls[] = 'runRevertTransaction';

        if (isset($this->hooks['runRevertTransaction'])) {
            return ($this->hooks['runRevertTransaction'])($callback, fn () => parent::runRevertTransaction($callback));
        }

        return parent::runRevertTransaction($callback);
    }

    protected function freshBatchStatus(int $batchId): ?string
    {
        return $this->hook('freshBatchStatus', [$batchId], fn () => parent::freshBatchStatus($batchId));
    }

    protected function forgetSitemap(int $organizationId): void
    {
        $this->hook('forgetSitemap', [$organizationId], fn () => parent::forgetSitemap($organizationId));
    }

    protected function removeFile(string $path): bool
    {
        return $this->hook('removeFile', [$path], fn () => parent::removeFile($path));
    }

    protected function driver(): string
    {
        return $this->hook('driver', [], fn () => parent::driver());
    }

    protected function executeAlter(string $table, int $to): void
    {
        $this->hook('executeAlter', [$table, $to], fn () => parent::executeAlter($table, $to));
    }

    protected function readCounter(string $table): ?int
    {
        return $this->hook('readCounter', [$table], fn () => parent::readCounter($table));
    }

    protected function sessionLockWait(): ?int
    {
        return $this->hook('sessionLockWait', [], fn () => parent::sessionLockWait());
    }

    protected function setSessionLockWait(?int $seconds): void
    {
        $this->calls[] = "setSessionLockWait({$seconds})";
        isset($this->hooks['setSessionLockWait']) ? ($this->hooks['setSessionLockWait'])($seconds) : parent::setSessionLockWait($seconds);
    }
}

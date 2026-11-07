<?php

declare(strict_types=1);

namespace App\Support\Catalog;

/**
 * Seam between SafeImageFetcher and DNS, so the SSRF suite can feed it a host that
 * resolves public on the first call and private on the next (DNS rebinding) without
 * touching the network.
 */
interface HostResolver
{
    /**
     * @return array<int, string> every A/AAAA address of the host (empty when unresolvable)
     */
    public function resolve(string $host): array;
}

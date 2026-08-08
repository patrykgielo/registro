<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Builds the extra regex patterns TrustHosts (bootstrap/app.php) validates
 * the incoming Host header against, from config('app.tenant_hosts') — i.e.
 * TENANT_HOSTS, the same allowlist ResolveTenant enforces for pinned
 * (TENANT_SLUG-set) stacks. Kept as a standalone, request-independent pure
 * function so it's unit-testable without booting the HTTP kernel.
 *
 * Laravel's own default — config('app.url')'s host plus all its subdomains —
 * keeps applying on top of this via trustHosts()'s $subdomains flag; this
 * class only adds the extra, pinned-stack hostnames that fall outside that
 * pattern (a client's own custom domain, for instance).
 */
class TrustedTenantHosts
{
    /**
     * @return array<int, string>
     */
    public static function patterns(): array
    {
        return array_map(
            static fn (string $host): string => '^'.preg_quote($host, '/').'$',
            config('app.tenant_hosts', [])
        );
    }
}

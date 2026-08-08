<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\OrganizationLifecycleState;
use App\Models\Organization;
use Illuminate\Console\Command;

/**
 * Prints the hostnames a TLS certificate has to cover, one per line.
 *
 * Exists because this installation uses one certificate carrying every tenant
 * subdomain as a SAN, re-issued via HTTP-01 whenever the tenant list changes --
 * NOT because a wildcard is unobtainable. The app domain is registrolabs.com, a
 * domain we own and control the DNS zone for, so a wildcard (DNS-01, publishing
 * _acme-challenge.registrolabs.com TXT) is technically obtainable. It just isn't
 * implemented yet: Hostinger (where the zone is parked) is supported by neither
 * certbot's DNS plugins nor acme.sh's dnsapi, so DNS-01 would need custom certbot
 * --manual-auth-hook/--manual-cleanup-hook scripts against Hostinger's REST API
 * (which does support adding a single TXT with overwrite=false and deleting by
 * name+type). See scripts/server/sync-certificate.sh for what the SAN approach
 * costs in the meantime and what a wildcard would buy when someone builds it.
 *
 * This command is the machine-readable half of that; the privileged half is
 * scripts/server/sync-certificate.sh, which runs as root.
 *
 * Output is deliberately bare -- no headers, no colour, no decoration -- so the
 * shell can consume it directly.
 */
class ListTenantHostnamesCommand extends Command
{
    protected $signature = 'tenants:hostnames';

    protected $description = 'Print every hostname that the TLS certificate must cover, one per line';

    public function handle(): int
    {
        $baseDomain = (string) config('app.domain');

        if ($baseDomain === '') {
            $this->getOutput()->writeln('<error>app.domain is not configured</error>');

            return self::FAILURE;
        }

        $hostnames = [$baseDomain];

        // Closed and Closing tenants are excluded on purpose: their subdomains no
        // longer serve anything, and every name on the certificate is published
        // in public Certificate Transparency logs for its whole lifetime.
        // Suspended IS included -- a suspension is temporary, and its 503 page
        // should still be reachable over HTTPS rather than through a browser
        // security warning.
        $slugs = Organization::query()
            ->withoutGlobalScopes()
            ->whereIn('lifecycle_state', [
                OrganizationLifecycleState::Active->value,
                OrganizationLifecycleState::Suspended->value,
            ])
            ->orderBy('slug')
            ->pluck('slug');

        foreach ($slugs as $slug) {
            $hostnames[] = "{$slug}.{$baseDomain}";
        }

        foreach (array_unique($hostnames) as $hostname) {
            $this->getOutput()->writeln($hostname);
        }

        return self::SUCCESS;
    }
}

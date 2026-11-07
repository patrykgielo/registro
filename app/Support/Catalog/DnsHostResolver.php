<?php

declare(strict_types=1);

namespace App\Support\Catalog;

final class DnsHostResolver implements HostResolver
{
    public function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $ips = [];

        foreach (@dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;

            if (is_string($ip)) {
                $ips[] = $ip;
            }
        }

        if ($ips === []) {
            // Same view of the name libcurl will use; also interprets exotic IPv4 spellings
            // (decimal, octal) so the guard judges what the socket would really connect to.
            foreach (@gethostbynamel($host) ?: [] as $ip) {
                $ips[] = $ip;
            }
        }

        return array_values(array_unique($ips));
    }
}

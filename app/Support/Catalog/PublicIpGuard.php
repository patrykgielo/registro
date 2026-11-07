<?php

declare(strict_types=1);

namespace App\Support\Catalog;

/**
 * SSRF address policy for SafeImageFetcher: an address is acceptable only if it is
 * globally routable. Covers what `FILTER_FLAG_NO_PRIV_RANGE|NO_RES_RANGE` misses
 * (CGNAT, TEST-NETs, benchmarking, multicast, IPv6 ULA/link-local/documentation)
 * and unwraps the IPv6 forms that smuggle an IPv4 address (IPv4-mapped, NAT64,
 * 6to4) so `::ffff:127.0.0.1` is judged as 127.0.0.1.
 */
final class PublicIpGuard
{
    /** @var array<int, string> */
    private const BLOCKED_V4 = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16',
        '172.16.0.0/12', '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16',
        '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
    ];

    /** @var array<int, string> */
    private const BLOCKED_V6 = [
        '::/128', '::1/128', '100::/64', '2001::/32' /* Teredo */, '2001:10::/28' /* ORCHID */, '2001:db8::/32',
        '3fff::/20' /* documentation (RFC 9637) */, 'fc00::/7', 'fe80::/10', 'ff00::/8',
    ];

    public static function isPublic(string $ip): bool
    {
        $packed = @inet_pton($ip);

        if ($packed === false) {
            return false;
        }

        if (strlen($packed) === 4) {
            return self::v4IsPublic($packed);
        }

        // IPv4-mapped ::ffff:a.b.c.d is judged as its embedded IPv4 address. (The deprecated
        // IPv4-compatible ::a.b.c.d form needs no special case: it lies outside 2000::/3, which
        // is the only range accepted at the end of this method.)
        if (str_starts_with($packed, str_repeat("\0", 10)."\xff\xff")) {
            return self::v4IsPublic(substr($packed, 12, 4));
        }

        // NAT64 64:ff9b::/96 embeds the v4 address in the last 32 bits
        if (str_starts_with($packed, "\x00\x64\xff\x9b".str_repeat("\0", 8))) {
            return self::v4IsPublic(substr($packed, 12, 4));
        }

        // 6to4 2002::/16 embeds it in bits 16-47
        if (str_starts_with($packed, "\x20\x02")) {
            return self::v4IsPublic(substr($packed, 2, 4));
        }

        foreach (self::BLOCKED_V6 as $cidr) {
            if (self::inCidr($packed, $cidr)) {
                return false;
            }
        }

        // Only 2000::/3 is currently allocated global unicast.
        return (ord($packed[0]) & 0xE0) === 0x20;
    }

    private static function v4IsPublic(string $packed): bool
    {
        foreach (self::BLOCKED_V4 as $cidr) {
            if (self::inCidr($packed, $cidr)) {
                return false;
            }
        }

        return true;
    }

    private static function inCidr(string $packedIp, string $cidr): bool
    {
        [$net, $bits] = explode('/', $cidr);
        $packedNet = inet_pton($net);

        if ($packedNet === false || strlen($packedNet) !== strlen($packedIp)) {
            return false;
        }

        $bits = (int) $bits;
        $fullBytes = intdiv($bits, 8);

        if ($fullBytes > 0 && substr($packedIp, 0, $fullBytes) !== substr($packedNet, 0, $fullBytes)) {
            return false;
        }

        $rest = $bits % 8;

        if ($rest === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($packedIp[$fullBytes]) & $mask) === (ord($packedNet[$fullBytes]) & $mask);
    }
}

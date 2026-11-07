<?php

declare(strict_types=1);

namespace Tests\Unit\Catalog;

use App\Support\Catalog\PublicIpGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PublicIpGuardTest extends TestCase
{
    /** @return array<string, array{0: string, 1: bool}> */
    public static function addresses(): array
    {
        return [
            'public v4' => ['93.184.216.34', true],
            'public v4 (cloudflare)' => ['1.1.1.1', true],
            'public v6' => ['2606:4700:4700::1111', true],
            'loopback' => ['127.0.0.1', false],
            'loopback range' => ['127.255.0.9', false],
            'this host' => ['0.0.0.0', false],
            'rfc1918 10/8' => ['10.0.0.1', false],
            'rfc1918 172.16/12 low' => ['172.16.0.1', false],
            'rfc1918 172.16/12 high' => ['172.31.255.254', false],
            'just outside 172.16/12' => ['172.32.0.1', true],
            'rfc1918 192.168' => ['192.168.1.1', false],
            'link-local / cloud metadata' => ['169.254.169.254', false],
            'CGNAT' => ['100.64.0.1', false],
            'TEST-NET-1' => ['192.0.2.10', false],
            'benchmark' => ['198.18.0.1', false],
            'multicast' => ['224.0.0.1', false],
            'reserved 240/4' => ['240.0.0.1', false],
            'broadcast' => ['255.255.255.255', false],
            'v6 loopback' => ['::1', false],
            'v6 unspecified' => ['::', false],
            'v6 link-local' => ['fe80::1', false],
            'v6 ULA' => ['fc00::1', false],
            'v6 ULA fd' => ['fd12:3456::1', false],
            'v6 documentation' => ['2001:db8::1', false],
            'v6 documentation 3fff' => ['3fff::1', false],
            'v6 Teredo' => ['2001:0:4136:e378:8000:63bf:3fff:fdd2', false],
            'v6 ORCHID' => ['2001:10::1', false],
            'v6 public next to Teredo' => ['2001:4860:4860::8888', true],
            'v6 multicast' => ['ff02::1', false],
            'v4-mapped loopback' => ['::ffff:127.0.0.1', false],
            'v4-mapped private' => ['::ffff:10.1.2.3', false],
            'v4-mapped public' => ['::ffff:93.184.216.34', true],
            'NAT64 of loopback' => ['64:ff9b::7f00:1', false],
            '6to4 of private' => ['2002:c0a8:0101::1', false],
            'v4-compatible loopback' => ['::7f00:1', false],
            'garbage' => ['not-an-ip', false],
        ];
    }

    #[DataProvider('addresses')]
    public function test_address_policy(string $ip, bool $expectedPublic): void
    {
        $this->assertSame($expectedPublic, PublicIpGuard::isPublic($ip), $ip);
    }
}

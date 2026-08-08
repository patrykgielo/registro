<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\TrustedTenantHosts;
use Tests\TestCase;

class TrustedTenantHostsTest extends TestCase
{
    public function test_empty_tenant_hosts_produces_no_extra_patterns(): void
    {
        config(['app.tenant_hosts' => []]);

        $this->assertSame([], TrustedTenantHosts::patterns());
    }

    public function test_each_host_becomes_an_anchored_exact_match_pattern(): void
    {
        config(['app.tenant_hosts' => ['acme.pl', 'www.acme.pl']]);

        $this->assertSame(['^acme\.pl$', '^www\.acme\.pl$'], TrustedTenantHosts::patterns());
    }

    public function test_regex_metacharacters_in_a_host_are_escaped(): void
    {
        // Not a realistic hostname, but proves preg_quote() is actually being
        // applied rather than the string being interpolated raw into the
        // pattern -- a raw "." would match ANY character, silently widening
        // the allowlist far beyond what TENANT_HOSTS says.
        config(['app.tenant_hosts' => ['a.b']]);

        $patterns = TrustedTenantHosts::patterns();

        $this->assertSame(['^a\.b$'], $patterns);
        $this->assertSame(1, preg_match('#'.$patterns[0].'#i', 'a.b'));
        $this->assertSame(0, preg_match('#'.$patterns[0].'#i', 'aXb'));
    }
}

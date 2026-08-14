<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * `request()->ip()` is legal evidence here, not telemetry: it is written into
 * users.sms_consent_ip, the marketing/newsletter consent columns,
 * orders.rodo_accepted_ip, user_consents.ip_address, audit_logs and
 * maintenance_events. If it can be forged, those records stop proving anything.
 *
 * Today nginx is the edge (docker-compose.prod.yml publishes 0.0.0.0:80) and
 * reaches PHP over fastcgi, where `fastcgi_param REMOTE_ADDR $remote_addr`
 * carries the real client. Nothing sets or strips X-Forwarded-For on that path,
 * and Laravel ignores it because no proxies are trusted. That is correct — and
 * it is correct by omission, which is exactly the kind of thing that gets
 * "fixed" by someone acting on a generic recommendation.
 *
 * A documentation sweep on 2026-08-08 recommended `trustProxies(at: '*')` for
 * this application. Applying it would have made Laravel believe the header, so
 * any visitor could have written any address into their own consent record —
 * destroying the evidence the change claimed to protect. These tests exist so
 * that mistake fails in CI instead of in a GDPR request.
 *
 * When the per-tenant edge proxy lands (the edge nginx will proxy_pass to each
 * tenant's nginx, so REMOTE_ADDR becomes the edge container for everyone),
 * trusting proxies becomes REQUIRED — but scoped to the edge network's CIDR,
 * with the edge overwriting X-Forwarded-For rather than appending to it. The
 * last test is what keeps that from being done with a wildcard.
 *
 * Driven through the middleware directly rather than a route: TrustProxies sits
 * in the global stack, and routing would drag in the CMS catch-all and the
 * settings table, neither of which has anything to do with this question.
 */
class ClientIpTrustTest extends TestCase
{
    private function ipSeenByApplication(array $headers = []): string
    {
        $request = Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => '203.0.113.9']);

        foreach ($headers as $name => $value) {
            $request->headers->set($name, $value);
        }

        $seen = null;

        (new TrustProxies)->handle($request, function (Request $request) use (&$seen) {
            $seen = $request->ip();

            return response('');
        });

        return $seen;
    }

    public function test_a_forged_forwarded_header_does_not_change_the_recorded_ip(): void
    {
        $seen = $this->ipSeenByApplication([
            'X-Forwarded-For' => '198.51.100.7',
            'X-Real-IP' => '198.51.100.7',
        ]);

        $this->assertSame('203.0.113.9', $seen, 'A visitor was able to forge the address recorded as consent evidence.');
    }

    public function test_the_untampered_client_address_is_what_gets_recorded(): void
    {
        $this->assertSame('203.0.113.9', $this->ipSeenByApplication());
    }

    /**
     * Trusting every proxy means trusting the client, because the client is one
     * of the hops. If proxies are ever configured here, they must name the hosts
     * we actually run.
     */
    public function test_proxies_are_never_trusted_by_wildcard(): void
    {
        $proxies = (new \ReflectionClass(TrustProxies::class))
            ->getStaticPropertyValue('alwaysTrustProxies');

        if ($proxies === null) {
            $this->addToAssertionCount(1);

            return;
        }

        foreach ((array) $proxies as $proxy) {
            $this->assertNotSame('*', $proxy, 'Trusting any proxy lets a visitor forge their own recorded IP.');
            $this->assertNotSame('**', $proxy, 'Trusting any proxy lets a visitor forge their own recorded IP.');
        }
    }
}

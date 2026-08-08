<?php

declare(strict_types=1);

namespace Tests\Unit\Middleware;

use App\Http\Middleware\Testing\PestBrowserHostBugWorkaround;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * PestBrowserHostBugWorkaround is registered UNCONDITIONALLY in the global
 * middleware stack (bootstrap/app.php) and is kept inert outside tests by a
 * single check inside handle(). That check is the only thing standing between
 * a test-harness workaround and production traffic, so it gets a real guard
 * rather than a comment.
 *
 * The middleware rewrites SERVER_NAME/HTTP_HOST, which is upstream of
 * ResolveTenant — the exact layer this project has repeatedly leaked
 * cross-tenant data through (VULN-003, layers 1-6). A regression here would be
 * silent, which is why these assertions exist.
 */
class PestBrowserHostBugWorkaroundTest extends TestCase
{
    private function handleWithHost(string $host): Request
    {
        $request = Request::create('http://127.0.0.1:8000/admin', 'GET');
        $request->server->set('HTTP_HOST', '127.0.0.1:8000');
        $request->server->set('SERVER_NAME', '127.0.0.1');
        $request->headers->set('Host', $host);

        (new PestBrowserHostBugWorkaround)->handle($request, fn (Request $r) => response(''));

        return $request;
    }

    public function test_it_is_inert_when_the_environment_is_not_testing(): void
    {
        $this->app['env'] = 'production';

        $request = $this->handleWithHost('grent.registro.local:8000');

        $this->assertSame('127.0.0.1:8000', $request->server->get('HTTP_HOST'));
        $this->assertSame('127.0.0.1', $request->server->get('SERVER_NAME'));
    }

    public function test_it_syncs_the_server_bag_for_a_tenant_subdomain_while_testing(): void
    {
        config(['app.domain' => 'registro.local']);

        $request = $this->handleWithHost('grent.registro.local:8000');

        $this->assertSame('grent.registro.local:8000', $request->server->get('HTTP_HOST'));
        $this->assertSame('grent.registro.local', $request->server->get('SERVER_NAME'));
    }

    /**
     * Suffix matching must require the literal dot separator. Without it,
     * `registro.local.attacker.com` would satisfy a naive str_contains/
     * str_ends_with check and let a foreign host through into the bag that
     * ResolveTenant reads.
     */
    public function test_it_ignores_a_host_that_merely_looks_like_the_configured_domain(): void
    {
        config(['app.domain' => 'registro.local']);

        foreach (['registro.local.attacker.com', 'evilregistro.local', 'attacker.com'] as $host) {
            $request = $this->handleWithHost($host);

            $this->assertSame('127.0.0.1:8000', $request->server->get('HTTP_HOST'), "Host [{$host}] must not be trusted.");
            $this->assertSame('127.0.0.1', $request->server->get('SERVER_NAME'), "Host [{$host}] must not be trusted.");
        }
    }
}

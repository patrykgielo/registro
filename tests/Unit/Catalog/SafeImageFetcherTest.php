<?php

declare(strict_types=1);

namespace Tests\Unit\Catalog;

use App\Support\Catalog\ImageFetchException;
use App\Support\Catalog\ImageInspector;
use App\Support\Catalog\SafeImageFetcher;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Catalog\FakeHostResolver;
use Tests\TestCase;

/**
 * SSRF + politeness suite. No network, no DNS, no real sleeping: Http::fake(), a fake
 * resolver, and an injected sleeper/clock (the "clock" advances by exactly what the
 * sleeper was asked to sleep).
 */
class SafeImageFetcherTest extends TestCase
{
    private FakeHostResolver $resolver;

    /** @var array<int, int> */
    private array $sleeps = [];

    private float $now = 1_000_000.0;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->resolver = new FakeHostResolver;
        $this->sleeps = [];
        $this->dir = sys_get_temp_dir().'/fetcher-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/*') ?: []);
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function fetcher(?ImageInspector $inspector = null, int $hostDelayMs = 0, int $maxRedirects = 3): SafeImageFetcher
    {
        return new SafeImageFetcher(
            $this->resolver,
            $inspector ?? new ImageInspector,
            function (int $ms): void {
                $this->sleeps[] = $ms;
                $this->now += $ms;
            },
            fn (): float => $this->now,
            maxRedirects: $maxRedirects,
            hostDelayMs: $hostDelayMs,
        );
    }

    private function png(int $size = 64): string
    {
        $im = imagecreatetruecolor($size, $size);
        ob_start();
        imagepng($im);

        return (string) ob_get_clean();
    }

    private function assertRefused(string $url, string $messagePart, ?SafeImageFetcher $fetcher = null): void
    {
        try {
            ($fetcher ?? $this->fetcher())->fetch($url, $this->dir);
            $this->fail("Expected refusal for {$url}");
        } catch (ImageFetchException $e) {
            $this->assertStringContainsString($messagePart, $e->getMessage());
        }
    }

    // ---------------------------------------------------------------- happy path

    public function test_fetches_a_valid_image_including_wikimedia_thumb_paths(): void
    {
        Http::fake(['upload.wikimedia.org/*' => Http::response($this->png(), 200, ['Content-Type' => 'image/png'])]);

        $url = 'https://upload.wikimedia.org/wikipedia/commons/thumb/a/ab/Hammer_drill.jpg/1280px-Hammer_drill.jpg';
        $image = $this->fetcher()->fetch($url, $this->dir);

        // extension from the SNIFFED type, not from the URL's ".jpg"
        $this->assertSame('png', $image->extension);
        $this->assertFileExists($image->tmpPath);
        Http::assertSent(fn (Request $r) => $r->url() === $url);
    }

    public function test_sends_a_descriptive_configurable_user_agent_and_no_email(): void
    {
        Http::fake(['*' => Http::response($this->png(), 200)]);

        config(['catalog_import.user_agent' => 'RegistroCatalogImporter/1.0 (+https://registro.example)']);
        $this->fetcher()->fetch('https://img.example.com/a.png', $this->dir);

        Http::assertSent(function (Request $r) {
            $ua = $r->header('User-Agent')[0] ?? '';

            return $ua === 'RegistroCatalogImporter/1.0 (+https://registro.example)' && ! str_contains($ua, '@');
        });
    }

    public function test_default_user_agent_comes_from_config_and_has_no_email(): void
    {
        $ua = (string) config('catalog_import.user_agent');

        $this->assertStringStartsWith('RegistroCatalogImporter/', $ua);
        $this->assertStringNotContainsString('@', $ua);
    }

    // ---------------------------------------------------------------- global budget

    private function budget(int $seconds, int $bytes = 1_000_000_000): \App\Support\Catalog\FetchBudget
    {
        return new \App\Support\Catalog\FetchBudget(fn (): float => $this->now, $seconds, $bytes);
    }

    public function test_an_exhausted_budget_stops_before_any_request(): void
    {
        Http::fake(['*' => Http::response($this->png(), 200)]);
        $budget = $this->budget(10);
        $this->now += 11_000;

        try {
            $this->fetcher()->withBudget($budget)->fetch('https://img.example.com/a.png', $this->dir);
            $this->fail('budget not enforced');
        } catch (\App\Support\Catalog\ImageBudgetExceededException $e) {
            $this->assertStringContainsString('budżet', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_a_retry_after_sleep_is_truncated_to_the_remaining_budget_and_then_the_budget_error_is_thrown(): void
    {
        Http::fake(['*' => Http::response('', 429, ['Retry-After' => '60'])]);
        $budget = $this->budget(10);

        try {
            $this->fetcher()->withBudget($budget)->fetch('https://img.example.com/a.png', $this->dir);
            $this->fail('budget not enforced');
        } catch (\App\Support\Catalog\ImageBudgetExceededException) {
            $this->assertTrue(true);
        }

        $this->assertSame([10_000], $this->sleeps, 'the 60 s wait is cut to the 10 s that are left');
        Http::assertSentCount(1);
    }

    public function test_the_per_host_delay_sleep_is_truncated_too(): void
    {
        Http::fake(['*' => Http::response($this->png(), 200)]);
        $fetcher = $this->fetcher(hostDelayMs: 5000)->withBudget($this->budget(3));

        $fetcher->fetch('https://img.example.com/1.png', $this->dir);

        try {
            $fetcher->fetch('https://img.example.com/2.png', $this->dir);
            $this->fail('budget not enforced');
        } catch (\App\Support\Catalog\ImageBudgetExceededException) {
            $this->assertTrue(true);
        }

        $this->assertSame([3000], $this->sleeps);
    }

    public function test_request_timeouts_are_clipped_to_the_remaining_budget_so_one_request_cannot_overshoot_the_deadline(): void
    {
        $seen = [];
        Http::fake(['*' => function (Request $request, array $options) use (&$seen) {
            $seen[] = [$options['timeout'], $options['connect_timeout']];

            return Http::response($this->png(), 200);
        }]);

        $this->fetcher()->fetch('https://img.example.com/a.png', $this->dir);                                  // no budget: 20 / 5
        $this->fetcher()->withBudget($this->budget(8))->fetch('https://img.example.com/b.png', $this->dir);    // 8 s left
        $this->fetcher()->withBudget($this->budget(3))->fetch('https://img.example.com/c.png', $this->dir);    // 3 s left
        $this->fetcher()->withBudget($this->budget(100))->fetch('https://img.example.com/d.png', $this->dir);  // plenty

        $this->assertSame([[20, 5], [8, 5], [3, 3], [20, 5]], $seen);
    }

    public function test_the_byte_budget_counts_what_was_consumed(): void
    {
        $budget = $this->budget(100, bytes: 1000);

        $budget->consumeBytes(600);
        $this->expectException(\App\Support\Catalog\ImageBudgetExceededException::class);
        $budget->consumeBytes(401);
    }

    // ---------------------------------------------------------------- the REAL request options (seam)

    /**
     * Http::fake() callbacks receive the final Guzzle options of the request. If the DNS pin,
     * the proxy bypass or the identity-encoding rule ever disappears from SafeImageFetcher,
     * this is the test that turns red — nothing else would notice under Http::fake().
     */
    public function test_the_final_request_options_carry_the_dns_pin_and_forbid_proxy_and_compression(): void
    {
        $captured = [];
        Http::fake(['*' => function (Request $request, array $options) use (&$captured) {
            $captured[] = [$request, $options];

            return Http::response($this->png(), 200);
        }]);

        $this->fetcher()->fetch('https://img.example.com/a.png', $this->dir);

        $this->assertCount(1, $captured);
        [$request, $options] = $captured[0];

        $this->assertSame(['img.example.com:443:93.184.216.34'], $options['curl'][CURLOPT_RESOLVE], 'the vetted address must be pinned');
        $this->assertFalse($options['decode_content'], 'a gzip bomb must not be inflated past the byte cap');
        $this->assertSame('', $options['proxy'], 'an env HTTP(S)_PROXY would bypass the pin');
        $this->assertFalse($options['allow_redirects']);
        $this->assertSame(5, $options['connect_timeout']);
        $this->assertSame(20, $options['timeout']);
        $this->assertIsCallable($options['progress']);
        $this->assertIsCallable($options['on_headers']);
        $this->assertSame('identity', $request->header('Accept-Encoding')[0]);
    }

    public function test_an_ipv6_pin_is_bracketed_and_a_plain_http_port_is_80(): void
    {
        $this->resolver->map['v6.example.com'] = ['2606:4700:4700::1111'];
        $this->resolver->map['plain.example.com'] = ['93.184.216.34'];
        $pins = [];
        Http::fake(['*' => function (Request $request, array $options) use (&$pins) {
            $pins[] = $options['curl'][CURLOPT_RESOLVE][0];

            return Http::response($this->png(), 200);
        }]);

        $this->fetcher()->fetch('https://v6.example.com/a.png', $this->dir);
        $this->fetcher()->fetch('http://plain.example.com/a.png', $this->dir);

        $this->assertSame(['v6.example.com:443:[2606:4700:4700::1111]', 'plain.example.com:80:93.184.216.34'], $pins);
    }

    public function test_urls_with_backslashes_whitespace_or_control_characters_are_refused_up_front(): void
    {
        foreach ([
            'http://good.example.com\\@evil.example.net/a.png',
            'https://img.example.com/a b.png',
            "https://img.example.com/a\tb.png",
            "https://img.example.com/a\nb.png",
            "https://img.example.com/a\x01.png",
            "https://img.example.com/\x7f.png",
        ] as $url) {
            $this->assertRefused($url, 'niedozwolone znaki');
        }

        // also on a redirect hop
        Http::fake(['img.example.com/*' => Http::response('', 302, ['Location' => 'https://img.example.com/x\\y.png'])]);
        $this->assertRefused('https://img.example.com/a.png', 'niedozwolone znaki');
    }

    // ---------------------------------------------------------------- SSRF

    public function test_refuses_non_http_schemes_credentials_and_odd_ports(): void
    {
        $this->assertRefused('file:///etc/passwd', 'schemat');
        $this->assertRefused('ftp://img.example.com/a.png', 'schemat');
        $this->assertRefused('gopher://img.example.com/a', 'schemat');
        $this->assertRefused('https://user:pass@img.example.com/a.png', 'danych logowania');
        $this->assertRefused('http://img.example.com:8080/a.png', 'port 8080');
        $this->assertRefused('http://img.example.com:22/a.png', 'port 22');
        $this->assertRefused('not a url', 'URL');
    }

    public function test_refuses_ip_literals_that_are_not_public(): void
    {
        foreach (['http://127.0.0.1/a.png', 'http://10.0.0.5/a.png', 'http://169.254.169.254/latest/meta-data', 'http://[::1]/a.png', 'http://[::ffff:127.0.0.1]/a.png', 'http://192.168.0.1/a.png'] as $url) {
            $this->assertRefused($url, 'niepubliczny');
        }
    }

    public function test_refuses_a_hostname_that_resolves_to_a_private_address(): void
    {
        $this->resolver->map['evil.example.com'] = ['10.1.2.3'];

        $this->assertRefused('https://evil.example.com/a.png', 'niepubliczny adres 10.1.2.3');
    }

    public function test_one_private_address_among_public_ones_rejects_the_host(): void
    {
        $this->resolver->map['mixed.example.com'] = ['93.184.216.34', '127.0.0.1'];

        $this->assertRefused('https://mixed.example.com/a.png', 'niepubliczny');
    }

    public function test_refuses_an_unresolvable_host(): void
    {
        $this->resolver->map['nope.example.com'] = [];

        $this->assertRefused('https://nope.example.com/a.png', 'rozwiązać');
    }

    public function test_decimal_ip_spellings_are_judged_by_what_they_resolve_to(): void
    {
        // http://2130706433/ is 127.0.0.1 for inet_aton(); a resolver reports that.
        $this->resolver->map['2130706433'] = ['127.0.0.1'];

        $this->assertRefused('http://2130706433/a.png', 'niepubliczny');
    }

    public function test_redirect_to_a_private_host_is_refused_on_the_hop_not_followed(): void
    {
        $this->resolver->map['internal.example.com'] = ['10.0.0.9'];
        Http::fake([
            'img.example.com/*' => Http::response('', 302, ['Location' => 'http://internal.example.com/secret']),
            'internal.example.com/*' => Http::response('SECRET', 200),
        ]);

        $this->assertRefused('https://img.example.com/a.png', 'niepubliczny');
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'internal.example.com'));
    }

    public function test_redirect_to_a_non_http_scheme_is_refused(): void
    {
        Http::fake(['img.example.com/*' => Http::response('', 302, ['Location' => 'file:///etc/passwd'])]);

        $this->assertRefused('https://img.example.com/a.png', 'schemat');
    }

    public function test_follows_a_legitimate_redirect_including_relative_location(): void
    {
        Http::fake([
            'img.example.com/old.png' => Http::response('', 301, ['Location' => '/new/real.png']),
            'img.example.com/new/real.png' => Http::response($this->png(), 200),
        ]);

        $this->assertSame('png', $this->fetcher()->fetch('https://img.example.com/old.png', $this->dir)->extension);
    }

    public function test_redirect_chain_longer_than_the_limit_is_refused(): void
    {
        Http::fake(['img.example.com/*' => Http::response('', 302, ['Location' => 'https://img.example.com/loop.png'])]);

        $this->assertRefused('https://img.example.com/loop.png', 'przekierowań', $this->fetcher(maxRedirects: 3));
        Http::assertSentCount(4); // the first request + 3 followed hops
    }

    public function test_dns_rebinding_between_hops_is_caught_because_every_hop_resolves_again(): void
    {
        // first lookup: public (passes). The redirect target is the SAME hostname — the second
        // lookup now answers with a private address.
        $this->resolver->map['rebind.example.com'] = [['93.184.216.34'], ['127.0.0.1']];
        Http::fake(['rebind.example.com/*' => Http::response('', 302, ['Location' => 'https://rebind.example.com/two.png'])]);

        $this->assertRefused('https://rebind.example.com/one.png', 'niepubliczny');
        Http::assertSentCount(1);
    }

    // ---------------------------------------------------------------- content

    public function test_refuses_html_svg_truncated_and_oversize_responses(): void
    {
        $cases = [
            'html' => ['<html>nope</html>', 'niedozwolony typ'],
            'svg' => ['<svg xmlns="http://www.w3.org/2000/svg" width="40" height="40"></svg>', 'niedozwolony typ'],
            'truncated' => [substr($this->png(), 0, 24), 'uszkodzony'],
            'empty' => ['', 'pusta'],
        ];

        $body = '';
        Http::fake(['img.example.com/*' => function () use (&$body) {
            return Http::response($body, 200, ['Content-Type' => 'image/jpeg']);
        }]);

        foreach ($cases as [$content, $message]) {
            $body = $content;
            $this->assertRefused('https://img.example.com/x.jpg', $message);
        }

        $body = $this->png();
        $this->assertRefused('https://img.example.com/x.png', 'większy niż', $this->fetcher(new ImageInspector(maxBytes: 100)));
    }

    // ---------------------------------------------------------------- politeness

    public function test_429_then_200_succeeds_and_honours_retry_after_seconds(): void
    {
        Http::fake(['img.example.com/*' => Http::sequence()
            ->push('Too many requests', 429, ['Retry-After' => '7'])
            ->push($this->png(), 200)]);

        $image = $this->fetcher()->fetch('https://img.example.com/a.png', $this->dir);

        $this->assertSame('png', $image->extension);
        $this->assertSame([7000], $this->sleeps);
        Http::assertSentCount(2);
    }

    public function test_retry_after_may_be_an_http_date(): void
    {
        Http::fake(['img.example.com/*' => Http::sequence()
            ->push('', 503, ['Retry-After' => gmdate('D, d M Y H:i:s \G\M\T', time() + 30)])
            ->push($this->png(), 200)]);

        $this->fetcher()->fetch('https://img.example.com/a.png', $this->dir);

        $this->assertCount(1, $this->sleeps);
        $this->assertGreaterThanOrEqual(27_000, $this->sleeps[0]);
        $this->assertLessThanOrEqual(30_000, $this->sleeps[0]);
    }

    public function test_a_huge_retry_after_is_capped_at_60_seconds(): void
    {
        Http::fake(['img.example.com/*' => Http::sequence()
            ->push('', 429, ['Retry-After' => '86400'])
            ->push($this->png(), 200)]);

        $this->fetcher()->fetch('https://img.example.com/a.png', $this->dir);

        $this->assertSame([60_000], $this->sleeps);
    }

    public function test_without_retry_after_it_backs_off_exponentially(): void
    {
        Http::fake(['img.example.com/*' => Http::sequence()
            ->push('', 429)->push('', 429)->push('', 503)->push($this->png(), 200)]);

        $this->fetcher()->fetch('https://img.example.com/a.png', $this->dir);

        $this->assertSame([2000, 4000, 8000], $this->sleeps);
        Http::assertSentCount(4);
    }

    public function test_429_forever_fails_with_a_clear_message_after_the_retry_limit(): void
    {
        Http::fake(['img.example.com/*' => Http::response('Too many requests', 429, ['Retry-After' => '1'])]);

        try {
            $this->fetcher()->fetch('https://img.example.com/a.png', $this->dir);
            $this->fail('should have failed');
        } catch (ImageFetchException $e) {
            $this->assertStringContainsString('HTTP 429', $e->getMessage());
            $this->assertStringContainsString('3 ponowieniach', $e->getMessage());
        }

        $this->assertCount(3, $this->sleeps);
        Http::assertSentCount(4); // 1 + 3 retries, then give up
    }

    public function test_any_other_4xx_fails_immediately_without_retrying(): void
    {
        $status = 403;
        Http::fake(['img.example.com/*' => function () use (&$status) {
            return Http::response('no', $status);
        }]);

        foreach ([403, 404, 410] as $status) {
            $this->assertRefused('https://img.example.com/a.png', "HTTP {$status}");
        }

        $this->assertSame([], $this->sleeps);
    }

    public function test_enforces_a_minimum_delay_between_requests_to_the_same_host_only(): void
    {
        Http::fake(['*' => Http::response($this->png(), 200)]);
        $fetcher = $this->fetcher(hostDelayMs: 1000);

        $fetcher->fetch('https://img.example.com/1.png', $this->dir);   // first: no wait
        $fetcher->fetch('https://img.example.com/2.png', $this->dir);   // same host: waits
        $fetcher->fetch('https://other.example.net/3.png', $this->dir); // different host: no wait

        $this->assertSame([1000], $this->sleeps);
    }

    public function test_the_delay_already_elapsed_is_not_slept_twice(): void
    {
        Http::fake(['*' => Http::response($this->png(), 200)]);
        $fetcher = $this->fetcher(hostDelayMs: 1000);

        $fetcher->fetch('https://img.example.com/1.png', $this->dir);
        $this->now += 5000; // the caller was busy for 5 s
        $fetcher->fetch('https://img.example.com/2.png', $this->dir);

        $this->assertSame([], $this->sleeps);
    }

    public function test_with_host_delay_overrides_the_delay(): void
    {
        Http::fake(['*' => Http::response($this->png(), 200)]);
        $fetcher = $this->fetcher(hostDelayMs: 0)->withHostDelay(250);

        $fetcher->fetch('https://img.example.com/1.png', $this->dir);
        $fetcher->fetch('https://img.example.com/2.png', $this->dir);

        $this->assertSame([250], $this->sleeps);
    }
}

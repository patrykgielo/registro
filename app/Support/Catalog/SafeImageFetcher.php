<?php

declare(strict_types=1);

namespace App\Support\Catalog;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Psr\Http\Message\ResponseInterface;

/**
 * Downloads one image from the open internet without letting the URL talk to
 * anything internal (SSRF), and without hammering the origin:
 *
 *  - http/https only, ports 80/443 only, no user:pass@ in the URL (`/thumb/…/1280px-…`
 *    style paths are ordinary paths and fine);
 *  - EVERY resolved A/AAAA address must be globally routable (PublicIpGuard) — one
 *    private address in the answer rejects the host;
 *  - the vetted address is PINNED into the request (CURLOPT_RESOLVE), so a second DNS
 *    answer cannot rebind the connection to an internal address after the check;
 *  - redirects are followed by hand (max $maxRedirects), each hop going through the
 *    whole check again — Guzzle's own redirect handling is switched off;
 *  - connect 5 s / total 20 s timeouts; the download is aborted from the progress
 *    callback the moment it exceeds the byte cap (a missing Content-Length is no loophole);
 *  - the body is then content-sniffed by ImageInspector (type, dimensions).
 *
 * Politeness (Wikimedia answered HTTP 429 after two quick requests): a descriptive,
 * configurable User-Agent; a minimum pause between two requests to the same host;
 * 429/503 are retried honouring Retry-After (seconds or HTTP-date, each wait capped)
 * with exponential backoff when absent; every other 4xx/5xx fails at once. Fetching is
 * strictly sequential — the caller never runs two downloads at once.
 *
 * Under Http::fake() the progress/on_headers hooks never fire (no cURL), so the size
 * cap is ALSO enforced on the finished body. The sleeper/clock are injectable so tests
 * never really sleep.
 */
final class SafeImageFetcher
{
    private const REDIRECT_STATUSES = [301, 302, 303, 307, 308];

    private const RETRY_STATUSES = [429, 503];

    /** @var array<string, float> host => ms timestamp of the last request */
    private array $lastRequestAt = [];

    private readonly Closure $sleeper;

    private readonly Closure $clock;

    private ?FetchBudget $budget = null;

    /**
     * @param  Closure|null  $sleeper  fn(int $milliseconds): void
     * @param  Closure|null  $clock  fn(): float — current time in milliseconds
     */
    public function __construct(
        private readonly HostResolver $resolver,
        private readonly ImageInspector $inspector,
        ?Closure $sleeper = null,
        ?Closure $clock = null,
        private readonly int $maxRedirects = 3,
        private int $hostDelayMs = 1000,
        private readonly int $maxRetries = 3,
        private readonly int $maxRetryWaitSeconds = 60,
        private readonly ?string $userAgent = null,
    ) {
        $this->sleeper = $sleeper ?? static function (int $ms): void {
            usleep($ms * 1000);
        };
        $this->clock = $clock ?? static fn (): float => microtime(true) * 1000;
    }

    public function withHostDelay(int $milliseconds): static
    {
        $this->hostDelayMs = max(0, $milliseconds);

        return $this;
    }

    /**
     * The GLOBAL budget of the image phase (time; bytes are fed by the caller). Checked before every
     * attempt and before every sleep; sleeps are truncated to what is left.
     */
    public function withBudget(FetchBudget $budget): static
    {
        $this->budget = $budget;

        return $this;
    }

    /** @throws ImageBudgetExceededException */
    private function pause(int $milliseconds): void
    {
        if ($this->budget !== null) {
            $this->budget->assertTime();
            $milliseconds = min($milliseconds, max(0, (int) floor($this->budget->remainingMs())));
        }

        ($this->sleeper)($milliseconds);
    }

    /**
     * @param  string  $scratchDir  existing directory for the temp file
     *
     * @throws ImageFetchException
     */
    public function fetch(string $url, string $scratchDir): FetchedImage
    {
        $current = $url;

        for ($hop = 0; $hop <= $this->maxRedirects; $hop++) {
            [$host, $port, $ip] = $this->vet($current);

            $response = $this->requestWithRetries($current, $host, $port, $ip);

            if (in_array($response->status(), self::REDIRECT_STATUSES, true)) {
                $location = $response->header('Location');

                if ($location === '') {
                    throw new ImageFetchException('przekierowanie bez nagłówka Location');
                }

                $current = $this->absolutize($current, $location);

                continue;
            }

            if (! $response->successful()) {
                throw new ImageFetchException('serwer odpowiedział HTTP '.$response->status());
            }

            return $this->inspector->inspect($response->body(), $scratchDir.'/'.Str::random(24));
        }

        throw new ImageFetchException("zbyt wiele przekierowań (max {$this->maxRedirects})");
    }

    private function requestWithRetries(string $url, string $host, int $port, string $ip): Response
    {
        for ($attempt = 0; ; $attempt++) {
            $this->budget?->assertTime(); // before every attempt…
            $this->waitForHost($host);
            $this->budget?->assertTime(); // …and again after the per-host pause
            $response = $this->request($url, $host, $port, $ip);
            $this->lastRequestAt[$host] = ($this->clock)();

            if (! in_array($response->status(), self::RETRY_STATUSES, true)) {
                return $response;
            }

            if ($attempt >= $this->maxRetries) {
                throw new ImageFetchException(
                    "serwer ograniczył liczbę żądań (HTTP {$response->status()}) — poddano się po {$this->maxRetries} ponowieniach"
                );
            }

            $waitSeconds = $this->retryWaitSeconds($response, $attempt);
            $this->pause((int) ($waitSeconds * 1000));
            $this->lastRequestAt[$host] = ($this->clock)();
        }
    }

    private function retryWaitSeconds(Response $response, int $attempt): int
    {
        $header = trim($response->header('Retry-After'));

        if ($header !== '' && ctype_digit($header)) {
            $wait = (int) $header;
        } elseif ($header !== '' && ($ts = strtotime($header)) !== false) {
            $wait = max(0, $ts - time());
        } else {
            $wait = 2 ** ($attempt + 1); // 2, 4, 8 …
        }

        return min($wait, $this->maxRetryWaitSeconds);
    }

    private function waitForHost(string $host): void
    {
        if ($this->hostDelayMs <= 0 || ! isset($this->lastRequestAt[$host])) {
            return;
        }

        $elapsed = ($this->clock)() - $this->lastRequestAt[$host];

        if ($elapsed < $this->hostDelayMs) {
            $this->pause((int) ceil($this->hostDelayMs - $elapsed));
        }
    }

    /**
     * @return array{0: string, 1: int, 2: string} host, port, the vetted IP to pin
     *
     * @throws ImageFetchException
     */
    private function vet(string $url): array
    {
        // Backslashes, whitespace and control characters are read differently by parse_url() and
        // by libcurl ("http://good.example\@evil/"); a legitimate URL has none (spaces arrive as %20).
        if (preg_match('/[\\\\\s\x00-\x1f\x7f]/', $url) === 1) {
            throw new ImageFetchException('adres URL zawiera niedozwolone znaki (backslash, spacje, znaki sterujące)');
        }

        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'])) {
            throw new ImageFetchException('nieprawidłowy adres URL');
        }

        $scheme = strtolower($parts['scheme']);

        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new ImageFetchException("niedozwolony schemat {$scheme}:// (tylko http/https)");
        }

        if (! isset($parts['host'])) {
            throw new ImageFetchException('nieprawidłowy adres URL');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new ImageFetchException('adres URL nie może zawierać danych logowania');
        }

        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);

        if (! in_array($port, [80, 443], true)) {
            throw new ImageFetchException("niedozwolony port {$port} (tylko 80/443)");
        }

        $host = strtolower(trim($parts['host'], '[]'));

        if (! filter_var($host, FILTER_VALIDATE_IP) && ! preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/', $host)) {
            throw new ImageFetchException('nieprawidłowa nazwa hosta');
        }

        $ips = $this->resolver->resolve($host);

        if ($ips === []) {
            throw new ImageFetchException("nie można rozwiązać hosta {$host}");
        }

        foreach ($ips as $ip) {
            if (! PublicIpGuard::isPublic($ip)) {
                throw new ImageFetchException("host {$host} wskazuje na niepubliczny adres {$ip}");
            }
        }

        return [$host, $port, $ips[0]];
    }

    private function request(string $url, string $host, int $port, string $ip): Response
    {
        $aborted = false;
        $max = $this->inspector->maxBytes();
        $pinned = str_contains($ip, ':') ? "[{$ip}]" : $ip;

        // One in-flight request must not outlive the global budget: clip both timeouts to what is left.
        $timeout = 20;
        $connectTimeout = 5;

        if ($this->budget !== null) {
            $left = max(1, (int) ceil($this->budget->remainingMs() / 1000));
            $timeout = min($timeout, $left);
            $connectTimeout = min($connectTimeout, $left);
        }

        try {
            return Http::withOptions([
                'allow_redirects' => false,
                'connect_timeout' => $connectTimeout,
                'timeout' => $timeout,
                'curl' => [CURLOPT_RESOLVE => ["{$host}:{$port}:{$pinned}"]],
                // The byte cap must count REAL bytes: no transparent gunzip (a few MB of gzip
                // inflating to gigabytes) — hence `Accept-Encoding: identity` below.
                'decode_content' => false,
                // Never an env HTTP(S)_PROXY: a proxy resolves the host itself and would bypass the
                // CURLOPT_RESOLVE pin and the address check.
                'proxy' => '',
                'on_headers' => function (ResponseInterface $r) use ($max, &$aborted): void {
                    if ((int) $r->getHeaderLine('Content-Length') > $max) {
                        $aborted = true;
                        throw new ImageFetchException('plik większy niż '.intdiv($max, 1024).' KB');
                    }
                },
                'progress' => function ($downloadTotal, $downloaded) use ($max, &$aborted): void {
                    if ($downloaded > $max) {
                        $aborted = true;
                        throw new ImageFetchException('plik większy niż '.intdiv($max, 1024).' KB');
                    }
                },
            ])
                ->withHeaders([
                    'Accept' => 'image/jpeg,image/png,image/webp',
                    'Accept-Encoding' => 'identity',
                    'User-Agent' => $this->userAgent ?? (string) config('catalog_import.user_agent', 'RegistroCatalogImporter/1.0'),
                ])
                ->get($url);
        } catch (ImageFetchException $e) {
            throw $e;
        } catch (ConnectionException $e) {
            throw new ImageFetchException('błąd połączenia: '.Str::limit($e->getMessage(), 120));
        } catch (\Throwable $e) {
            if ($aborted) {
                throw new ImageFetchException('plik większy niż '.intdiv($max, 1024).' KB');
            }

            throw new ImageFetchException('błąd pobierania: '.Str::limit($e->getMessage(), 120));
        }
    }

    private function absolutize(string $base, string $location): string
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $location)) {
            return $location;
        }

        $parts = parse_url($base);
        $origin = ($parts['scheme'] ?? 'http').'://'.($parts['host'] ?? '').(isset($parts['port']) ? ':'.$parts['port'] : '');

        if (str_starts_with($location, '//')) {
            return ($parts['scheme'] ?? 'http').':'.$location;
        }

        if (str_starts_with($location, '/')) {
            return $origin.$location;
        }

        $dir = rtrim(dirname($parts['path'] ?? '/'), '/');

        return $origin.$dir.'/'.$location;
    }
}

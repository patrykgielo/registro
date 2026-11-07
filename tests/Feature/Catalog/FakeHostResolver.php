<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Support\Catalog\HostResolver;

/** host => ip list (or a list of lists, consumed one per call, to simulate DNS rebinding). */
final class FakeHostResolver implements HostResolver
{
    /** @var array<string, array<int, string>|array<int, array<int, string>>> */
    public array $map = [];

    /** @var array<int, string> */
    public array $calls = [];

    public string $default = '93.184.216.34';

    public function resolve(string $host): array
    {
        $this->calls[] = $host;

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host]; // like DnsHostResolver: a literal is its own answer
        }

        $entry = $this->map[$host] ?? [$this->default];

        if ($entry !== [] && is_array($entry[0])) {
            $next = array_shift($this->map[$host]);

            return $next ?? end($entry);
        }

        return $entry;
    }
}

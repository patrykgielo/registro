<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Organization;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class ResolveTenant
{
    /**
     * Resolve the current tenant from subdomain.
     *
     * - {slug}.registro.app → resolves Organization by slug
     * - registro.app (root domain, no subdomain) → no tenant (marketplace)
     * - Unknown subdomain → redirect to root domain (fail closed)
     */
    public function handle(Request $request, Closure $next): Response
    {
        $host = $request->getHost();
        $baseDomain = config('app.domain', 'registro.local');

        // Root domain (no subdomain) — marketplace, no tenant context
        if ($host === $baseDomain) {
            return $next($request);
        }

        // Extract subdomain: "demo.registro.local" → "demo"
        $suffix = '.'.$baseDomain;
        if (! str_ends_with($host, $suffix)) {
            // Host doesn't match our base domain at all — redirect to root
            return redirect()->to($this->rootUrl($request));
        }

        $slug = substr($host, 0, -strlen($suffix));

        // Validate slug format (prevent injection via crafted Host header)
        if (! preg_match('/^[a-z0-9][a-z0-9-]{0,61}[a-z0-9]$/', $slug) && ! preg_match('/^[a-z0-9]{1,2}$/', $slug)) {
            return redirect()->to($this->rootUrl($request));
        }

        // Resolve tenant with cache (5 min TTL)
        $tenant = Cache::remember("tenant:slug:{$slug}", 300, function () use ($slug) {
            return Organization::where('slug', $slug)
                ->where('is_active', true)
                ->first();
        });

        if (! $tenant) {
            // Unknown or inactive tenant — redirect to root domain (fail closed)
            // Do NOT cache null results (tenant might be created/activated soon)
            Cache::forget("tenant:slug:{$slug}");

            return redirect()->to($this->rootUrl($request));
        }

        $request->attributes->set('tenant', $tenant);

        return $next($request);
    }

    /**
     * Build the root domain URL preserving scheme and port.
     */
    private function rootUrl(Request $request): string
    {
        $scheme = $request->isSecure() ? 'https' : 'http';
        $baseDomain = config('app.domain', 'registro.local');
        $port = $request->getPort();

        // Only append non-standard ports
        $portSuffix = '';
        if (($scheme === 'https' && $port !== 443) || ($scheme === 'http' && $port !== 80)) {
            $portSuffix = ':'.$port;
        }

        return "{$scheme}://{$baseDomain}{$portSuffix}";
    }
}

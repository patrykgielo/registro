<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Seo\SitemapBuilder;
use App\Support\TenantFeature;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

class SitemapController extends Controller
{
    public function __invoke(SitemapBuilder $builder): Response
    {
        $tenant = TenantFeature::currentTenant();

        // RequireTenant already guards this route, so $tenant should never be
        // null here — but a controller must not assume a middleware upstream
        // always ran (defense in depth, cheap to check).
        abort_unless($tenant !== null, 404);

        $xml = Cache::remember(
            "sitemap:{$tenant->id}",
            now()->addHour(),
            fn () => $builder->build($tenant)
        );

        return response($xml, 200)->header('Content-Type', 'application/xml');
    }
}

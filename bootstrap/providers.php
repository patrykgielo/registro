<?php

// PlatformPanelProvider is registered ONLY when this container has no TENANT_SLUG
// (the shared legacy stack; dev/tests). A dedicated tenant-stack container ships
// the same Docker image but must not expose /platform at all: the panel's code
// hosts multi-tenant management (Organization list, billing, lifecycle) that a
// single-org container has no legitimate use for, and every route/controller it
// defines only exists in the router if this provider's register()/boot() runs.
// Skipping registration is not "hide the link" — it removes the surface entirely
// (verify with `php artisan route:list` inside a container booted with TENANT_SLUG
// set: no platform.* route appears). One residual: the compiled platform.css/js
// Vite assets are still physically present in the image (built once, before any
// specific stack's TENANT_SLUG is known) and remain fetchable as inert static
// files — no logic or secrets in them, and per-stack asset stripping is a Docker
// build-tooling concern, out of scope here.
//
// LoadConfiguration (which resolves config('app.tenant_slug') from TENANT_SLUG)
// runs before RegisterProviders in the framework's bootstrapper order, so
// config() is safe to call here — see Illuminate\Foundation\Http\Kernel::$bootstrappers.
return array_values(array_filter([
    App\Providers\AppServiceProvider::class,
    App\Providers\Filament\AdminPanelProvider::class,
    config('app.tenant_slug') ? null : App\Providers\Filament\PlatformPanelProvider::class,
    App\Providers\HorizonServiceProvider::class,
]));

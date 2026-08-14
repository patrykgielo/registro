<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Application Name
    |--------------------------------------------------------------------------
    |
    | This value is the name of your application, which will be used when the
    | framework needs to place the application's name in a notification or
    | other UI elements where an application name needs to be displayed.
    |
    */

    'name' => env('APP_NAME', 'Laravel'),

    /*
    |--------------------------------------------------------------------------
    | Application Environment
    |--------------------------------------------------------------------------
    |
    | This value determines the "environment" your application is currently
    | running in. This may determine how you prefer to configure various
    | services the application utilizes. Set this in your ".env" file.
    |
    */

    'env' => env('APP_ENV', 'production'),

    /*
    |--------------------------------------------------------------------------
    | Application Debug Mode
    |--------------------------------------------------------------------------
    |
    | When your application is in debug mode, detailed error messages with
    | stack traces will be shown on every error that occurs within your
    | application. If disabled, a simple generic error page is shown.
    |
    */

    'debug' => (bool) env('APP_DEBUG', false),

    /*
    |--------------------------------------------------------------------------
    | Application URL
    |--------------------------------------------------------------------------
    |
    | This URL is used by the console to properly generate URLs when using
    | the Artisan command line tool. You should set this to the root of
    | the application so that it's available within Artisan commands.
    |
    */

    'url' => env('APP_URL', 'http://localhost'),

    /*
    |--------------------------------------------------------------------------
    | Application Domain (for multi-tenancy subdomain resolution)
    |--------------------------------------------------------------------------
    |
    | The base domain without scheme or port. Used by ResolveTenant middleware
    | to extract tenant slug from subdomain. Subdomains are resolved as:
    | {slug}.{domain} → Organization::where('slug', $slug)
    |
    */

    'domain' => env('APP_DOMAIN', 'registro.local'),

    /*
    |--------------------------------------------------------------------------
    | Tenant Slug (dedicated tenant-stack containers only)
    |--------------------------------------------------------------------------
    |
    | Set on a per-client Docker stack where this container's database holds
    | exactly one Organization. Empty on the shared legacy stack and in dev/
    | tests, which still host many organizations in one database. Gates:
    | registro:tenant-provision (safety check against the wrong slug), the
    | organizations.singleton DB lock (see its migration), the public
    | registration routes, and the /platform panel registration.
    |
    */

    'tenant_slug' => env('TENANT_SLUG'),

    /*
    |--------------------------------------------------------------------------
    | Tenant Hosts (dedicated tenant-stack containers only)
    |--------------------------------------------------------------------------
    |
    | Comma-separated allowlist of hostnames this container is allowed to
    | answer on. Only consulted by ResolveTenant when tenant_slug (above) is
    | set — a Host outside this list gets 404 even though tenant_slug
    | resolves fine; fail-closed and independent of the slug pinning, not a
    | replacement for it. Empty on the shared legacy stack and in dev/tests,
    | where it is never read. Also feeds TrustHosts' pattern list
    | (bootstrap/app.php, App\Support\TrustedTenantHosts) so Laravel's own
    | Host-header validation agrees with ResolveTenant's.
    |
    */

    'tenant_hosts' => array_values(array_filter(array_map(
        static fn (string $host): string => strtolower(trim($host)),
        explode(',', (string) env('TENANT_HOSTS', ''))
    ))),

    /*
    |--------------------------------------------------------------------------
    | Application Timezone
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default timezone for your application, which
    | will be used by the PHP date and date-time functions. The timezone
    | is set to "UTC" by default as it is suitable for most use cases.
    |
    */

    'timezone' => env('APP_TIMEZONE', 'Europe/Warsaw'),

    /*
    |--------------------------------------------------------------------------
    | Application Locale Configuration
    |--------------------------------------------------------------------------
    |
    | The application locale determines the default locale that will be used
    | by Laravel's translation / localization methods. This option can be
    | set to any locale for which you plan to have translation strings.
    |
    */

    'locale' => env('APP_LOCALE', 'en'),

    'fallback_locale' => env('APP_FALLBACK_LOCALE', 'en'),

    'faker_locale' => env('APP_FAKER_LOCALE', 'en_US'),

    /*
    |--------------------------------------------------------------------------
    | Encryption Key
    |--------------------------------------------------------------------------
    |
    | This key is utilized by Laravel's encryption services and should be set
    | to a random, 32 character string to ensure that all encrypted values
    | are secure. You should do this prior to deploying the application.
    |
    */

    'cipher' => 'AES-256-CBC',

    'key' => env('APP_KEY'),

    'previous_keys' => [
        ...array_filter(
            explode(',', (string) env('APP_PREVIOUS_KEYS', ''))
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Maintenance Mode Driver
    |--------------------------------------------------------------------------
    |
    | These configuration options determine the driver used to determine and
    | manage Laravel's "maintenance mode" status. The "cache" driver will
    | allow maintenance mode to be controlled across multiple machines.
    |
    | Supported drivers: "file", "cache"
    |
    */

    'maintenance' => [
        'driver' => env('APP_MAINTENANCE_DRIVER', 'file'),
        'store' => env('APP_MAINTENANCE_STORE', 'database'),
    ],

];

<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Trusted Proxy CIDR(s)
    |--------------------------------------------------------------------------
    |
    | Consumed by Illuminate\Http\Middleware\TrustProxies' OWN fallback
    | (setTrustedProxyIpAddresses(): `$this->proxies() ?: config('trustedproxy.proxies')`)
    | — this key name is a Laravel convention, not something bootstrap/app.php
    | wires up manually. Deliberately NOT passed via ->trustProxies(at: ...) in
    | bootstrap/app.php: that closure argument is evaluated while the
    | ->withMiddleware() closure itself runs, which is BEFORE
    | LoadEnvironmentVariables/LoadConfiguration (see the timing note on
    | App\Http\Middleware\Testing\PestBrowserHostBugWorkaround for the same
    | hazard in this codebase) — env() there would silently read nothing.
    | config() is read at REQUEST time instead (inside TrustProxies::handle()),
    | well after config has loaded, so this file is the only safe place for it.
    |
    | Unset (null, the default) means TrustProxies trusts nothing: no
    | X-Forwarded-* header (Host, Proto, For, Port, Prefix, AWS-ELB) is ever
    | honored, regardless of who sends it — this is what already happens today
    | with bootstrap/app.php configuring neither TrustProxies nor TrustHosts at
    | all, and is the precondition for the browser test suite and local dev
    | (constraints unaffected by this file). There is no edge network yet (a
    | later task) — trusting a wildcard or a guessed CIDR here, before one
    | exists, would open exactly the X-Forwarded-Host password-reset poisoning
    | vector this file exists to keep closed.
    |
    | NEVER '*' or '**' (Symfony trusts the immediate caller — always this
    | project's own nginx container when TLS terminates locally, which then
    | uncritically forwards whatever a client sent). Once a real edge network
    | exists (task 5), set this to ITS CIDR only, e.g. "10.0.0.0/24".
    |
    */

    'proxies' => env('TRUSTED_PROXIES_CIDR'),

];

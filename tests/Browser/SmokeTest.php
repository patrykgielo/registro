<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Pest\Browser\ServerManager;
use Spatie\Permission\Models\Role;

/*
|--------------------------------------------------------------------------
| E2E Browser Smoke Test — FOUNDATION ONLY
|--------------------------------------------------------------------------
|
| One test, deliberately narrow: prove the real-browser stack (Playwright
| + Chromium + the in-process Laravel HTTP server) can log an admin into
| the tenant panel on a subdomain. Do not extend this file's scope — see
| tests/Pest.php and .claude/rules/tests.md.
|
| The tenant slug MUST be "grent" or "qatest" — only those two subdomains
| resolve to 127.0.0.1 inside this container (/etc/hosts). Any other slug
| fails at the DNS layer, not the application layer.
|
| Selectors: Filament renders form input `id`s using the dotted statePath
| ("form.email", "form.password" — NOT "data.email" as the wire:model
| attribute might suggest). Those ids MUST be targeted as explicit CSS
| attribute selectors (`[id="form.email"]`), never as the bare dotted
| string ("form.email"). pest-plugin-browser's Selector::isExplicit()
| heuristic treats a bare "word.word" string as a CSS "tag.class" selector
| (e.g. "form.email" -> <form> with class "email"), silently searches for
| a nonexistent element, and hangs far longer than its own 5s timeout
| instead of raising a fast, readable error. Verified against vendor
| source: vendor/pestphp/pest-plugin-browser/src/Support/Selector.php.
|
| This test depends on App\Http\Middleware\Testing\PestBrowserHostBugWorkaround
| (registered testing-only in bootstrap/app.php) to resolve the correct tenant
| on /livewire/update calls — a workaround for an OPEN upstream bug in
| pest-plugin-browser (https://github.com/pestphp/pest/issues/1734), NOT a
| vendor patch. Full root-cause writeup:
| .claude/agent-memory/test-engineer/project_e2e_browser_foundation_2026-08-08.md
| If this test starts redirecting to the wrong host again, check that
| workaround middleware is still registered before assuming vendor regressed.
|
*/

it('logs an admin into the tenant panel via the subdomain login form', function () {
    // Filament's Login page rate-limits `authenticate` at 5/min, keyed by
    // request()->ip() (see DanHarrin\LivewireRateLimiting\WithRateLimiting).
    // The array cache store is per-PHP-process, so a leftover hit from an
    // earlier request in this same process would silently 429 the form.
    Cache::flush();

    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);

    $organization = Organization::factory()->create(['slug' => 'grent']);

    $password = 'e2e-smoke-password';

    $user = User::factory()->create([
        'password' => Hash::make($password),
    ]);
    $user->assignRole('admin');
    $user->organizations()->attach($organization->id, ['role' => 'owner']);

    $port = ServerManager::instance()->http()->port;

    $page = visit("http://grent.registro.local:{$port}/admin/login");

    $page->fill('[id="form.email"]', $user->email)
        ->fill('[id="form.password"]', $password)
        ->click('button[type="submit"]')
        // Fixed delay rather than wait-for-condition, deliberately: the app is
        // served in-process (no network, no separate PHP-FPM), so the post-login
        // redirect settles in tens of milliseconds, and the alternative would
        // mean waiting on guessed Filament dashboard copy. Revisit the moment
        // this suite either grows past a handful of tests or runs on CI —
        // fixed sleeps are the classic source of flakes on loaded runners, and
        // a flaky test here teaches everyone to ignore red.
        ->wait(1)
        ->assertPathIs('/admin')
        ->assertDontSee('Zaloguj się');
});

<?php

declare(strict_types=1);

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
| Data setup + the login form itself live in loginAsTenantAdmin() (see
| tests/Pest.php) — shared with every other file in this suite. This test
| only adds the one assertion that is actually its own point: the login
| page's content is gone once you're in.
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
    $result = loginAsTenantAdmin('grent');

    $result['page']->assertDontSee('Zaloguj się');
});

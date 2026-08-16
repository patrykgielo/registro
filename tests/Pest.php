<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Pest\Browser\ServerManager;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Scoped ONLY to tests/Browser. The other 123 test files in this project
| are classical PHPUnit test classes (extends Tests\TestCase) — they are
| not "Pest tests" and this file must never change their behavior.
|
| Browser tests are Pest's functional style and run through the built-in
| Playwright-driven server (same PHP process — see LaravelHttpServer),
| so RefreshDatabase + the reference-data seeding wired via
| Tests\TestCase::$seeder (see tests.md -> "Reference data seeding") work
| exactly like any other Feature test.
|
*/

uses(TestCase::class, RefreshDatabase::class)->in('Browser');

/*
|--------------------------------------------------------------------------
| Shared Browser test fixture: one tenant, one admin, one login
|--------------------------------------------------------------------------
|
| Every tests/Browser/*Test.php file needs a logged-in tenant admin to do
| anything at all — that used to mean each file created its own Role, its
| own Organization, its own User + Hash::make(password), attached the
| organization_user pivot by hand, and drove the login form, all slightly
| differently. This is the one place that sequence lives now.
|
| Deliberately not a factory/builder: there is exactly one shape of data
| here (one org, one admin, attached as 'owner'), not a family of variants.
| A test that needs something more (TenantIsolationTest's second tenant,
| EmployeeCreationTest's 'staff' role and its second, non-admin login) adds
| that itself, locally, in its own file — see the "read this before
| extending" docblocks in those files for why.
|
| Slug MUST be "grent" or "qatest" — see tests/Browser/SmokeTest.php's
| docblock for why only those two resolve inside this container.
|
*/
function loginAsTenantAdmin(string $slug): array
{
    // Filament's Login page rate-limits `authenticate` at 5/min, keyed by
    // request()->ip(). The array cache store is per-PHP-process, and the
    // whole Browser suite runs in one process, so a leftover hit from an
    // earlier test's login would silently 429 this one.
    Cache::flush();

    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);

    $organization = Organization::factory()->create(['slug' => $slug]);

    $password = 'e2e-password';
    $admin = User::factory()->create([
        'password' => Hash::make($password),
    ]);
    $admin->assignRole('admin');
    $admin->organizations()->attach($organization->id, ['role' => 'owner']);

    $port = ServerManager::instance()->http()->port;
    $baseUrl = "http://{$slug}.registro.local:{$port}";

    // Selectors: Filament renders form input `id`s using the dotted
    // statePath ("form.email", NOT "data.email"), and they MUST be targeted
    // as explicit CSS attribute selectors (`[id="form.email"]`) — the bare
    // dotted string is misread as CSS `tag.class` by Selector::isExplicit()
    // and hangs instead of failing fast. Full writeup:
    // .claude/agent-memory/test-engineer/project_e2e_browser_foundation_2026-08-08.md
    $page = visit("{$baseUrl}/admin/login");

    $page->fill('[id="form.email"]', $admin->email)
        ->fill('[id="form.password"]', $password)
        ->click('button[type="submit"]')
        // Real wait-for-condition, not a guessed sleep: block until the
        // post-login redirect's document has actually finished loading
        // before asserting on the resulting path.
        ->waitForEvent('load')
        ->assertPathIs('/admin');

    return [
        'organization' => $organization,
        'admin' => $admin,
        'password' => $password,
        'port' => $port,
        'baseUrl' => $baseUrl,
        'page' => $page,
    ];
}

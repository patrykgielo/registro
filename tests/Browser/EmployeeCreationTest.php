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
| E2E: Admin creates an employee, employee logs in — REGRESSION GUARD
|--------------------------------------------------------------------------
|
| Protects the line at
| app/Filament/Resources/EmployeeResource/Pages/CreateEmployee.php:33
| (`$this->record->organizations()->syncWithoutDetaching(...)`).
|
| A Filament "created" flash notification and a naive assertDatabaseHas both
| stop short of the actual bug this line fixes: User::canAccessTenant() reads
| ONLY the organization_user pivot table. An employee created without that
| pivot row looks fully created — Spatie 'staff' role assigned, success toast
| shown, row visible in the admin table — and yet cannot log in at all. This
| happened in production; none of the (at the time) ~1050 tests noticed.
|
| Shared foundation (rate limiting, dotted-statePath selectors, subdomain/host
| workaround) lives in tests/Browser/SmokeTest.php and is documented in
| .claude/rules/tests.md ("tests/Browser") — read those before extending this
| file. Only gotchas specific to this scenario are commented inline below.
|
*/

it('lets a tenant admin create an employee who can then log into the panel', function () {
    // Two real logins happen in this test (admin, then the new employee) —
    // both well under Filament's 5/min authenticate() rate limit, but the
    // array cache store is per-process, so a leftover hit from an earlier
    // test in this run would 429 the first one. Same reasoning as SmokeTest.
    Cache::flush();

    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'staff', 'guard_name' => 'web']);

    // "qatest" is the second of the only two tenant slugs that resolve inside
    // this container (see SmokeTest docblock). Using it here (not "grent")
    // keeps this test's fixtures independent of the smoke test's.
    $organization = Organization::factory()->create(['slug' => 'qatest']);

    $adminPassword = 'e2e-admin-password';
    $admin = User::factory()->create(['password' => Hash::make($adminPassword)]);
    $admin->assignRole('admin');
    $admin->organizations()->attach($organization->id, ['role' => 'owner']);

    $employeeEmail = 'jan.testowy.'.uniqid().'@example.com';
    $employeePassword = 'e2e-employee-password';

    $port = ServerManager::instance()->http()->port;
    $baseUrl = "http://qatest.registro.local:{$port}";

    // Everything below runs in ONE Playwright browser context/page. A second
    // `visit()` call (a second, independent context, to simulate "logout")
    // was tried first and deadlocked every time: the in-process AmpHttp test
    // server + Playwright RPC client run cooperatively in a single PHP
    // process, and opening a second context/page while the first one is
    // still alive never returned control back to the test — no exception,
    // no timeout, just an indefinitely blocked process (confirmed via
    // breadcrumb logging: execution stopped dead on the very first API call
    // against the second page, before any assertion could even run). Given
    // that, and that Filament's logout is a genuine `<form method="post">`
    // (see AccountWidget below), driving the real logout button in the same
    // session is both the safer AND the more realistic E2E path — it is
    // exactly what a human tester would do.
    $page = visit("{$baseUrl}/admin/login");

    // --- Step 1: admin logs in ---
    $page->fill('[id="form.email"]', $admin->email)
        ->fill('[id="form.password"]', $adminPassword)
        ->click('button[type="submit"]')
        ->wait(1)
        ->assertPathIs('/admin');

    // --- Step 2: admin creates the employee through the real panel form ---
    // EmployeeResource's password field is `required(fn ($context) => $context
    // === 'create')`, so this IS the employee's real password — no
    // post-creation DB patching needed, this stays a pure E2E flow end to end.
    $page->navigate("{$baseUrl}/admin/employees/create")
        ->wait(1)
        ->fill('[id="form.first_name"]', 'Jan')
        ->fill('[id="form.last_name"]', 'Testowy')
        ->fill('[id="form.email"]', $employeeEmail)
        ->fill('[id="form.password"]', $employeePassword)
        // Filament's Create button is a split button: a second, HIDDEN
        // `button[type="submit"]` exists for the "Create & create another"
        // dropdown item, so the login form's generic selector is ambiguous
        // here. `wire:target="create"` uniquely identifies the primary
        // action's own <button>. The colon in the attribute name must be
        // escaped for the CSS selector engine (`wire\\:target`) — unescaped,
        // Chromium raises a SyntaxError instead of just failing to match.
        ->click('button[wire\\:target="create"]')
        ->wait(1);

    // Screen confirms creation: redirected to the index, flash notification
    // visible. (Deliberately NOT also asserting the new row is visible in
    // the table here: EmployeeResource::getEloquentQuery() scopes to
    // `whereHas('organizations', ...)`, so an employee created without the
    // pivot row this test guards would ALSO fail that assertion — merely
    // confirming the same bug one step earlier, and burying the much more
    // informative failure in step 3 below, which is the actual point of
    // this test.)
    $page->assertPathIs('/admin/employees')
        ->assertSee('Pracownik został utworzony');

    // --- Step 3: log out, then log in as the new employee — the actual
    // regression this test guards ---
    // Filament's AccountWidget (registered on the admin dashboard) renders a
    // plain `<form method="post" action=".../admin/logout">` with a real
    // @csrf token — not a Livewire wire:click — so this is a genuine full
    // POST navigation, exactly what clicking "Wyloguj się" does for a human.
    // Only reachable from the dashboard (the widget isn't on every page), so
    // navigate there first.
    $page->navigate("{$baseUrl}/admin")
        ->wait(1)
        // Filament renders BOTH a labeled and an icon-only variant of this
        // button for different breakpoints (both present in the DOM, toggled
        // by CSS, not server-side) — an unqualified selector is a strict-mode
        // violation (2 matches). `:visible` is a Playwright CSS extension
        // (not standard browser CSS) that filters to whichever variant the
        // current viewport is actually displaying.
        ->click('.fi-account-widget-logout-form button[type="submit"]:visible')
        ->wait(1)
        ->assertPathIs('/admin/login');

    $page->fill('[id="form.email"]', $employeeEmail)
        ->fill('[id="form.password"]', $employeePassword)
        ->click('button[type="submit"]')
        ->wait(1)
        ->assertPathIs('/admin')
        ->assertDontSee('Zaloguj się');

    // Corroborating DB-level check, placed AFTER the UX-level proof above on
    // purpose: without CreateEmployee.php:33, the employee login attempt
    // above fails FIRST (ResolveTenant's canAccessTenant() check redirects
    // off /admin back to the root domain) — this assertion only ever
    // confirms what the login already proved, it is not the primary signal.
    $employee = User::where('email', $employeeEmail)->firstOrFail();

    $this->assertDatabaseHas('organization_user', [
        'organization_id' => $organization->id,
        'user_id' => $employee->id,
        'role' => 'staff',
    ]);

    expect($employee->hasRole('staff'))->toBeTrue();
});

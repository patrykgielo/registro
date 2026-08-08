<?php

declare(strict_types=1);

use App\Models\User;
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
| The admin's org/login is the one standard fixture shared across this whole
| suite — loginAsTenantAdmin() in tests/Pest.php. The 'staff' role and the
| employee's own login below are this file's own deviation from that
| fixture and stay local on purpose (see tests/Pest.php's docblock).
|
*/

it('lets a tenant admin create an employee who can then log into the panel', function () {
    Role::firstOrCreate(['name' => 'staff', 'guard_name' => 'web']);

    // "qatest" is the second of the only two tenant slugs that resolve inside
    // this container (see tests/Pest.php's docblock).
    ['organization' => $organization, 'page' => $page, 'baseUrl' => $baseUrl] = loginAsTenantAdmin('qatest');

    $employeeEmail = 'jan.testowy.'.uniqid().'@example.com';
    $employeePassword = 'e2e-employee-password';

    // --- Step 2: admin creates the employee through the real panel form ---
    // EmployeeResource's password field is `required(fn ($context) => $context
    // === 'create')`, so this IS the employee's real password — no
    // post-creation DB patching needed, this stays a pure E2E flow end to end.
    //
    // No explicit wait before the fill() calls below: navigate() already
    // blocks until the document's 'load' event fires, and fill() itself
    // (a real Playwright locator action) auto-waits for its target to be
    // attached, visible and enabled before typing into it — a fixed sleep
    // here would just be duplicating waiting the plugin already does.
    $page->navigate("{$baseUrl}/admin/employees/create")
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
        // Real wait-for-condition: block until the resulting redirect's
        // document has finished loading before asserting on path/content.
        ->waitForEvent('load');

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
    //
    // Everything in this test runs in ONE Playwright browser context/page. A
    // second `visit()` call (a second, independent context, to simulate
    // "logout") was tried first and deadlocked every time: the in-process
    // AmpHttp test server + Playwright RPC client run cooperatively in a
    // single PHP process, and opening a second context/page while the first
    // one is still alive never returned control back to the test — no
    // exception, no timeout, just an indefinitely blocked process. Driving
    // the real logout button in the same session is both the safer AND the
    // more realistic E2E path — it is exactly what a human tester would do.
    //
    // No wait before the click() below either, for the same reason as
    // step 2: click() auto-waits for its own target to be actionable.
    $page->navigate("{$baseUrl}/admin")
        // Filament renders BOTH a labeled and an icon-only variant of this
        // button for different breakpoints (both present in the DOM, toggled
        // by CSS, not server-side) — an unqualified selector is a strict-mode
        // violation (2 matches). `:visible` is a Playwright CSS extension
        // (not standard browser CSS) that filters to whichever variant the
        // current viewport is actually displaying.
        ->click('.fi-account-widget-logout-form button[type="submit"]:visible')
        // Fixed delay, deliberately, after trying both real wait-for-condition
        // primitives the plugin exposes here (`waitForEvent('load')` and
        // `waitForEvent('networkidle')`) and finding BOTH fail this exact
        // step 100% of the time (verified: 3/3 runs each, always the same
        // failure — the next block's fill() writes into empty-looking inputs,
        // then Chromium's native "Please fill out this field" validation blocks
        // the submit). Root cause: this navigation is triggered by clicking a
        // real `<form method="post">` INSIDE an already-hydrated page, not by
        // our own `visit()`/`navigate()` — Playwright's load-state tracking
        // resolves too early against a race with Livewire/Alpine's own client
        // hydration of the fresh login page, which then appears to reset the
        // inputs Playwright's fill() already wrote to (this reset does not
        // happen for the identical selectors in loginAsTenantAdmin()'s login,
        // because that one lands on a brand-new browser context via `visit()`,
        // which incidentally gives the JS bundle enough of a head start).
        // There is no "wait until Livewire/Alpine is hydrated" primitive in
        // this plugin version to wait on instead — see
        // vendor/pestphp/pest-plugin-browser/src/Api/Concerns/HasWaitCapabilities.php.
        ->wait(1)
        ->assertPathIs('/admin/login');

    $page->fill('[id="form.email"]', $employeeEmail)
        ->fill('[id="form.password"]', $employeePassword)
        ->click('button[type="submit"]')
        ->waitForEvent('load')
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

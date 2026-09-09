<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Models\Location;
use App\Models\Organization;
use App\Support\LocationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Code review, 2026-09-09 — a distinct, deliberately isolated proof from
 * LocationContextTest. `App\Traits\BelongsToOrganization`'s global scope is
 * fail-closed for a real HTTP request (VULN-003 Layer 2) but NOT for a real
 * console/queue context: `app()->runningInConsole() && ! app()->runningUnitTests()`
 * (`BelongsToOrganization.php:36-38`) returns with ZERO filtering, not "no
 * tenant found". `LocationContext::find()`/`activeLocations()` therefore do
 * NOT rely on that scope at all — they filter by `organization_id` themselves
 * (see their docblocks).
 *
 * THE TRAP this file exists to avoid: `app()->runningInConsole()` is already
 * TRUE while PHPUnit runs (PHP_SAPI is 'cli') — but `app()->runningUnitTests()`
 * is ALSO true for the entire PHPUnit process (`Application::runningUnitTests()`
 * checks `$app['env'] === 'testing'`, bound once at bootstrap), so the
 * vulnerable branch never fires in an ordinary test. A test that merely calls
 * these methods from a plain test method exercises the SAME safe path as
 * every other Feature/Unit test in this repo and would pass identically with
 * the guard removed — proving nothing.
 *
 * To reproduce the real condition, `app()->instance('env', 'production')` is
 * used to force `runningUnitTests()` false for the scope of one assertion
 * (restored in `finally`, never left mutated for other tests). Falsifiability
 * confirmed empirically: reverting LocationContext's guard (back to
 * `Location::active()->find($id)` / `Location::active()->ordered()->get()`,
 * trusting the ambient scope) turns both tests in this file red — the first
 * because `activeLocations()` would then return the OTHER tenant's only
 * active location unfiltered (count() === 1 → auto-selected), the second
 * because `find()` would resolve the stale session id straight through.
 */
class LocationContextConsoleGuardTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Runs $callback with runningUnitTests() forced false (env !== 'testing'),
     * while runningInConsole() stays true for free (real PHP_SAPI === 'cli'
     * under PHPUnit — confirmed, not assumed). Together these reproduce
     * BelongsToOrganization's real console/queue bypass branch exactly.
     */
    private function withRealConsoleConditions(callable $callback): mixed
    {
        $originalEnv = $this->app['env'];
        $this->app->instance('env', 'production');

        try {
            return $callback();
        } finally {
            $this->app->instance('env', $originalEnv);
        }
    }

    public function test_console_context_with_no_resolved_tenant_does_not_auto_select_another_tenants_only_active_location(): void
    {
        $orgB = Organization::factory()->equipmentRental()->create();
        Location::factory()->for($orgB, 'organization')->create(['is_active' => true]);
        // Deliberately no request 'tenant' attribute set at all — simulates a
        // queue job / artisan command with nothing "current", the exact shape
        // a future Faza 5.2+ consumer could plausibly have.

        $this->withRealConsoleConditions(function () {
            $context = new LocationContext;

            $this->assertNull($context->selected());
            $this->assertFalse($context->selectionRequired());
        });
    }

    public function test_console_context_does_not_resolve_a_session_id_belonging_to_another_tenant(): void
    {
        $orgB = Organization::factory()->equipmentRental()->create();
        $leaked = Location::factory()->for($orgB, 'organization')->create(['is_active' => true]);
        session(['selected_location_id' => $leaked->id]);

        $this->withRealConsoleConditions(function () use ($leaked) {
            $context = new LocationContext;

            $this->assertNull($context->selected());
            $this->assertNotSame($leaked->id, $context->selectedId());
        });
    }
}

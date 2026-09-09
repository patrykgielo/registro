<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Models\Location;
use App\Models\Organization;
use App\Support\LocationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Faza 5.1 (86cbahqg3) foundation. Drives LocationContext directly, with the
 * tenant simulated via `$this->app['request']->attributes->set('tenant', ...)`
 * — the same pattern LocationTenantIsolationTest and
 * BookingConfirmationSecurityTest use — rather than a full HTTP round trip,
 * since selected()/selectionRequired()/set()/pruneStaleSelection() have no
 * HTTP-specific behavior of their own (that's ShareSelectedLocationTest's job).
 */
class LocationContextTest extends TestCase
{
    use RefreshDatabase;

    private LocationContext $context;

    protected function setUp(): void
    {
        parent::setUp();

        $this->context = new LocationContext;
    }

    private function actingAsTenant(Organization $org): void
    {
        $this->app['request']->attributes->set('tenant', $org);
    }

    public function test_no_resolved_tenant_returns_null_and_false_gracefully(): void
    {
        $this->assertFalse($this->context->selectionRequired());
        $this->assertNull($this->context->selected());
        $this->assertNull($this->context->selectedId());
    }

    public function test_selection_required_is_false_with_zero_active_locations(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        $this->actingAsTenant($org);

        $this->assertFalse($this->context->selectionRequired());
        $this->assertNull($this->context->selected());
    }

    public function test_selection_required_is_false_with_exactly_one_active_location(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        $location = Location::factory()->for($org, 'organization')->create(['is_active' => true]);
        $this->actingAsTenant($org);

        $this->assertFalse($this->context->selectionRequired());
    }

    /**
     * "kontekst ustawia się sam, bez udziału klienta" (tryb-jednooddzialowy.md)
     * — computed live, never persisted to session.
     */
    public function test_selected_auto_resolves_the_single_active_location_without_writing_to_session(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        $location = Location::factory()->for($org, 'organization')->create(['is_active' => true]);
        $this->actingAsTenant($org);

        $selected = $this->context->selected();

        $this->assertNotNull($selected);
        $this->assertSame($location->id, $selected->id);
        $this->assertNull(session('selected_location_id'));
    }

    public function test_selection_required_is_true_with_two_or_more_active_locations(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        Location::factory()->for($org, 'organization')->count(2)->create(['is_active' => true]);
        $this->actingAsTenant($org);

        $this->assertTrue($this->context->selectionRequired());
    }

    public function test_selected_returns_null_when_multiple_locations_exist_and_nothing_is_selected(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        Location::factory()->for($org, 'organization')->count(2)->create(['is_active' => true]);
        $this->actingAsTenant($org);

        $this->assertNull($this->context->selected());
    }

    public function test_an_inactive_location_does_not_count_toward_auto_selection(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        Location::factory()->for($org, 'organization')->create(['is_active' => false]);
        $this->actingAsTenant($org);

        // Zero ACTIVE locations — same as having none at all (Zasada 6,
        // .claude/rules/rental-availability.md: an inactive branch has no
        // business reporting availability, and by extension no business
        // being auto-selected either).
        $this->assertFalse($this->context->selectionRequired());
        $this->assertNull($this->context->selected());
    }

    public function test_set_persists_a_valid_selection_and_selected_returns_it(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        [$locationA, $locationB] = Location::factory()->for($org, 'organization')->count(2)->create(['is_active' => true]);
        $this->actingAsTenant($org);

        $this->context->set($locationB);

        $this->assertSame($locationB->id, $this->context->selectedId());
        $this->assertSame($locationB->id, session('selected_location_id'));
    }

    public function test_set_rejects_a_location_belonging_to_another_tenant(): void
    {
        $orgA = Organization::factory()->equipmentRental()->create();
        $orgB = Organization::factory()->equipmentRental()->create();
        $foreignLocation = Location::factory()->for($orgB, 'organization')->create(['is_active' => true]);
        $this->actingAsTenant($orgA);

        $this->expectException(\InvalidArgumentException::class);

        $this->context->set($foreignLocation);
    }

    public function test_set_rejects_an_inactive_location(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        $location = Location::factory()->for($org, 'organization')->create(['is_active' => false]);
        $this->actingAsTenant($org);

        $this->expectException(\InvalidArgumentException::class);

        $this->context->set($location);
    }

    public function test_clear_removes_the_session_value(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        $location = Location::factory()->for($org, 'organization')->create(['is_active' => true]);
        $this->actingAsTenant($org);
        $this->context->set($location);

        $this->context->clear();

        $this->assertNull(session('selected_location_id'));
    }

    public function test_prune_stale_selection_clears_a_cross_tenant_id(): void
    {
        $orgA = Organization::factory()->equipmentRental()->create();
        $orgB = Organization::factory()->equipmentRental()->create();
        $foreignLocation = Location::factory()->for($orgB, 'organization')->create(['is_active' => true]);
        session(['selected_location_id' => $foreignLocation->id]);
        $this->actingAsTenant($orgA);

        $this->context->pruneStaleSelection();

        $this->assertNull(session('selected_location_id'));
    }

    public function test_prune_stale_selection_clears_a_deleted_location_id(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        session(['selected_location_id' => 999999]);
        $this->actingAsTenant($org);

        $this->context->pruneStaleSelection();

        $this->assertNull(session('selected_location_id'));
    }

    public function test_prune_stale_selection_clears_a_deactivated_location(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        $location = Location::factory()->for($org, 'organization')->create(['is_active' => false]);
        session(['selected_location_id' => $location->id]);
        $this->actingAsTenant($org);

        $this->context->pruneStaleSelection();

        $this->assertNull(session('selected_location_id'));
    }

    public function test_prune_stale_selection_leaves_a_valid_selection_untouched(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        [$locationA, $locationB] = Location::factory()->for($org, 'organization')->count(2)->create(['is_active' => true]);
        session(['selected_location_id' => $locationB->id]);
        $this->actingAsTenant($org);

        $this->context->pruneStaleSelection();

        $this->assertSame($locationB->id, session('selected_location_id'));
    }
}

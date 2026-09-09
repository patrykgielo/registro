<?php

declare(strict_types=1);

namespace Tests\Feature\Rental;

use App\Models\Location;
use App\Models\Organization;
use App\Models\Service;
use App\Models\ServiceLocationStock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

/**
 * Faza 4 krok 4.6 (plan-wdrozenia.md, kontrakt-dostepnosci.md Zasada 3) —
 * wiring the optional, tenant-scoped `location_id` query param onto BOTH
 * public availability endpoints (checkAvailability + monthlyAvailability),
 * in the same step, per the plan's own acceptance criterion ("musi iść w
 * jednym kroku z endpointem API — rozdzielenie zostawia okno, w którym
 * kalendarz kłamie").
 */
class RentalBookingControllerTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Service $item;

    private Location $locationA;

    private Location $locationB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([ThrottleRequests::class]);

        $this->org = Organization::factory()->equipmentRental()->create();
        $this->item = Service::factory()->itemRental()->create([
            'organization_id' => $this->org->id,
            'quantity_total' => 10,
            'price_per_day' => 50,
        ]);

        $this->locationA = Location::factory()->for($this->org, 'organization')->create();
        $this->locationB = Location::factory()->for($this->org, 'organization')->create();

        ServiceLocationStock::where('service_id', $this->item->id)
            ->where('location_id', $this->locationA->id)
            ->update(['quantity' => 3]);

        $this->actingAsTenant($this->org);
    }

    // Same pattern as RentalExtensionControllerTest::actingAsTenant() —
    // binds a fake ResolveTenant instead of driving real Host resolution.
    private function actingAsTenant(Organization $org): static
    {
        $this->app->bind(\App\Http\Middleware\ResolveTenant::class, function () use ($org) {
            return new class($org)
            {
                public function __construct(private Organization $org) {}

                public function handle($request, $next)
                {
                    $request->attributes->set('tenant', $this->org);

                    return $next($request);
                }
            };
        });

        return $this;
    }

    // -------------------------------------------------------------------------
    // Zero regression — omitting location_id entirely behaves exactly like
    // before this step existed, on BOTH endpoints.
    // -------------------------------------------------------------------------

    public function test_check_availability_without_location_id_returns_the_global_quantity_total_based_figure(): void
    {
        $response = $this->getJson(route('rental.availability', $this->item).'?start_date=2026-05-01&end_date=2026-05-05');

        $response->assertOk();
        $response->assertJson([
            'available_quantity' => 10,
            'total_quantity' => 10,
        ]);
    }

    public function test_monthly_availability_without_location_id_returns_the_global_quantity_total_based_calendar(): void
    {
        $response = $this->getJson(route('rental.calendar', $this->item).'?year=2026&month=5');

        $response->assertOk();
        $response->assertJsonPath('2026-05-01.available_quantity', 10);
        $response->assertJsonPath('2026-05-01.status', 'available');
    }

    // -------------------------------------------------------------------------
    // With a valid, tenant-owned location — both endpoints switch to the
    // anchor-based figure, and agree with each other.
    // -------------------------------------------------------------------------

    public function test_check_availability_with_a_valid_location_id_returns_the_anchor_based_figure(): void
    {
        $response = $this->getJson(
            route('rental.availability', $this->item).'?start_date=2026-05-01&end_date=2026-05-05&location_id='.$this->locationA->id
        );

        $response->assertOk();
        $response->assertJsonPath('available_quantity', 3);
    }

    public function test_monthly_availability_with_a_valid_location_id_returns_the_anchor_based_calendar(): void
    {
        $response = $this->getJson(
            route('rental.calendar', $this->item).'?year=2026&month=5&location_id='.$this->locationA->id
        );

        $response->assertOk();
        $response->assertJsonPath('2026-05-01.available_quantity', 3);
    }

    /**
     * The core "must go in one step" proof: the point-check and the
     * calendar, asked about the SAME location on the SAME day, must never
     * disagree.
     */
    public function test_point_check_and_calendar_agree_for_the_same_location_and_day(): void
    {
        $pointCheck = $this->getJson(
            route('rental.availability', $this->item).'?start_date=2026-05-01&end_date=2026-05-01&location_id='.$this->locationA->id
        )->json('available_quantity');

        $calendarDay = $this->getJson(
            route('rental.calendar', $this->item).'?year=2026&month=5&location_id='.$this->locationA->id
        )->json('2026-05-01.available_quantity');

        $this->assertSame(3, $pointCheck);
        $this->assertSame($pointCheck, $calendarDay);
    }

    // -------------------------------------------------------------------------
    // Fail-closed — a location_id from another tenant (or one that does not
    // exist at all) is REJECTED with a validation error, never silently
    // treated as "no location"/"any location". This is the antipattern
    // agent-usage.md explicitly forbids copying from ServiceAreaValidator.
    // -------------------------------------------------------------------------

    public function test_check_availability_rejects_a_location_id_belonging_to_another_tenant(): void
    {
        $otherOrg = Organization::factory()->equipmentRental()->create();
        $foreignLocation = Location::factory()->for($otherOrg, 'organization')->create();

        $response = $this->getJson(
            route('rental.availability', $this->item).'?start_date=2026-05-01&end_date=2026-05-05&location_id='.$foreignLocation->id
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['location_id']);
    }

    public function test_monthly_availability_rejects_a_location_id_belonging_to_another_tenant(): void
    {
        $otherOrg = Organization::factory()->equipmentRental()->create();
        $foreignLocation = Location::factory()->for($otherOrg, 'organization')->create();

        $response = $this->getJson(
            route('rental.calendar', $this->item).'?year=2026&month=5&location_id='.$foreignLocation->id
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['location_id']);
    }

    public function test_check_availability_rejects_a_location_id_that_does_not_exist_at_all(): void
    {
        $response = $this->getJson(
            route('rental.availability', $this->item).'?start_date=2026-05-01&end_date=2026-05-05&location_id=999999'
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['location_id']);
    }

    public function test_check_availability_rejects_a_non_integer_location_id(): void
    {
        $response = $this->getJson(
            route('rental.availability', $this->item).'?start_date=2026-05-01&end_date=2026-05-05&location_id=not-a-number'
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['location_id']);
    }

    /**
     * Code review 2026-09-09: an inactive location must not be selectable
     * through the public API even though it belongs to the right tenant —
     * it isn't selling anything, so it has no business reporting
     * availability. Mirrors the "belongs to another tenant" tests above,
     * same 422 shape.
     */
    public function test_check_availability_rejects_an_inactive_location_id(): void
    {
        $inactiveLocation = Location::factory()->for($this->org, 'organization')->create(['is_active' => false]);

        $response = $this->getJson(
            route('rental.availability', $this->item).'?start_date=2026-05-01&end_date=2026-05-05&location_id='.$inactiveLocation->id
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['location_id']);
    }

    public function test_monthly_availability_rejects_an_inactive_location_id(): void
    {
        $inactiveLocation = Location::factory()->for($this->org, 'organization')->create(['is_active' => false]);

        $response = $this->getJson(
            route('rental.calendar', $this->item).'?year=2026&month=5&location_id='.$inactiveLocation->id
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['location_id']);
    }
}

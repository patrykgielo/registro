<?php

declare(strict_types=1);

namespace Tests\Feature\Rental;

use App\Enums\RentalStatus;
use App\Enums\ServiceType;
use App\Http\Middleware\ResolveTenant;
use App\Models\Organization;
use App\Models\Rental;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

class RentalBookingControllerTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Service $rentalService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([ThrottleRequests::class, ResolveTenant::class]);

        $this->org = Organization::factory()->equipmentRental()->create([
            'slug' => 'test-rental-org',
        ]);

        $this->rentalService = Service::factory()->itemRental()->create([
            'organization_id' => $this->org->id,
            'quantity_total' => 5,
            'price_per_day' => 100.00,
            'price_per_week' => null,
            'is_active' => true,
        ]);

        // ResolveTenant is bypassed — BelongsToOrganization scope is a no-op without
        // a tenant in context, so all Rental/Service queries are unscoped, which is
        // exactly what we want for testing the controller logic in isolation.
    }

    // ─────────────────────────────────────────────
    // show (step 1 GET)
    // ─────────────────────────────────────────────

    public function test_step1_returns_view_for_rental_service(): void
    {
        $response = $this->get(route('rental.step1', $this->rentalService));

        $response->assertOk();
        $response->assertViewIs('rental.step1');
        $response->assertViewHas('service', fn ($s) => $s->id === $this->rentalService->id);
    }

    public function test_step1_returns_404_for_timeslot_service(): void
    {
        $timeSlotService = Service::factory()->create([
            'organization_id' => $this->org->id,
            'service_type' => ServiceType::TimeSlot,
            'is_active' => true,
        ]);

        $this->get(route('rental.step1', $timeSlotService))->assertNotFound();
    }

    public function test_step1_returns_404_for_inactive_service(): void
    {
        $inactive = Service::factory()->itemRental()->create([
            'organization_id' => $this->org->id,
            'is_active' => false,
        ]);

        $this->get(route('rental.step1', $inactive))->assertNotFound();
    }

    public function test_step1_prefills_existing_active_hold(): void
    {
        $rental = Rental::factory()->held()->create([
            'organization_id' => $this->org->id,
            'service_id' => $this->rentalService->id,
            'start_date' => '2027-07-01',
            'end_date' => '2027-07-03',
            'quantity' => 2,
        ]);

        $this->withSession(["rental_booking.{$this->rentalService->id}.rental_id" => $rental->id])
            ->get(route('rental.step1', $this->rentalService))
            ->assertOk()
            ->assertViewHas('step1', fn ($step1) => $step1['start_date'] === '2027-07-01'
                && $step1['end_date'] === '2027-07-03'
                && $step1['quantity'] === 2);
    }

    // ─────────────────────────────────────────────
    // storeStep1 (POST)
    // ─────────────────────────────────────────────

    public function test_step1_store_creates_hold_and_redirects_to_step2(): void
    {
        $response = $this->post(route('rental.step1.store', $this->rentalService), [
            'start_date' => '2027-07-01',
            'end_date' => '2027-07-03',
            'quantity' => 1,
        ]);

        $response->assertRedirect(route('rental.step2', $this->rentalService));

        $this->assertDatabaseHas('rentals', [
            'service_id' => $this->rentalService->id,
            'status' => 'held',
            'quantity' => 1,
        ]);
    }

    public function test_step1_store_redirects_back_with_error_when_unavailable(): void
    {
        // Block all items
        Rental::factory()->create([
            'organization_id' => $this->org->id,
            'service_id' => $this->rentalService->id,
            'quantity' => 5,
            'start_date' => '2027-07-01',
            'end_date' => '2027-07-03',
            'status' => RentalStatus::Confirmed,
        ]);

        $response = $this->post(route('rental.step1.store', $this->rentalService), [
            'start_date' => '2027-07-01',
            'end_date' => '2027-07-03',
            'quantity' => 1,
        ]);

        $response->assertRedirect(route('rental.step1', $this->rentalService));
        $response->assertSessionHas('error');
    }

    public function test_step1_store_validation_rejects_past_start_date(): void
    {
        $this->post(route('rental.step1.store', $this->rentalService), [
            'start_date' => '2020-01-01',
            'end_date' => '2020-01-05',
            'quantity' => 1,
        ])->assertSessionHasErrors(['start_date']);
    }

    public function test_step1_store_validation_rejects_end_before_start(): void
    {
        $this->post(route('rental.step1.store', $this->rentalService), [
            'start_date' => '2027-07-10',
            'end_date' => '2027-07-05',
            'quantity' => 1,
        ])->assertSessionHasErrors(['end_date']);
    }

    public function test_step1_store_releases_previous_hold_on_new_submission(): void
    {
        $oldRental = Rental::factory()->held()->create([
            'organization_id' => $this->org->id,
            'service_id' => $this->rentalService->id,
        ]);

        $this->withSession(["rental_booking.{$this->rentalService->id}.rental_id" => $oldRental->id])
            ->post(route('rental.step1.store', $this->rentalService), [
                'start_date' => '2027-08-01',
                'end_date' => '2027-08-03',
                'quantity' => 1,
            ]);

        $oldRental->refresh();
        $this->assertSame(RentalStatus::Expired, $oldRental->status);
    }

    // ─────────────────────────────────────────────
    // showStep2 (GET)
    // ─────────────────────────────────────────────

    public function test_step2_show_renders_view_with_active_hold(): void
    {
        $rental = Rental::factory()->held()->create([
            'organization_id' => $this->org->id,
            'service_id' => $this->rentalService->id,
        ]);

        $this->withSession(["rental_booking.{$this->rentalService->id}.rental_id" => $rental->id])
            ->get(route('rental.step2', $this->rentalService))
            ->assertOk()
            ->assertViewIs('rental.step2');
    }

    public function test_step2_show_redirects_to_step1_when_no_hold(): void
    {
        $this->get(route('rental.step2', $this->rentalService))
            ->assertRedirect(route('rental.step1', $this->rentalService))
            ->assertSessionHas('error');
    }

    public function test_step2_show_redirects_when_hold_expired(): void
    {
        $rental = Rental::factory()->held()->create([
            'organization_id' => $this->org->id,
            'service_id' => $this->rentalService->id,
            'held_until' => now()->subMinute(),
        ]);

        $this->withSession(["rental_booking.{$this->rentalService->id}.rental_id" => $rental->id])
            ->get(route('rental.step2', $this->rentalService))
            ->assertRedirect(route('rental.step1', $this->rentalService));
    }

    public function test_step2_show_prefills_logged_in_user_contact(): void
    {
        $user = User::factory()->create([
            'first_name' => 'Anna',
            'last_name' => 'Nowak',
            'email' => 'anna@example.com',
        ]);

        $rental = Rental::factory()->held()->create([
            'organization_id' => $this->org->id,
            'service_id' => $this->rentalService->id,
        ]);

        $this->actingAs($user)
            ->withSession(["rental_booking.{$this->rentalService->id}.rental_id" => $rental->id])
            ->get(route('rental.step2', $this->rentalService))
            ->assertOk()
            ->assertViewHas('step2', fn ($step2) => $step2['first_name'] === 'Anna'
                && $step2['email'] === 'anna@example.com');
    }

    // ─────────────────────────────────────────────
    // storeStep2 (POST)
    // ─────────────────────────────────────────────

    public function test_step2_store_saves_contact_and_redirects_to_step3(): void
    {
        $rental = Rental::factory()->held()->create([
            'organization_id' => $this->org->id,
            'service_id' => $this->rentalService->id,
        ]);

        $this->withSession(["rental_booking.{$this->rentalService->id}.rental_id" => $rental->id])
            ->post(route('rental.step2.store', $this->rentalService), [
                'first_name' => 'Jan',
                'last_name' => 'Kowalski',
                'email' => 'jan@example.com',
                'phone' => '500123456',
            ])
            ->assertRedirect(route('rental.step3', $this->rentalService));
    }

    public function test_step2_store_validates_required_fields(): void
    {
        $rental = Rental::factory()->held()->create([
            'organization_id' => $this->org->id,
            'service_id' => $this->rentalService->id,
        ]);

        $this->withSession(["rental_booking.{$this->rentalService->id}.rental_id" => $rental->id])
            ->post(route('rental.step2.store', $this->rentalService), [])
            ->assertSessionHasErrors(['first_name', 'last_name', 'email', 'phone']);
    }

    public function test_step2_store_redirects_to_step1_when_hold_missing(): void
    {
        $this->post(route('rental.step2.store', $this->rentalService), [
            'first_name' => 'Jan',
            'last_name' => 'Kowalski',
            'email' => 'jan@example.com',
            'phone' => '500123456',
        ])->assertRedirect(route('rental.step1', $this->rentalService));
    }

    // ─────────────────────────────────────────────
    // showStep3 (GET)
    // ─────────────────────────────────────────────

    public function test_step3_renders_summary_view(): void
    {
        $rental = Rental::factory()->held()->create([
            'organization_id' => $this->org->id,
            'service_id' => $this->rentalService->id,
        ]);

        $session = [
            "rental_booking.{$this->rentalService->id}.rental_id" => $rental->id,
            "rental_booking.{$this->rentalService->id}.step1" => [
                'start_date' => '2027-07-01',
                'end_date' => '2027-07-03',
                'quantity' => 1,
            ],
            "rental_booking.{$this->rentalService->id}.step2" => [
                'first_name' => 'Jan',
                'last_name' => 'Kowalski',
                'email' => 'jan@example.com',
                'phone' => '500123456',
            ],
        ];

        $this->withSession($session)
            ->get(route('rental.step3', $this->rentalService))
            ->assertOk()
            ->assertViewIs('rental.step3')
            ->assertViewHas('durationDays', 3);
    }

    public function test_step3_redirects_to_step1_when_session_incomplete(): void
    {
        $rental = Rental::factory()->held()->create([
            'organization_id' => $this->org->id,
            'service_id' => $this->rentalService->id,
        ]);

        // Missing step2 data
        $this->withSession([
            "rental_booking.{$this->rentalService->id}.rental_id" => $rental->id,
            "rental_booking.{$this->rentalService->id}.step1" => [
                'start_date' => '2027-07-01',
                'end_date' => '2027-07-03',
                'quantity' => 1,
            ],
        ])
            ->get(route('rental.step3', $this->rentalService))
            ->assertRedirect(route('rental.step1', $this->rentalService));
    }

    // ─────────────────────────────────────────────
    // confirm (POST)
    // ─────────────────────────────────────────────

    public function test_confirm_transitions_held_to_pending_and_redirects(): void
    {
        $rental = Rental::factory()->held()->create([
            'organization_id' => $this->org->id,
            'service_id' => $this->rentalService->id,
        ]);

        $session = [
            "rental_booking.{$this->rentalService->id}.rental_id" => $rental->id,
            "rental_booking.{$this->rentalService->id}.step2" => [
                'first_name' => 'Jan',
                'last_name' => 'Kowalski',
                'email' => 'jan@example.com',
                'phone' => '500123456',
            ],
        ];

        $this->withSession($session)
            ->post(route('rental.confirm', $this->rentalService))
            ->assertRedirect(route('rental.confirmation', $this->rentalService));

        $this->assertDatabaseHas('rentals', [
            'id' => $rental->id,
            'status' => 'pending',
            'first_name' => 'Jan',
            'email' => 'jan@example.com',
        ]);
    }

    public function test_confirm_clears_session_after_successful_confirmation(): void
    {
        $rental = Rental::factory()->held()->create([
            'organization_id' => $this->org->id,
            'service_id' => $this->rentalService->id,
        ]);

        $sessionKey = "rental_booking.{$this->rentalService->id}";

        $this->withSession([
            "{$sessionKey}.rental_id" => $rental->id,
            "{$sessionKey}.step1" => ['start_date' => '2027-07-01', 'end_date' => '2027-07-03', 'quantity' => 1],
            "{$sessionKey}.step2" => ['first_name' => 'Jan', 'last_name' => 'Kowalski', 'email' => 'jan@example.com', 'phone' => '500123456'],
        ])
            ->post(route('rental.confirm', $this->rentalService))
            ->assertRedirect();

        $this->assertNull(session($sessionKey));
    }

    public function test_confirm_redirects_to_step1_with_error_when_hold_expired(): void
    {
        $rental = Rental::factory()->held()->create([
            'organization_id' => $this->org->id,
            'service_id' => $this->rentalService->id,
            'held_until' => now()->subMinute(),
        ]);

        $session = [
            "rental_booking.{$this->rentalService->id}.rental_id" => $rental->id,
            "rental_booking.{$this->rentalService->id}.step2" => [
                'first_name' => 'Jan',
                'last_name' => 'Kowalski',
                'email' => 'jan@example.com',
                'phone' => '500123456',
            ],
        ];

        $this->withSession($session)
            ->post(route('rental.confirm', $this->rentalService))
            ->assertRedirect(route('rental.step1', $this->rentalService))
            ->assertSessionHas('error');
    }

    public function test_confirm_redirects_to_step1_when_step2_missing_from_session(): void
    {
        $rental = Rental::factory()->held()->create([
            'organization_id' => $this->org->id,
            'service_id' => $this->rentalService->id,
        ]);

        $this->withSession(["rental_booking.{$this->rentalService->id}.rental_id" => $rental->id])
            ->post(route('rental.confirm', $this->rentalService))
            ->assertRedirect(route('rental.step1', $this->rentalService));
    }

    // ─────────────────────────────────────────────
    // showConfirmation (GET)
    // ─────────────────────────────────────────────

    public function test_confirmation_page_renders_with_flashed_rental(): void
    {
        $rental = Rental::factory()->create([
            'organization_id' => $this->org->id,
            'service_id' => $this->rentalService->id,
            'status' => RentalStatus::Pending,
        ]);

        $this->withSession(['rental_id' => $rental->id])
            ->get(route('rental.confirmation', $this->rentalService))
            ->assertOk()
            ->assertViewIs('rental.confirmation')
            ->assertViewHas('rental', fn ($r) => $r->id === $rental->id);
    }

    public function test_confirmation_page_renders_without_rental_id_in_session(): void
    {
        $this->get(route('rental.confirmation', $this->rentalService))
            ->assertOk()
            ->assertViewHas('rental', null);
    }

    // ─────────────────────────────────────────────
    // checkAvailability (JSON endpoint)
    // ─────────────────────────────────────────────

    public function test_availability_endpoint_returns_json(): void
    {
        $this->getJson(route('rental.availability', $this->rentalService).'?start_date=2027-07-01&end_date=2027-07-03')
            ->assertOk()
            ->assertJsonStructure(['available_quantity', 'total_quantity'])
            ->assertJson([
                'available_quantity' => 5,
                'total_quantity' => 5,
            ]);
    }

    public function test_availability_endpoint_reflects_existing_booking(): void
    {
        Rental::factory()->create([
            'organization_id' => $this->org->id,
            'service_id' => $this->rentalService->id,
            'quantity' => 3,
            'start_date' => '2027-07-01',
            'end_date' => '2027-07-03',
            'status' => RentalStatus::Confirmed,
        ]);

        $this->getJson(route('rental.availability', $this->rentalService).'?start_date=2027-07-01&end_date=2027-07-03')
            ->assertOk()
            ->assertJson(['available_quantity' => 2]);
    }

    public function test_availability_endpoint_validates_dates(): void
    {
        $this->getJson(route('rental.availability', $this->rentalService).'?start_date=2027-07-05&end_date=2027-07-01')
            ->assertUnprocessable();
    }

    public function test_availability_endpoint_returns_404_for_timeslot_service(): void
    {
        $timeSlotService = Service::factory()->create([
            'organization_id' => $this->org->id,
            'service_type' => ServiceType::TimeSlot,
            'is_active' => true,
        ]);

        $this->getJson(route('rental.availability', $timeSlotService).'?start_date=2027-07-01&end_date=2027-07-03')
            ->assertNotFound();
    }

    // ─────────────────────────────────────────────
    // monthlyAvailability (JSON endpoint)
    // ─────────────────────────────────────────────

    public function test_calendar_endpoint_returns_monthly_data(): void
    {
        $response = $this->getJson(route('rental.calendar', $this->rentalService).'?year=2027&month=7');

        $response->assertOk();
        $data = $response->json();

        $this->assertCount(31, $data); // July has 31 days
        $this->assertArrayHasKey('2027-07-01', $data);
        $this->assertArrayHasKey('available_quantity', $data['2027-07-01']);
        $this->assertArrayHasKey('status', $data['2027-07-01']);
    }

    public function test_calendar_endpoint_validates_year_range(): void
    {
        $this->getJson(route('rental.calendar', $this->rentalService).'?year=2000&month=1')
            ->assertUnprocessable();
    }

    public function test_calendar_endpoint_validates_month_range(): void
    {
        $this->getJson(route('rental.calendar', $this->rentalService).'?year=2027&month=13')
            ->assertUnprocessable();
    }
}

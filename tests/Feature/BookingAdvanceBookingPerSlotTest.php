<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\ResolveTenant;
use App\Models\Organization;
use App\Models\Service;
use App\Models\StaffSchedule;
use App\Models\User;
use App\Services\AppointmentService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression test for HIGH finding #3 (2026-07 booking integrity review):
 * the 24h advance-booking rule used to be checked against the day's
 * business-hours-open instant (e.g. 09:00) instead of each candidate slot's
 * own start time. Browsing during business hours today for tomorrow rejected
 * the ENTIRE next day even though slots later that day were legitimately
 * ≥24h out and should have been bookable. Fixed in
 * AppointmentService::getAvailableSlotsAcrossAllStaff() (single-day slots
 * endpoint) and ::calculateAvailableSlotsForDay() (calendar/bulk endpoint).
 */
class BookingAdvanceBookingPerSlotTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $org;

    protected Service $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->autoDetailing()->create();
        $this->app['request']->attributes->set('tenant', $this->org);
        $this->actingAsTenant($this->org);

        $this->service = Service::factory()->create([
            'organization_id' => $this->org->id,
            'duration_minutes' => 60,
        ]);

        $staff = User::factory()->create();
        $staff->assignRole('staff');
        for ($day = Carbon::MONDAY; $day <= Carbon::FRIDAY; $day++) {
            StaffSchedule::create([
                'user_id' => $staff->id,
                'day_of_week' => $day,
                'start_time' => '09:00:00',
                'end_time' => '18:00:00',
                'is_active' => true,
            ]);
        }
        $staff->services()->attach($this->service->id);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function actingAsTenant(Organization $org): static
    {
        $this->app->bind(ResolveTenant::class, function () use ($org) {
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

    public function test_tomorrow_afternoon_slots_are_returned_when_browsing_during_business_hours_today(): void
    {
        // Monday 14:00 — business hours (09:00-18:00), browsing for tomorrow.
        Carbon::setTestNow(Carbon::parse('2026-07-06 14:00:00'));

        $tomorrow = Carbon::parse('2026-07-07'); // Tuesday, a working day
        $minimumBookingDateTime = now()->addHours(24); // 2026-07-07 14:00:00

        $response = $this->actingAs(User::factory()->create())
            ->getJson(route('booking.slots', [
                'service_id' => $this->service->id,
                'date' => $tomorrow->format('Y-m-d'),
            ]));

        $response->assertOk();
        $times = collect($response->json('slots'))->pluck('time');

        // Old bug: gating on the day's business-hours-open instant (09:00,
        // which is < the 24h minimum) rejected the WHOLE day — slots would be
        // empty here. New behavior: only slots before the 24h cutoff are
        // excluded; everything at/after it is legitimately bookable.
        $this->assertTrue($times->isNotEmpty(), 'Expected tomorrow afternoon slots to be returned, got none.');
        $this->assertTrue($times->contains('09:00') === false, 'Slot 09:00 is < 24h out and must be excluded.');
        $this->assertTrue(
            $times->contains(fn ($time) => Carbon::parse($tomorrow->format('Y-m-d').' '.$time)->gte($minimumBookingDateTime)),
            'Expected at least one afternoon slot that meets the 24h advance-booking requirement.'
        );
    }

    public function test_calculate_available_slots_for_day_also_applies_the_per_slot_advance_booking_rule(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 14:00:00'));

        $tomorrow = Carbon::parse('2026-07-07');

        /** @var AppointmentService $appointmentService */
        $appointmentService = app(AppointmentService::class);

        $availability = $appointmentService->getBulkAvailability(
            $this->service->id,
            $tomorrow,
            $tomorrow->copy()
        );

        // Old bug: this would be 'unavailable' (day-level gate rejected the
        // whole day). New behavior: afternoon slots are still bookable.
        $this->assertNotSame('unavailable', $availability[$tomorrow->format('Y-m-d')]);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Organization;
use App\Models\Service;
use App\Models\User;
use App\Models\VehicleType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Pins a real bug found while auditing tenant-branding.md's logo fix:
 * booking-wizard/confirmation.blade.php had its own hardcoded 3rd-tier
 * fallback checklist ("Usuń wartościowe przedmioty z wnętrza auta" — a
 * mobile car-wash prep list), independent of the seeded Setting row this
 * project also removed. Even with the DB row gone, this blade file would
 * have kept showing the exact same car-specific copy to any tenant that
 * enables time_slot bookings (this wizard is unreachable by equipment
 * rental, the only tenant type live today, but not by any other tenants —
 * see app/docs/features/tenant-branding.md).
 *
 * Setup mirrors BookingConfirmationSecurityMinimalTest's working recipe —
 * see that file's docblock for why actingAsTenant()/session token/etc. are
 * shaped this way.
 */
class BookingWizardChecklistNoForeignCopyTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_confirmation_page_shows_no_checklist_when_none_configured(): void
    {
        $org = Organization::factory()->autoDetailing()->create();
        $this->app['request']->attributes->set('tenant', $org);

        $this->artisan('db:seed', ['--class' => 'ServiceSeeder']);
        $this->artisan('db:seed', ['--class' => 'VehicleTypeSeeder']);

        $user = User::factory()->create();
        $staff = User::factory()->create();
        $staff->assignRole(Role::firstOrCreate(['name' => 'staff', 'guard_name' => 'web']));

        $appointment = Appointment::create([
            'organization_id' => $org->id,
            'customer_id' => $user->id,
            'service_id' => Service::first()->id,
            'staff_id' => $staff->id,
            'appointment_date' => now()->addDays(2)->format('Y-m-d'),
            'start_time' => '10:00:00',
            'end_time' => '12:00:00',
            'status' => AppointmentStatus::Pending,
            'vehicle_type_id' => VehicleType::first()->id,
            'location_address' => 'Test Address 123',
            'location_latitude' => 52.2297,
            'location_longitude' => 21.0122,
            'first_name' => 'Jan',
            'last_name' => 'Kowalski',
            'email' => 'jan@example.com',
            'phone' => '+48123456789',
        ]);

        session(['booking_confirmed_id' => $appointment->id]);

        $response = $this->actingAs($user)
            ->actingAsTenant($org)
            ->get(route('booking.confirmation'));

        $response->assertOk();
        $response->assertDontSee('Przed Wizytą');
        $response->assertDontSee('samochód', false);
        $response->assertDontSee('wnętrza auta', false);
    }
}

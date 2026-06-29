<?php

namespace Tests\Unit\Services;

use App\Enums\AppointmentStatus;
use App\Enums\RentalStatus;
use App\Models\Appointment;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Rental;
use App\Models\Service;
use App\Models\User;
use App\Models\VehicleType;
use App\Services\TenantObligationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TenantObligationServiceTest extends TestCase
{
    use RefreshDatabase;

    private TenantObligationService $service;

    private Organization $org;

    private VehicleType $vehicleType;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(TenantObligationService::class);
        $this->org = Organization::factory()->create();
        $this->vehicleType = VehicleType::factory()->create();
        Role::firstOrCreate(['name' => 'staff', 'guard_name' => 'web']);
    }

    private function createStaff(): User
    {
        $user = User::factory()->create();
        $user->assignRole('staff');

        return $user;
    }

    private function makeAppointment(array $attributes): Appointment
    {
        return Appointment::factory()->create(
            array_merge(['vehicle_type_id' => $this->vehicleType->id], $attributes)
        );
    }

    // ─── Appointments ──────────────────────────────────────────────────────

    public function test_counts_active_appointments(): void
    {
        $staff = $this->createStaff();

        $this->makeAppointment([
            'organization_id' => $this->org->id,
            'staff_id' => $staff->id,
            'status' => AppointmentStatus::Pending,
        ]);
        $this->makeAppointment([
            'organization_id' => $this->org->id,
            'staff_id' => $staff->id,
            'status' => AppointmentStatus::Confirmed,
        ]);

        $counts = $this->service->activeObligations($this->org);

        $this->assertSame(2, $counts['appointments']);
    }

    public function test_ignores_terminal_appointments(): void
    {
        $staff = $this->createStaff();

        $this->makeAppointment([
            'organization_id' => $this->org->id,
            'staff_id' => $staff->id,
            'status' => AppointmentStatus::Cancelled,
        ]);
        $this->makeAppointment([
            'organization_id' => $this->org->id,
            'staff_id' => $staff->id,
            'status' => AppointmentStatus::Completed,
        ]);

        $counts = $this->service->activeObligations($this->org);

        $this->assertSame(0, $counts['appointments']);
    }

    // ─── Orders ────────────────────────────────────────────────────────────

    public function test_counts_non_terminal_orders(): void
    {
        Order::factory()->create(['organization_id' => $this->org->id, 'status' => 'pending_payment']);
        Order::factory()->create(['organization_id' => $this->org->id, 'status' => 'paid']);
        Order::factory()->create(['organization_id' => $this->org->id, 'status' => 'confirmed']);
        Order::factory()->create(['organization_id' => $this->org->id, 'status' => 'in_progress']);

        $counts = $this->service->activeObligations($this->org);

        $this->assertSame(4, $counts['orders']);
    }

    public function test_ignores_terminal_orders(): void
    {
        Order::factory()->create(['organization_id' => $this->org->id, 'status' => 'cancelled']);
        Order::factory()->create(['organization_id' => $this->org->id, 'status' => 'refunded']);

        $counts = $this->service->activeObligations($this->org);

        $this->assertSame(0, $counts['orders']);
    }

    // ─── Rentals ───────────────────────────────────────────────────────────

    public function test_counts_blocking_rentals(): void
    {
        $service = Service::factory()->itemRental()->create(['organization_id' => $this->org->id]);
        $customer = User::factory()->create();

        foreach ([RentalStatus::Held, RentalStatus::Pending, RentalStatus::Confirmed, RentalStatus::Active] as $status) {
            Rental::factory()->create([
                'organization_id' => $this->org->id,
                'service_id' => $service->id,
                'customer_id' => $customer->id,
                'status' => $status,
            ]);
        }

        $counts = $this->service->activeObligations($this->org);

        $this->assertSame(4, $counts['rentals']);
    }

    public function test_ignores_non_blocking_rentals(): void
    {
        $service = Service::factory()->itemRental()->create(['organization_id' => $this->org->id]);
        $customer = User::factory()->create();

        foreach ([RentalStatus::Returned, RentalStatus::Cancelled, RentalStatus::Expired] as $status) {
            Rental::factory()->create([
                'organization_id' => $this->org->id,
                'service_id' => $service->id,
                'customer_id' => $customer->id,
                'status' => $status,
            ]);
        }

        $counts = $this->service->activeObligations($this->org);

        $this->assertSame(0, $counts['rentals']);
    }

    // ─── Total ─────────────────────────────────────────────────────────────

    public function test_total_is_sum_of_all_obligations(): void
    {
        $staff = $this->createStaff();
        $rentalService = Service::factory()->itemRental()->create(['organization_id' => $this->org->id]);
        $customer = User::factory()->create();

        $this->makeAppointment(['organization_id' => $this->org->id, 'staff_id' => $staff->id, 'status' => AppointmentStatus::Pending]);
        Order::factory()->create(['organization_id' => $this->org->id, 'status' => 'paid']);
        Rental::factory()->create(['organization_id' => $this->org->id, 'service_id' => $rentalService->id, 'customer_id' => $customer->id, 'status' => RentalStatus::Active]);

        $counts = $this->service->activeObligations($this->org);

        $this->assertSame(1, $counts['appointments']);
        $this->assertSame(1, $counts['orders']);
        $this->assertSame(1, $counts['rentals']);
        $this->assertSame(3, $counts['total']);
    }

    public function test_has_active_obligations_returns_true_when_any_exist(): void
    {
        $staff = $this->createStaff();
        $this->makeAppointment(['organization_id' => $this->org->id, 'staff_id' => $staff->id, 'status' => AppointmentStatus::Pending]);

        $this->assertTrue($this->service->hasActiveObligations($this->org));
    }

    public function test_has_active_obligations_returns_false_when_none(): void
    {
        $this->assertFalse($this->service->hasActiveObligations($this->org));
    }

    public function test_counts_only_obligations_for_given_org(): void
    {
        $otherOrg = Organization::factory()->create();
        $staff = $this->createStaff();

        // Obligation belongs to other org — must not count for $this->org
        $this->makeAppointment([
            'organization_id' => $otherOrg->id,
            'staff_id' => $staff->id,
            'status' => AppointmentStatus::Confirmed,
        ]);

        $counts = $this->service->activeObligations($this->org);

        $this->assertSame(0, $counts['total']);
    }
}

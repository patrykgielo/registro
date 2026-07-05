<?php

declare(strict_types=1);

namespace Tests\Feature\Offboarding;

use App\Enums\AppointmentStatus;
use App\Enums\RentalStatus;
use App\Jobs\CancelInFlightObligationsJob;
use App\Models\Appointment;
use App\Models\Organization;
use App\Models\Rental;
use App\Models\Service;
use App\Models\User;
use App\Models\VehicleType;
use App\Notifications\AppointmentCancelledNotification;
use App\Notifications\OrderCancelledNotification;
use App\Notifications\RentalCancelledNotification;
use App\Services\Order\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CancelInFlightObligationsJobTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $customer;

    private User $staff;

    private VehicleType $vehicleType;

    protected function setUp(): void
    {
        parent::setUp();
        $staffRole = Role::firstOrCreate(['name' => 'staff', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);

        // Fake all notifications globally — rental cancellations trigger RentalCancelledNotification
        // which uses EmailServiceChannel (DB templates). Without fake, tests that only check
        // status changes would throw "template not found" because there are no seeded templates
        // in the SQLite in-memory test DB.
        \Illuminate\Support\Facades\Notification::fake();

        $owner = User::factory()->create();
        $this->org = Organization::factory()->create(['owner_id' => $owner->id]);
        $this->customer = User::factory()->create();
        $this->staff = User::factory()->create();
        $this->staff->assignRole($staffRole);
        $this->vehicleType = VehicleType::factory()->create();
    }

    private function runJob(string $reason = 'Zamknięcie działalności'): void
    {
        $job = new CancelInFlightObligationsJob($this->org->id, $reason);
        $job->handle(app(OrderService::class));
    }

    private function makeOrder(string $status = 'pending_payment'): \App\Models\Order
    {
        return \App\Models\Order::factory()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->customer->id,
            'status' => $status,
        ]);
    }

    private function makeAppointment(AppointmentStatus $status = AppointmentStatus::Pending): Appointment
    {
        $service = Service::factory()->create(['organization_id' => $this->org->id]);

        return Appointment::factory()->create([
            'organization_id' => $this->org->id,
            'customer_id' => $this->customer->id,
            'service_id' => $service->id,
            'staff_id' => $this->staff->id,
            'vehicle_type_id' => $this->vehicleType->id,
            'status' => $status,
        ]);
    }

    private function makeRental(RentalStatus $status = RentalStatus::Pending, float $deposit = 0): Rental
    {
        return Rental::factory()->create([
            'organization_id' => $this->org->id,
            'customer_id' => $this->customer->id,
            'status' => $status,
            'deposit_amount' => $deposit,
        ]);
    }

    // ─── Orders ───────────────────────────────────────────────────────────────

    public function test_pending_payment_order_cancelled(): void
    {
        $order = $this->makeOrder('pending_payment');

        $this->runJob();

        $this->assertSame('cancelled', $order->fresh()->status);
    }

    public function test_paid_order_cancelled(): void
    {
        $order = $this->makeOrder('paid');

        $this->runJob();

        $this->assertSame('cancelled', $order->fresh()->status);
    }

    public function test_confirmed_order_cancelled(): void
    {
        $order = $this->makeOrder('confirmed');

        $this->runJob();

        $this->assertSame('cancelled', $order->fresh()->status);
    }

    public function test_in_progress_order_cancelled(): void
    {
        $order = $this->makeOrder('in_progress');

        $this->runJob();

        $this->assertSame('cancelled', $order->fresh()->status);
    }

    public function test_order_cancelled_notification_sent(): void
    {
        Notification::fake();
        $this->makeOrder('pending_payment');

        $this->runJob();

        Notification::assertSentTo($this->customer, OrderCancelledNotification::class);
    }

    public function test_completed_order_not_cancelled(): void
    {
        $order = $this->makeOrder('completed');

        $this->runJob();

        $this->assertSame('completed', $order->fresh()->status);
    }

    public function test_already_cancelled_order_not_touched(): void
    {
        $order = $this->makeOrder('cancelled');

        $this->runJob();

        $this->assertSame('cancelled', $order->fresh()->status);
        // No exception thrown — idempotent
    }

    // ─── Appointments ─────────────────────────────────────────────────────────

    public function test_pending_appointment_cancelled(): void
    {
        $appointment = $this->makeAppointment(AppointmentStatus::Pending);

        $this->runJob();

        $this->assertSame(AppointmentStatus::Cancelled, $appointment->fresh()->status);
    }

    public function test_confirmed_appointment_cancelled(): void
    {
        $appointment = $this->makeAppointment(AppointmentStatus::Confirmed);

        $this->runJob();

        $this->assertSame(AppointmentStatus::Cancelled, $appointment->fresh()->status);
    }

    public function test_appointment_cancellation_reason_stored(): void
    {
        $appointment = $this->makeAppointment(AppointmentStatus::Pending);

        $this->runJob('Zamknięcie działalności');

        $this->assertSame('Zamknięcie działalności', $appointment->fresh()->cancellation_reason);
    }

    public function test_appointment_cancelled_notification_sent(): void
    {
        Notification::fake();
        $this->makeAppointment(AppointmentStatus::Pending);

        $this->runJob();

        Notification::assertSentTo($this->customer, AppointmentCancelledNotification::class);
    }

    public function test_completed_appointment_not_cancelled(): void
    {
        $appointment = $this->makeAppointment(AppointmentStatus::Completed);

        $this->runJob();

        $this->assertSame(AppointmentStatus::Completed, $appointment->fresh()->status);
    }

    // ─── Rentals ──────────────────────────────────────────────────────────────

    public function test_pending_rental_cancelled(): void
    {
        $rental = $this->makeRental(RentalStatus::Pending);

        $this->runJob();

        $this->assertSame(RentalStatus::Cancelled, $rental->fresh()->status);
    }

    public function test_confirmed_rental_cancelled(): void
    {
        $rental = $this->makeRental(RentalStatus::Confirmed);

        $this->runJob();

        $this->assertSame(RentalStatus::Cancelled, $rental->fresh()->status);
    }

    public function test_active_rental_cancelled(): void
    {
        $rental = $this->makeRental(RentalStatus::Active);

        $this->runJob();

        $this->assertSame(RentalStatus::Cancelled, $rental->fresh()->status);
    }

    public function test_held_rental_cancelled(): void
    {
        $rental = $this->makeRental(RentalStatus::Held);

        $this->runJob();

        $this->assertSame(RentalStatus::Cancelled, $rental->fresh()->status);
    }

    public function test_rental_cancelled_notification_sent(): void
    {
        Notification::fake();
        $this->makeRental(RentalStatus::Pending);

        $this->runJob();

        Notification::assertSentTo($this->customer, RentalCancelledNotification::class);
    }

    public function test_returned_rental_not_cancelled(): void
    {
        $rental = $this->makeRental(RentalStatus::Returned);

        $this->runJob();

        $this->assertSame(RentalStatus::Returned, $rental->fresh()->status);
    }

    public function test_already_cancelled_rental_not_touched(): void
    {
        $rental = $this->makeRental(RentalStatus::Cancelled);

        $this->runJob();

        // Already cancelled — not re-processed (not in whereIn filter)
        $this->assertSame(RentalStatus::Cancelled, $rental->fresh()->status);
    }

    // ─── Cross-org isolation ──────────────────────────────────────────────────

    public function test_other_org_obligations_not_touched(): void
    {
        $otherOrg = Organization::factory()->create();
        $otherCustomer = User::factory()->create();
        $otherOrder = \App\Models\Order::factory()->create([
            'organization_id' => $otherOrg->id,
            'user_id' => $otherCustomer->id,
            'status' => 'pending_payment',
        ]);
        $otherRental = Rental::factory()->create([
            'organization_id' => $otherOrg->id,
            'customer_id' => $otherCustomer->id,
            'status' => RentalStatus::Pending,
        ]);

        // Run job only for $this->org
        $this->runJob();

        $this->assertSame('pending_payment', $otherOrder->fresh()->status);
        $this->assertSame(RentalStatus::Pending, $otherRental->fresh()->status);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Rental;

use App\Enums\RentalStatus;
use App\Events\RentalCancelled;
use App\Models\Organization;
use App\Models\Rental;
use App\Models\User;
use App\Notifications\RentalCancelledNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RentalCancelledTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);
    }

    public function test_rental_cancelled_event_dispatched_when_status_changes_to_cancelled(): void
    {
        Event::fake([RentalCancelled::class]);

        $org = Organization::factory()->create();
        $customer = User::factory()->create();
        $rental = Rental::factory()->create([
            'organization_id' => $org->id,
            'customer_id' => $customer->id,
            'status' => RentalStatus::Pending,
        ]);

        $rental->status = RentalStatus::Cancelled;
        $rental->save();

        Event::assertDispatched(RentalCancelled::class, function (RentalCancelled $event) use ($rental): bool {
            return $event->rental->id === $rental->id;
        });
    }

    public function test_cancelled_at_set_when_status_changes_to_cancelled(): void
    {
        Notification::fake();

        $org = Organization::factory()->create();
        $customer = User::factory()->create();
        $rental = Rental::factory()->create([
            'organization_id' => $org->id,
            'customer_id' => $customer->id,
            'status' => RentalStatus::Pending,
        ]);

        $rental->status = RentalStatus::Cancelled;
        $rental->save();

        $this->assertNotNull($rental->fresh()->cancelled_at);
    }

    public function test_event_not_dispatched_for_non_cancelled_status_change(): void
    {
        Event::fake([RentalCancelled::class]);

        $org = Organization::factory()->create();
        $customer = User::factory()->create();
        $rental = Rental::factory()->create([
            'organization_id' => $org->id,
            'customer_id' => $customer->id,
            'status' => RentalStatus::Pending,
        ]);

        $rental->status = RentalStatus::Confirmed;
        $rental->save();

        Event::assertNotDispatched(RentalCancelled::class);
    }

    public function test_rental_cancelled_notification_sent_to_customer(): void
    {
        Notification::fake();

        $org = Organization::factory()->create();
        $customer = User::factory()->create();
        $rental = Rental::factory()->create([
            'organization_id' => $org->id,
            'customer_id' => $customer->id,
            'status' => RentalStatus::Pending,
        ]);

        $rental->status = RentalStatus::Cancelled;
        $rental->save();

        Notification::assertSentTo($customer, RentalCancelledNotification::class);
    }

    public function test_no_notification_when_rental_has_no_customer(): void
    {
        Notification::fake();

        $org = Organization::factory()->create();
        $customer = User::factory()->create();
        $rental = Rental::factory()->create([
            'organization_id' => $org->id,
            'customer_id' => $customer->id,
            'status' => RentalStatus::Pending,
        ]);

        // Seed null into the already-loaded relationship cache without touching the DB column
        // (NOT NULL constraint). The listener uses loadMissing('customer'), so it sees the
        // pre-loaded null and skips the notification — exercises the defensive branch.
        $rental->setRelation('customer', null);

        $rental->status = RentalStatus::Cancelled;
        $rental->save();

        Notification::assertNothingSent();
    }
}

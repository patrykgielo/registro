<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs\Reminder;

use App\Jobs\Reminder\ProcessRentalReturnRemindersJob;
use App\Models\EmailSend;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Organization;
use App\Notifications\RentalReturnDueSoonNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Pins ProcessRentalReturnRemindersJob's two reminder types end-to-end.
 *
 * Idempotency tests deliberately do NOT use Notification::fake() — dedup
 * lives entirely in EmailService's `message_key` UNIQUE constraint (see
 * notifications.md's "Idempotencja EmailService" section and both
 * notification classes' own docblocks), a layer Notification::fake()
 * bypasses completely. The job itself re-matches the same OrderItem on
 * every run; only EmailService decides whether a second send actually goes
 * out. QUEUE_CONNECTION=sync in .env.testing means notify() runs the real
 * pipeline synchronously (EmailServiceChannel -> EmailService ->
 * FakeEmailGateway, auto-bound in the testing environment — see
 * AppServiceProvider), so counting `email_sends` rows proves the real
 * behaviour, not a mock's opinion of it.
 *
 * Tenant-scoping and pure routing tests use Notification::fake() instead,
 * where the property under test is "who got called with what", not "did a
 * duplicate email actually go out" — fake() is the right tool there.
 */
class ProcessRentalReturnRemindersJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_item_due_tomorrow_gets_exactly_one_due_soon_email_and_rerun_does_not_send_a_second(): void
    {
        $order = Order::factory()->inProgress()->create();
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'start_date' => Carbon::today(),
            'end_date' => Carbon::tomorrow(),
        ]);

        app(ProcessRentalReturnRemindersJob::class)->handle();
        app(ProcessRentalReturnRemindersJob::class)->handle();

        $sends = EmailSend::where('template_key', 'rental-return-due-soon')
            ->where('recipient_email', $order->user->email)
            ->get();

        $this->assertCount(1, $sends, 'Running the job twice must not send the due-soon reminder twice.');
        $this->assertSame('sent', $sends->first()->status);
    }

    public function test_item_overdue_gets_exactly_one_overdue_email_and_rerun_does_not_send_a_second(): void
    {
        $order = Order::factory()->inProgress()->create();
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'start_date' => Carbon::today()->subDays(5),
            'end_date' => Carbon::today()->subDays(2),
        ]);

        app(ProcessRentalReturnRemindersJob::class)->handle();
        app(ProcessRentalReturnRemindersJob::class)->handle();

        $sends = EmailSend::where('template_key', 'rental-return-overdue')
            ->where('recipient_email', $order->user->email)
            ->get();

        $this->assertCount(1, $sends, 'Running the job twice must not send the overdue reminder twice.');
    }

    /**
     * The scope decision: an overdue notice fires once, ever, per item — not
     * daily for as long as the order stays in_progress. Simulates several
     * days passing (the job re-finding the same still-overdue item on each
     * one, exactly as it would in production) and asserts the email count
     * never grows past 1. See ProcessRentalReturnRemindersJob's own
     * docblock on processOverdue() for the reasoning.
     */
    public function test_overdue_reminder_does_not_repeat_on_subsequent_days(): void
    {
        $order = Order::factory()->inProgress()->create();
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'start_date' => Carbon::today()->subDays(3),
            'end_date' => Carbon::today()->subDay(),
        ]);

        app(ProcessRentalReturnRemindersJob::class)->handle();

        Carbon::setTestNow(Carbon::now()->addDays(3));
        app(ProcessRentalReturnRemindersJob::class)->handle();

        Carbon::setTestNow(Carbon::now()->addDays(7));
        app(ProcessRentalReturnRemindersJob::class)->handle();

        Carbon::setTestNow();

        $count = EmailSend::where('template_key', 'rental-return-overdue')
            ->where('recipient_email', $order->user->email)
            ->count();

        $this->assertSame(1, $count, 'An overdue notice must fire once ever per item, not once per day it stays overdue.');
    }

    public function test_completed_order_gets_no_reminder(): void
    {
        $order = Order::factory()->completed()->create();
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'start_date' => Carbon::today()->subDays(3),
            'end_date' => Carbon::tomorrow(),
        ]);
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'start_date' => Carbon::today()->subDays(10),
            'end_date' => Carbon::today()->subDays(2),
        ]);

        app(ProcessRentalReturnRemindersJob::class)->handle();

        $this->assertSame(0, EmailSend::whereIn('template_key', ['rental-return-due-soon', 'rental-return-overdue'])->count());
    }

    public function test_cancelled_order_gets_no_reminder(): void
    {
        $order = Order::factory()->cancelled()->create();
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'start_date' => Carbon::today()->subDays(3),
            'end_date' => Carbon::tomorrow(),
        ]);
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'start_date' => Carbon::today()->subDays(10),
            'end_date' => Carbon::today()->subDays(2),
        ]);

        app(ProcessRentalReturnRemindersJob::class)->handle();

        $this->assertSame(0, EmailSend::whereIn('template_key', ['rental-return-due-soon', 'rental-return-overdue'])->count());
    }

    /**
     * The exact scenario this branch exists to close a gap on: an order
     * neither due tomorrow nor overdue (e.g. still 5 days out) gets nothing
     * from either reminder type.
     */
    public function test_item_not_yet_due_gets_no_reminder(): void
    {
        $order = Order::factory()->inProgress()->create();
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'start_date' => Carbon::today(),
            'end_date' => Carbon::today()->addDays(5),
        ]);

        app(ProcessRentalReturnRemindersJob::class)->handle();

        $this->assertSame(0, EmailSend::whereIn('template_key', ['rental-return-due-soon', 'rental-return-overdue'])->count());
    }

    /**
     * Cross-tenant regression pin: tenant A's rental reminder must never
     * reach tenant B's customer, and each customer's own notification must
     * carry their own order, not the other tenant's.
     */
    public function test_tenant_scoping_never_crosses_reminders_between_organizations(): void
    {
        Notification::fake();

        $orgA = Organization::factory()->create();
        $orderA = Order::factory()->inProgress()->for($orgA, 'organization')->create();
        OrderItem::factory()->create([
            'order_id' => $orderA->id,
            'start_date' => Carbon::today(),
            'end_date' => Carbon::tomorrow(),
        ]);

        $orgB = Organization::factory()->create();
        $orderB = Order::factory()->inProgress()->for($orgB, 'organization')->create();
        OrderItem::factory()->create([
            'order_id' => $orderB->id,
            'start_date' => Carbon::today(),
            'end_date' => Carbon::tomorrow(),
        ]);

        app(ProcessRentalReturnRemindersJob::class)->handle();

        Notification::assertSentTo(
            $orderA->user,
            RentalReturnDueSoonNotification::class,
            fn (RentalReturnDueSoonNotification $n): bool => $n->order->id === $orderA->id
        );
        Notification::assertSentTo(
            $orderB->user,
            RentalReturnDueSoonNotification::class,
            fn (RentalReturnDueSoonNotification $n): bool => $n->order->id === $orderB->id
        );

        // Neither customer ever receives a notification carrying the OTHER
        // tenant's order.
        Notification::assertNotSentTo(
            $orderA->user,
            RentalReturnDueSoonNotification::class,
            fn (RentalReturnDueSoonNotification $n): bool => $n->order->id === $orderB->id
        );
        Notification::assertNotSentTo(
            $orderB->user,
            RentalReturnDueSoonNotification::class,
            fn (RentalReturnDueSoonNotification $n): bool => $n->order->id === $orderA->id
        );
    }

    /**
     * A multi-item order sends one reminder per item, not one combined
     * email — see ProcessRentalReturnRemindersJob's own docblock for why
     * items, not orders, are the reminder unit.
     */
    public function test_two_items_on_the_same_order_due_tomorrow_each_get_their_own_reminder(): void
    {
        $order = Order::factory()->inProgress()->create();
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'start_date' => Carbon::today(),
            'end_date' => Carbon::tomorrow(),
        ]);
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'start_date' => Carbon::today(),
            'end_date' => Carbon::tomorrow(),
        ]);

        app(ProcessRentalReturnRemindersJob::class)->handle();

        $this->assertSame(
            2,
            EmailSend::where('template_key', 'rental-return-due-soon')->where('recipient_email', $order->user->email)->count()
        );
    }

    /**
     * Extending an item after its due-soon reminder was sent must earn the
     * new date its own reminder — the dedup key includes end_date precisely
     * so a stale reminder is never the last thing a customer heard about
     * this item. See RentalReturnDueSoonNotification's own docblock.
     */
    public function test_extending_an_item_after_its_due_soon_reminder_sends_a_fresh_one_for_the_new_date(): void
    {
        $order = Order::factory()->inProgress()->create();
        $item = OrderItem::factory()->create([
            'order_id' => $order->id,
            'start_date' => Carbon::today(),
            'end_date' => Carbon::tomorrow(),
        ]);

        app(ProcessRentalReturnRemindersJob::class)->handle();

        $newEndDate = Carbon::tomorrow()->addDays(3);
        $item->update(['end_date' => $newEndDate]);
        // Advance "today" to exactly one day before the new end_date, so it
        // falls back into the due-soon window under the new clock.
        Carbon::setTestNow($newEndDate->copy()->subDay());
        app(ProcessRentalReturnRemindersJob::class)->handle();
        Carbon::setTestNow();

        $count = EmailSend::where('template_key', 'rental-return-due-soon')
            ->where('recipient_email', $order->user->email)
            ->count();

        $this->assertSame(2, $count, 'A fresh due date after an extension must earn its own reminder.');
    }
}

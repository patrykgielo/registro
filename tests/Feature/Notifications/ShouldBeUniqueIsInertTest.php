<?php

namespace Tests\Feature\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Tests\TestCase;

class CountingChannel
{
    public static int $delivered = 0;

    public function send($notifiable, Notification $notification): void
    {
        self::$delivered++;
    }
}

class InertProbeNotification extends Notification implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public function __construct(public int $entityId) {}

    public function uniqueId(): string
    {
        return 'probe:'.$this->entityId;
    }

    public function via($notifiable): array
    {
        return [CountingChannel::class];
    }
}

/**
 * Pins a framework behaviour 23 notification classes in this repo silently depend on.
 *
 * `ShouldBeUnique` does nothing on a Notification in Laravel 12.60.2:
 * NotificationSender::queueNotification() calls Bus::dispatch() on a hand-built
 * SendQueuedNotifications, which implements only ShouldQueue, and UniqueLock is
 * acquired solely by PendingDispatch::__destruct() — the Job::dispatch() path
 * notifications never take.
 *
 * Every order, appointment and rental notification declares the interface and gets
 * nothing from it; the ones that send email are protected by EmailService's own
 * message_key deduplication instead, and the ones that do not are unprotected.
 *
 * This test exists so an upgrade that STARTS enforcing the interface fails loudly
 * here, rather than silently swallowing mail across the whole notification layer.
 * If it goes red, the fix is not to change this test — it is to audit every class
 * that declares ShouldBeUnique and decide what each one actually needs.
 *
 * Context: .claude/rules/notifications.md documented a "fan-out incident" caused by
 * this interface. It never happened; the mechanism it described is impossible here.
 */
class ShouldBeUniqueIsInertTest extends TestCase
{
    use RefreshDatabase;

    public function test_shouldbeunique_does_not_deduplicate_across_recipients(): void
    {
        config(['queue.default' => 'sync', 'cache.default' => 'array']);
        CountingChannel::$delivered = 0;

        $users = User::factory()->count(5)->create();

        NotificationFacade::send($users, new InertProbeNotification(42));

        $this->assertSame(
            5,
            CountingChannel::$delivered,
            'Laravel now enforces ShouldBeUnique on notifications. 23 classes in app/Notifications '
            .'declare it with recipient-agnostic uniqueId()s and have never been deduplicated. '
            .'Audit them before making this test pass.'
        );
    }

    public function test_shouldbeunique_does_not_deduplicate_repeated_sends(): void
    {
        config(['queue.default' => 'sync', 'cache.default' => 'array']);
        CountingChannel::$delivered = 0;

        $user = User::factory()->create();

        $user->notify(new InertProbeNotification(7));
        $user->notify(new InertProbeNotification(7));

        $this->assertSame(2, CountingChannel::$delivered);
    }
}

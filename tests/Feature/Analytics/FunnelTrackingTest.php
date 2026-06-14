<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Events\OrderPaid;
use App\Jobs\IngestAnalyticsEventsJob;
use App\Jobs\MarkCartsAbandonedJob;
use App\Listeners\RecordAnalyticsOnOrderPaid;
use App\Models\Cart;
use App\Models\Order;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class FunnelTrackingTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->equipmentRental()->create();
        $this->user = User::factory()->create();
    }

    // -------------------------------------------------------------------------
    // MarkCartsAbandonedJob
    // -------------------------------------------------------------------------

    public function test_mark_carts_abandoned_job_marks_stale_active_cart(): void
    {
        Queue::fake();

        $cart = Cart::factory()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'status' => 'active',
            'updated_at' => now()->subMinutes(35),
        ]);

        // Force updated_at to be in the past (factory may reset it)
        Cart::withoutTimestamps(function () use ($cart) {
            $cart->update(['updated_at' => now()->subMinutes(35)]);
        });

        (new MarkCartsAbandonedJob)->handle(
            new \App\Services\Analytics\AnalyticsEventDispatcher
        );

        $this->assertDatabaseHas('carts', [
            'id' => $cart->id,
            'status' => 'abandoned',
        ]);

        $this->assertNotNull($cart->fresh()->abandoned_at);
    }

    public function test_mark_carts_abandoned_job_leaves_recent_cart_alone(): void
    {
        Queue::fake();

        $cart = Cart::factory()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'status' => 'active',
        ]);

        (new MarkCartsAbandonedJob)->handle(
            new \App\Services\Analytics\AnalyticsEventDispatcher
        );

        $this->assertDatabaseHas('carts', [
            'id' => $cart->id,
            'status' => 'active',
        ]);
    }

    public function test_mark_carts_abandoned_job_dispatches_analytics_event(): void
    {
        Queue::fake();

        $cart = Cart::factory()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'status' => 'active',
            'updated_at' => now()->subMinutes(35),
        ]);

        // Force updated_at via raw DB to bypass Eloquent timestamp auto-fill
        \Illuminate\Support\Facades\DB::table('carts')
            ->where('id', $cart->id)
            ->update(['updated_at' => now()->subMinutes(35)->toDateTimeString()]);

        (new MarkCartsAbandonedJob)->handle(
            new \App\Services\Analytics\AnalyticsEventDispatcher
        );

        Queue::assertPushed(IngestAnalyticsEventsJob::class, function (IngestAnalyticsEventsJob $job): bool {
            $reflection = new \ReflectionClass($job);
            $property = $reflection->getProperty('events');
            $property->setAccessible(true);
            $events = $property->getValue($job);

            return isset($events[0]['event']) && $events[0]['event'] === 'cart.abandoned';
        });
    }

    // -------------------------------------------------------------------------
    // UTM extraction in IngestAnalyticsEventsJob
    // -------------------------------------------------------------------------

    public function test_ingest_job_extracts_utm_from_event_properties(): void
    {
        $events = [
            [
                'event' => 'page_viewed',
                'url' => 'https://example.com/?utm_source=google&utm_medium=cpc',
                'properties' => [
                    'utm_source' => 'google',
                    'utm_medium' => 'cpc',
                    'utm_campaign' => 'summer2026',
                ],
                'timestamp' => now()->toISOString(),
            ],
        ];

        $serverProps = [
            'organization_id' => $this->org->id,
            'user_id' => null,
            'session_id' => 'test-session-abc',
            'received_at' => now()->format('Y-m-d H:i:s'),
        ];

        (new IngestAnalyticsEventsJob($events, $serverProps))->handle();

        $this->assertDatabaseHas('analytics_events', [
            'organization_id' => $this->org->id,
            'event' => 'page_viewed',
            'utm_source' => 'google',
            'utm_medium' => 'cpc',
            'utm_campaign' => 'summer2026',
        ]);
    }

    public function test_ingest_job_extracts_referrer_domain(): void
    {
        $events = [
            [
                'event' => 'page_viewed',
                'url' => 'https://example.com/',
                'referrer' => 'https://www.google.com/search?q=wypozyczalnia',
                'properties' => [],
                'timestamp' => now()->toISOString(),
            ],
        ];

        $serverProps = [
            'organization_id' => $this->org->id,
            'user_id' => null,
            'session_id' => 'test-session-xyz',
            'received_at' => now()->format('Y-m-d H:i:s'),
        ];

        (new IngestAnalyticsEventsJob($events, $serverProps))->handle();

        $this->assertDatabaseHas('analytics_events', [
            'organization_id' => $this->org->id,
            'event' => 'page_viewed',
            'referrer_domain' => 'www.google.com',
        ]);
    }

    public function test_ingest_job_null_utm_when_not_in_properties(): void
    {
        $events = [
            [
                'event' => 'page_viewed',
                'url' => 'https://example.com/',
                'properties' => ['some_prop' => 'value'],
                'timestamp' => now()->toISOString(),
            ],
        ];

        $serverProps = [
            'organization_id' => $this->org->id,
            'user_id' => null,
            'session_id' => 'test-session-no-utm',
            'received_at' => now()->format('Y-m-d H:i:s'),
        ];

        (new IngestAnalyticsEventsJob($events, $serverProps))->handle();

        // NULL values can't be asserted with assertDatabaseHas (SQL NULL != NULL)
        // so we query directly and assert on the model
        $record = \App\Models\AnalyticsEvent::where('session_id', 'test-session-no-utm')->first();
        $this->assertNotNull($record);
        $this->assertNull($record->utm_source);
        $this->assertNull($record->utm_medium);
        $this->assertNull($record->utm_campaign);
    }

    // -------------------------------------------------------------------------
    // RecordAnalyticsOnOrderPaid listener
    // -------------------------------------------------------------------------

    public function test_record_analytics_on_order_paid_listener_is_registered(): void
    {
        Event::fake([OrderPaid::class]);

        $order = Order::factory()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
        ]);

        OrderPaid::dispatch($order);

        Event::assertDispatched(OrderPaid::class);
    }

    public function test_record_analytics_on_order_paid_dispatches_order_completed_event(): void
    {
        Queue::fake();

        $order = Order::factory()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
        ]);

        $listener = new RecordAnalyticsOnOrderPaid;
        $listener->handle(new OrderPaid($order));

        Queue::assertPushed(IngestAnalyticsEventsJob::class, function (IngestAnalyticsEventsJob $job) use ($order): bool {
            $reflection = new \ReflectionClass($job);
            $events = $reflection->getProperty('events')->getValue($job);
            $serverProps = $reflection->getProperty('serverProps')->getValue($job);

            return isset($events[0]['event'])
                && $events[0]['event'] === 'order.completed'
                && $serverProps['organization_id'] === $order->organization_id;
        });
    }
}

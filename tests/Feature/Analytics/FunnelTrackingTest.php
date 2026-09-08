<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Events\OrderPaid;
use App\Jobs\IngestAnalyticsEventsJob;
use App\Jobs\MarkCartsAbandonedJob;
use App\Listeners\RecordAnalyticsOnOrderPaid;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Organization;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    /**
     * item_count must be sum(quantity), not a row count — a CartItem row with
     * quantity=3 expands into 3 OrderItem rows of quantity 1 each at checkout
     * (Faza 3 krok 2), so counting rows here would disagree with
     * RecordAnalyticsOnOrderPaid's item_count for the same cart/order even
     * though nothing was added or removed.
     */
    public function test_mark_carts_abandoned_job_reports_sum_of_quantities_not_row_count(): void
    {
        Queue::fake();

        $cart = Cart::factory()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'status' => 'active',
        ]);

        $service = Service::factory()->itemRental()->create(['organization_id' => $this->org->id]);

        // One row, quantity 3 — sum(quantity) = 3, row count = 1.
        CartItem::factory()->create([
            'cart_id' => $cart->id,
            'service_id' => $service->id,
            'quantity' => 3,
        ]);

        \Illuminate\Support\Facades\DB::table('carts')
            ->where('id', $cart->id)
            ->update(['updated_at' => now()->subMinutes(35)->toDateTimeString()]);

        (new MarkCartsAbandonedJob)->handle(
            new \App\Services\Analytics\AnalyticsEventDispatcher
        );

        Queue::assertPushed(IngestAnalyticsEventsJob::class, function (IngestAnalyticsEventsJob $job): bool {
            $reflection = new \ReflectionClass($job);
            $events = $reflection->getProperty('events')->getValue($job);

            return isset($events[0]['properties']['item_count'])
                && $events[0]['properties']['item_count'] === 3;
        });
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

    public function test_record_analytics_on_order_paid_listener_is_queued_on_analytics_queue(): void
    {
        $listener = new RecordAnalyticsOnOrderPaid(new \App\Services\Analytics\AnalyticsEventDispatcher);

        $this->assertInstanceOf(\Illuminate\Contracts\Queue\ShouldQueue::class, $listener);
        $this->assertEquals('analytics', $listener->queue);
    }

    public function test_record_analytics_on_order_paid_dispatches_order_completed_event(): void
    {
        Queue::fake();

        $order = Order::factory()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
        ]);

        $listener = new RecordAnalyticsOnOrderPaid(new \App\Services\Analytics\AnalyticsEventDispatcher);
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

    /**
     * item_count must be sum(quantity), not ->items()->count() — see the
     * matching MarkCartsAbandonedJob test above for why row count would
     * disagree with 'checkout.started''s item_count for the same cart/order
     * since Faza 3 krok 2 (cart-quantity expansion into single-unit
     * OrderItems at checkout).
     */
    public function test_record_analytics_on_order_paid_reports_sum_of_quantities_not_row_count(): void
    {
        Queue::fake();

        $order = Order::factory()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
        ]);

        $service = Service::factory()->itemRental()->create(['organization_id' => $this->org->id]);

        // Two OrderItem rows of quantity 1 each — the shape produced by
        // CartService::convertToOrder() splitting one cart line of
        // quantity=2 — sum(quantity) = 2, row count also happens to be 2
        // here, so add a second row pair to make the two counting
        // strategies diverge and prove which one is actually used.
        OrderItem::factory()->count(2)->create([
            'order_id' => $order->id,
            'service_id' => $service->id,
            'quantity' => 1,
        ]);
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'service_id' => $service->id,
            'quantity' => 5,
        ]);

        $listener = new RecordAnalyticsOnOrderPaid(new \App\Services\Analytics\AnalyticsEventDispatcher);
        $listener->handle(new OrderPaid($order));

        Queue::assertPushed(IngestAnalyticsEventsJob::class, function (IngestAnalyticsEventsJob $job): bool {
            $reflection = new \ReflectionClass($job);
            $events = $reflection->getProperty('events')->getValue($job);

            // sum(quantity) = 1+1+5 = 7; row count would have been 3.
            return isset($events[0]['properties']['item_count'])
                && $events[0]['properties']['item_count'] === 7;
        });
    }
}

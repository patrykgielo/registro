<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Jobs\IngestAnalyticsEventsJob;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IngestAnalyticsEventsJobTest extends TestCase
{
    use RefreshDatabase;

    private array $serverProps;

    protected function setUp(): void
    {
        parent::setUp();

        $org = Organization::factory()->equipmentRental()->create();

        $this->serverProps = [
            'organization_id' => $org->id,
            'user_id' => null,
            'session_id' => 'test-session-001',
            'anonymous_id' => null,
            'browser' => null,
            'os' => null,
            'received_at' => now()->format('Y-m-d H:i:s'),
        ];
    }

    private function makeEvent(string $eventName, array $extra = []): array
    {
        return array_merge(['event' => $eventName, 'timestamp' => now()->toIso8601String()], $extra);
    }

    private function ingest(array $events): void
    {
        (new IngestAnalyticsEventsJob($events, $this->serverProps))->handle();
    }

    // ── Server-side funnel events ────────────────────────────────────────────

    public function test_cart_abandoned_event_is_persisted(): void
    {
        $this->ingest([$this->makeEvent('cart.abandoned', ['reason' => 'timeout'])]);

        $this->assertDatabaseHas('analytics_events', [
            'event' => 'cart.abandoned',
            'organization_id' => $this->serverProps['organization_id'],
        ]);
    }

    public function test_checkout_started_event_is_persisted(): void
    {
        $this->ingest([$this->makeEvent('checkout.started')]);

        $this->assertDatabaseHas('analytics_events', [
            'event' => 'checkout.started',
            'organization_id' => $this->serverProps['organization_id'],
        ]);
    }

    public function test_checkout_submitted_event_is_persisted(): void
    {
        $this->ingest([$this->makeEvent('checkout.submitted')]);

        $this->assertDatabaseHas('analytics_events', [
            'event' => 'checkout.submitted',
            'organization_id' => $this->serverProps['organization_id'],
        ]);
    }

    public function test_order_completed_event_is_persisted(): void
    {
        $this->ingest([$this->makeEvent('order.completed')]);

        $this->assertDatabaseHas('analytics_events', [
            'event' => 'order.completed',
            'organization_id' => $this->serverProps['organization_id'],
        ]);
    }

    public function test_unknown_event_is_silently_dropped(): void
    {
        $this->ingest([$this->makeEvent('hacker.evil')]);

        $this->assertDatabaseMissing('analytics_events', ['event' => 'hacker.evil']);
    }

    // ── GDPR: query-string stripping ─────────────────────────────────────────

    public function test_query_string_is_stripped_from_url(): void
    {
        $this->ingest([
            $this->makeEvent('page_viewed', [
                'url' => 'https://example.com/uslugi?token=abc&email=user@test.com',
            ]),
        ]);

        $this->assertDatabaseHas('analytics_events', [
            'event' => 'page_viewed',
            'url' => 'https://example.com/uslugi',
        ]);
    }

    public function test_query_string_is_stripped_from_referrer(): void
    {
        $this->ingest([
            $this->makeEvent('page_viewed', [
                'referrer' => 'https://google.com/search?q=test&email=foo@bar.com',
            ]),
        ]);

        $this->assertDatabaseHas('analytics_events', [
            'event' => 'page_viewed',
            'referrer' => 'https://google.com/search',
        ]);
    }

    // ── XSS: javascript: scheme blocked in job (second defence layer) ────────

    public function test_javascript_scheme_url_is_stored_as_null_in_job(): void
    {
        $this->ingest([
            $this->makeEvent('page_viewed', ['url' => 'javascript:alert(1)']),
        ]);

        // Event is still recorded (the job doesn't drop on bad URL alone),
        // but the url column must be NULL — never the raw javascript: string.
        $this->assertDatabaseHas('analytics_events', [
            'event' => 'page_viewed',
            'url' => null,
        ]);

        $this->assertDatabaseMissing('analytics_events', ['url' => 'javascript:alert(1)']);
    }
}

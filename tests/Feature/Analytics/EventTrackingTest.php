<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Jobs\IngestAnalyticsEventsJob;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class EventTrackingTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->equipmentRental()->create();
    }

    private function withTenant(Organization $org): static
    {
        $this->app->bind(\App\Http\Middleware\ResolveTenant::class, function () use ($org) {
            return new class($org)
            {
                public function __construct(private readonly Organization $org) {}

                public function handle($request, $next)
                {
                    $request->attributes->set('tenant', $this->org);

                    return $next($request);
                }
            };
        });

        return $this;
    }

    private function validPayload(int $count = 2): array
    {
        $events = [];
        for ($i = 0; $i < $count; $i++) {
            $events[] = [
                'event' => 'page_viewed',
                'session_id' => 'abc123',
                'url' => 'https://example.com/uslugi',
                'page_type' => 'service',
                'timestamp' => now()->subSeconds($i)->toIso8601String(),
                'properties' => ['source' => 'organic'],
            ];
        }

        return ['events' => $events];
    }

    public function test_guest_can_submit_valid_event_batch(): void
    {
        Queue::fake();

        $response = $this->withTenant($this->org)
            ->postJson('/api/track', $this->validPayload());

        $response->assertStatus(202)
            ->assertJson(['ok' => true]);
    }

    public function test_tenant_id_cannot_be_spoofed_from_client(): void
    {
        Queue::fake();

        $otherOrg = Organization::factory()->equipmentRental()->create();

        // Attacker injects organization_id into properties, hoping it gets used
        $payload = [
            'events' => [
                [
                    'event' => 'page_view',
                    'organization_id' => $otherOrg->id, // should be ignored
                    'properties' => ['organization_id' => $otherOrg->id],
                ],
            ],
        ];

        $this->withTenant($this->org)
            ->postJson('/api/track', $payload)
            ->assertStatus(202);

        Queue::assertPushed(IngestAnalyticsEventsJob::class, function (IngestAnalyticsEventsJob $job) use ($otherOrg): bool {
            // Inspect the job via reflection — serverProps must use tenant from request, not payload
            $reflection = new \ReflectionClass($job);
            $serverProps = $reflection->getProperty('serverProps')->getValue($job);

            return (int) $serverProps['organization_id'] === $this->org->id
                && (int) $serverProps['organization_id'] !== $otherOrg->id;
        });
    }

    public function test_oversized_batch_is_rejected(): void
    {
        Queue::fake();

        $payload = ['events' => array_fill(0, 31, ['event' => 'page_viewed'])];

        $this->withTenant($this->org)
            ->postJson('/api/track', $payload)
            ->assertStatus(422);

        Queue::assertNotPushed(IngestAnalyticsEventsJob::class);
    }

    public function test_events_are_dispatched_to_analytics_queue(): void
    {
        Queue::fake();

        $this->withTenant($this->org)
            ->postJson('/api/track', $this->validPayload())
            ->assertStatus(202);

        Queue::assertPushedOn('analytics', IngestAnalyticsEventsJob::class);
    }

    public function test_ip_hash_is_not_present_in_server_props(): void
    {
        Queue::fake();

        $this->withTenant($this->org)
            ->postJson('/api/track', $this->validPayload())
            ->assertStatus(202);

        Queue::assertPushed(IngestAnalyticsEventsJob::class, function (IngestAnalyticsEventsJob $job): bool {
            $reflection = new \ReflectionClass($job);
            $serverProps = $reflection->getProperty('serverProps')->getValue($job);

            // ip_hash must not be present in serverProps at all (GDPR: we stopped collecting it)
            return ! array_key_exists('ip_hash', $serverProps);
        });
    }

    public function test_missing_events_key_is_rejected(): void
    {
        Queue::fake();

        $this->withTenant($this->org)
            ->postJson('/api/track', [])
            ->assertStatus(422);
    }

    public function test_request_without_tenant_returns_400(): void
    {
        // Do NOT bind the tenant stub — ResolveTenant will try to resolve from Host header.
        // Since there's no matching subdomain in test DB, the middleware redirects.
        // The controller's own guard handles the case where tenant is absent.
        Queue::fake();

        // Override ResolveTenant to pass through without setting a tenant
        $this->app->bind(\App\Http\Middleware\ResolveTenant::class, function () {
            return new class
            {
                public function handle($request, $next)
                {
                    // No tenant set
                    return $next($request);
                }
            };
        });

        $this->postJson('/api/track', $this->validPayload())
            ->assertStatus(400);
    }

    public function test_authenticated_user_id_is_stamped_server_side(): void
    {
        Queue::fake();

        $user = User::factory()->create();

        $this->withTenant($this->org)
            ->actingAs($user)
            ->postJson('/api/track', $this->validPayload())
            ->assertStatus(202);

        Queue::assertPushed(IngestAnalyticsEventsJob::class, function (IngestAnalyticsEventsJob $job) use ($user): bool {
            $reflection = new \ReflectionClass($job);
            $serverProps = $reflection->getProperty('serverProps')->getValue($job);

            return (int) $serverProps['user_id'] === $user->id;
        });
    }
}

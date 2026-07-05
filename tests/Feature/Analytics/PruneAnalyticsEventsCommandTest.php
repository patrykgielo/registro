<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PruneAnalyticsEventsCommandTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->equipmentRental()->create();
    }

    private function insertEvent(string $occurredAt): void
    {
        DB::table('analytics_events')->insert([
            'organization_id' => $this->org->id,
            'event' => 'page_viewed',
            'occurred_at' => $occurredAt,
            'received_at' => $occurredAt,
        ]);
    }

    public function test_prune_deletes_events_older_than_13_months(): void
    {
        $old = now()->subMonths(14)->format('Y-m-d H:i:s');
        $recent = now()->subDay()->format('Y-m-d H:i:s');

        $this->insertEvent($old);
        $this->insertEvent($old);
        $this->insertEvent($old);
        $this->insertEvent($recent);
        $this->insertEvent($recent);

        $this->artisan('analytics:prune')
            ->assertExitCode(0);

        $this->assertDatabaseCount('analytics_events', 2);
    }

    public function test_prune_respects_custom_months_option(): void
    {
        $twoMonthsAgo = now()->subMonths(2)->format('Y-m-d H:i:s');
        $recent = now()->subDay()->format('Y-m-d H:i:s');

        $this->insertEvent($twoMonthsAgo);
        $this->insertEvent($twoMonthsAgo);
        $this->insertEvent($recent);

        $this->artisan('analytics:prune', ['--months' => 1])
            ->assertExitCode(0);

        $this->assertDatabaseCount('analytics_events', 1);
    }
}

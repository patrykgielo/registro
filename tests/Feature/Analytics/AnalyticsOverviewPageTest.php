<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Filament\Pages\AnalyticsOverview;
use App\Models\Organization;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AnalyticsOverviewPageTest extends TestCase
{
    use RefreshDatabase;

    private function createAdminWithOrg(): array
    {
        $owner = User::factory()->create();
        $org = Organization::factory()->create(['owner_id' => $owner->id]);
        $adminRole = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $owner->assignRole($adminRole);

        return [$owner, $org];
    }

    private function insertEvent(int $orgId, string $event, array $overrides = []): void
    {
        \Illuminate\Support\Facades\DB::table('analytics_events')->insert(array_merge([
            'organization_id' => $orgId,
            'user_id' => null,
            'session_id' => 'sess-'.uniqid(),
            'event' => $event,
            'url' => 'https://example.com/page-'.uniqid(),
            'page_type' => 'home',
            'device_type' => 'desktop',
            'ip_hash' => hash('sha256', '127.0.0.1'),
            'occurred_at' => Carbon::now()->subHour()->toDateTimeString(),
            'received_at' => Carbon::now()->toDateTimeString(),
        ], $overrides));
    }

    public function test_analytics_page_requires_auth(): void
    {
        $response = $this->get('/admin/analityka');

        $response->assertRedirectToRoute('filament.admin.auth.login');
    }

    public function test_analytics_page_requires_admin_role(): void
    {
        $user = User::factory()->create();
        $customerRole = Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);
        $user->assignRole($customerRole);

        $response = $this->actingAs($user)->get('/admin/analityka');

        $response->assertForbidden();
    }

    public function test_get_kpi_data_returns_correct_counts(): void
    {
        [$admin, $org] = $this->createAdminWithOrg();

        // Seed: 3 page_viewed events for this org with distinct sessions
        $sessions = ['session-aaa', 'session-bbb', 'session-ccc'];
        foreach ($sessions as $session) {
            $this->insertEvent($org->id, 'page_viewed', [
                'session_id' => $session,
                'user_id' => $admin->id,
            ]);
        }

        // Bind tenant context so TenantFeature::currentTenant() resolves
        $this->app['request']->attributes->set('tenant', $org);

        $page = new AnalyticsOverview;
        $page->period = 'this_month';

        $kpi = $page->getKpiData();

        $this->assertEquals(3, $kpi['page_views']);
        $this->assertEquals(3, $kpi['unique_sessions']);
        $this->assertEquals(1, $kpi['unique_users']); // all events for same user
        $this->assertIsFloat($kpi['avg_session_events']);
    }

    public function test_get_top_pages_limited_to_ten(): void
    {
        [$admin, $org] = $this->createAdminWithOrg();

        // Insert 15 distinct URLs
        for ($i = 1; $i <= 15; $i++) {
            $this->insertEvent($org->id, 'page_viewed', [
                'url' => 'https://example.com/page-'.$i,
            ]);
        }

        $this->app['request']->attributes->set('tenant', $org);

        $page = new AnalyticsOverview;
        $page->period = 'this_month';

        $topPages = $page->getTopPages();

        $this->assertLessThanOrEqual(10, count($topPages));
        $this->assertArrayHasKey('url', $topPages[0]);
        $this->assertArrayHasKey('views', $topPages[0]);
        $this->assertArrayHasKey('sessions', $topPages[0]);
    }

    public function test_period_defaults_to_last_14_days(): void
    {
        [$admin, $org] = $this->createAdminWithOrg();

        $this->app['request']->attributes->set('tenant', $org);

        $page = new AnalyticsOverview;
        $page->mount();

        $this->assertEquals('last_14_days', $page->period);
    }

    public function test_invalid_period_falls_back_to_last_14_days(): void
    {
        [$admin, $org] = $this->createAdminWithOrg();

        $this->app['request']->attributes->set('tenant', $org);

        $page = new AnalyticsOverview;
        $page->period = 'invalid_period';
        $page->mount();

        $this->assertEquals('last_14_days', $page->period);
    }

    public function test_get_scroll_depth_returns_five_keys(): void
    {
        [$admin, $org] = $this->createAdminWithOrg();

        $this->insertEvent($org->id, 'scroll_25');
        $this->insertEvent($org->id, 'scroll_50');
        $this->insertEvent($org->id, 'scroll_75');

        $this->app['request']->attributes->set('tenant', $org);

        $page = new AnalyticsOverview;
        $page->period = 'this_month';

        $depth = $page->getScrollDepth();

        $this->assertArrayHasKey('25', $depth);
        $this->assertArrayHasKey('50', $depth);
        $this->assertArrayHasKey('75', $depth);
        $this->assertArrayHasKey('90', $depth);
        $this->assertArrayHasKey('100', $depth);
        $this->assertEquals(1, $depth['25']);
        $this->assertEquals(1, $depth['50']);
        $this->assertEquals(0, $depth['90']);
    }

    public function test_get_kpi_data_returns_zeros_without_tenant(): void
    {
        [$admin, $org] = $this->createAdminWithOrg();
        // Do NOT set tenant on request — TenantFeature::currentTenant() returns null

        $page = new AnalyticsOverview;
        $page->period = 'this_month';

        $kpi = $page->getKpiData();

        $this->assertEquals(0, $kpi['page_views']);
        $this->assertEquals(0, $kpi['unique_sessions']);
        $this->assertEquals(0, $kpi['unique_users']);
        $this->assertEquals(0.0, $kpi['avg_session_events']);
    }

    public function test_period_to_range_today_starts_at_midnight(): void
    {
        $page = new AnalyticsOverview;
        [$from, $to] = $page->periodToRange('today');

        $this->assertTrue($from->isStartOfDay());
        $this->assertTrue($to->lte(Carbon::now()->addSecond()));
    }

    public function test_period_to_range_last_month_covers_full_month(): void
    {
        $page = new AnalyticsOverview;
        [$from, $to] = $page->periodToRange('last_month');

        $expected = Carbon::now()->subMonthNoOverflow();

        $this->assertEquals(1, $from->day);
        $this->assertEquals($expected->month, $from->month);
        $this->assertEquals($from->endOfMonth()->day, $to->day);
    }

    public function test_kpi_data_is_isolated_per_organization(): void
    {
        [$admin, $org] = $this->createAdminWithOrg();
        $otherOrg = Organization::factory()->create();

        // 3 events for our org, 5 events for another org
        for ($i = 0; $i < 3; $i++) {
            $this->insertEvent($org->id, 'page_viewed');
        }
        for ($i = 0; $i < 5; $i++) {
            $this->insertEvent($otherOrg->id, 'page_viewed');
        }

        $this->app['request']->attributes->set('tenant', $org);

        $page = new AnalyticsOverview;
        $page->period = 'this_month';

        $kpi = $page->getKpiData();

        // Must see exactly 3 — not 8
        $this->assertEquals(3, $kpi['page_views']);
    }
}

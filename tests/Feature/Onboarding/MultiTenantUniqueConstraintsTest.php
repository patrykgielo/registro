<?php

namespace Tests\Feature\Onboarding;

use App\Models\Organization;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MultiTenantUniqueConstraintsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org1;

    private Organization $org2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org1 = Organization::factory()->create();
        $this->org2 = Organization::factory()->create();
    }

    public function test_two_orgs_can_have_service_with_same_name(): void
    {
        DB::table('services')->insert([
            'organization_id' => $this->org1->id,
            'name' => 'Wiertarka udarowa',
            'slug' => 'wiertarka-udarowa-1',
            'price' => 0,
            'duration_minutes' => 60,
            'is_active' => true,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('services')->insert([
            'organization_id' => $this->org2->id,
            'name' => 'Wiertarka udarowa',
            'slug' => 'wiertarka-udarowa-2',
            'price' => 0,
            'duration_minutes' => 60,
            'is_active' => true,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertDatabaseCount('services', 2);
    }

    public function test_duplicate_service_name_within_same_org_is_rejected(): void
    {
        DB::table('services')->insert([
            'organization_id' => $this->org1->id,
            'name' => 'Wiertarka udarowa',
            'slug' => 'wiertarka-udarowa-a',
            'price' => 0,
            'duration_minutes' => 60,
            'is_active' => true,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(UniqueConstraintViolationException::class);

        DB::table('services')->insert([
            'organization_id' => $this->org1->id,
            'name' => 'Wiertarka udarowa',
            'slug' => 'wiertarka-udarowa-b',
            'price' => 0,
            'duration_minutes' => 60,
            'is_active' => true,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_two_orgs_can_have_page_with_same_slug(): void
    {
        DB::table('pages')->insert([
            'organization_id' => $this->org1->id,
            'title' => 'O nas',
            'slug' => 'o-nas',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('pages')->insert([
            'organization_id' => $this->org2->id,
            'title' => 'O nas',
            'slug' => 'o-nas',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertDatabaseCount('pages', 2);
    }

    public function test_duplicate_page_slug_within_same_org_is_rejected(): void
    {
        DB::table('pages')->insert([
            'organization_id' => $this->org1->id,
            'title' => 'O nas',
            'slug' => 'o-nas',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(UniqueConstraintViolationException::class);

        DB::table('pages')->insert([
            'organization_id' => $this->org1->id,
            'title' => 'O nas kopia',
            'slug' => 'o-nas',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

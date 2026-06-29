<?php

declare(strict_types=1);

namespace Tests\Feature\Onboarding;

use App\Models\Organization;
use App\Models\User;
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

    public function test_two_orgs_can_have_same_order_number(): void
    {
        $user = User::factory()->create();

        $baseRow = [
            'user_id' => $user->id,
            'order_number' => 'ORD-2024-001',
            'subtotal' => '100.00',
            'total_amount' => '100.00',
            'customer_email' => 'test@example.com',
            'customer_first_name' => 'Jan',
            'customer_last_name' => 'Kowalski',
            'created_at' => now(),
            'updated_at' => now(),
        ];

        DB::table('orders')->insert(array_merge($baseRow, ['organization_id' => $this->org1->id]));
        DB::table('orders')->insert(array_merge($baseRow, ['organization_id' => $this->org2->id]));

        $this->assertDatabaseCount('orders', 2);
    }

    public function test_duplicate_order_number_within_same_org_is_rejected(): void
    {
        $user = User::factory()->create();

        $row = [
            'organization_id' => $this->org1->id,
            'user_id' => $user->id,
            'order_number' => 'ORD-2024-001',
            'subtotal' => '100.00',
            'total_amount' => '100.00',
            'customer_email' => 'test@example.com',
            'customer_first_name' => 'Jan',
            'customer_last_name' => 'Kowalski',
            'created_at' => now(),
            'updated_at' => now(),
        ];

        DB::table('orders')->insert($row);

        $this->expectException(UniqueConstraintViolationException::class);

        DB::table('orders')->insert($row);
    }
}

<?php

namespace Tests\Unit;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrganizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_organization_can_be_created(): void
    {
        $owner = User::factory()->create();

        $org = Organization::create([
            'name' => 'Test Salon',
            'slug' => 'test-salon',
            'booking_type' => 'time_slot',
            'owner_id' => $owner->id,
            'is_active' => true,
        ]);

        $this->assertDatabaseHas('organizations', [
            'name' => 'Test Salon',
            'slug' => 'test-salon',
            'booking_type' => 'time_slot',
        ]);
    }

    public function test_organization_has_owner_relationship(): void
    {
        $owner = User::factory()->create();

        $org = Organization::create([
            'name' => 'Test Salon',
            'slug' => 'test-salon',
            'booking_type' => 'time_slot',
            'owner_id' => $owner->id,
        ]);

        $this->assertEquals($owner->id, $org->owner->id);
    }

    public function test_organization_has_members(): void
    {
        $owner = User::factory()->create();
        $staff = User::factory()->create();

        $org = Organization::create([
            'name' => 'Test Salon',
            'slug' => 'test-salon',
            'booking_type' => 'time_slot',
            'owner_id' => $owner->id,
        ]);

        $org->members()->attach($owner, ['role' => 'owner']);
        $org->members()->attach($staff, ['role' => 'staff']);

        $this->assertCount(2, $org->members);
        $this->assertEquals('owner', $org->members->find($owner->id)->pivot->role);
        $this->assertEquals('staff', $org->members->find($staff->id)->pivot->role);
    }

    public function test_user_can_access_their_organizations(): void
    {
        $user = User::factory()->create();

        $org1 = Organization::create([
            'name' => 'Salon A',
            'slug' => 'salon-a',
            'booking_type' => 'time_slot',
            'owner_id' => $user->id,
        ]);
        $org1->members()->attach($user, ['role' => 'owner']);

        $org2 = Organization::create([
            'name' => 'Salon B',
            'slug' => 'salon-b',
            'booking_type' => 'time_slot',
            'owner_id' => $user->id,
        ]);
        $org2->members()->attach($user, ['role' => 'admin']);

        $this->assertCount(2, $user->organizations);
    }

    public function test_user_can_check_tenant_access(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $org = Organization::create([
            'name' => 'Salon A',
            'slug' => 'salon-a',
            'booking_type' => 'time_slot',
            'owner_id' => $user->id,
        ]);
        $org->members()->attach($user, ['role' => 'owner']);

        $this->assertTrue($user->canAccessTenant($org));
        $this->assertFalse($otherUser->canAccessTenant($org));
    }

    public function test_organization_trial_status(): void
    {
        $owner = User::factory()->create();

        $onTrial = Organization::create([
            'name' => 'Trial Org',
            'slug' => 'trial-org',
            'booking_type' => 'time_slot',
            'owner_id' => $owner->id,
            'trial_ends_at' => now()->addDays(14),
        ]);

        $expired = Organization::create([
            'name' => 'Expired Org',
            'slug' => 'expired-org',
            'booking_type' => 'time_slot',
            'owner_id' => $owner->id,
            'trial_ends_at' => now()->subDay(),
        ]);

        $this->assertTrue($onTrial->onTrial());
        $this->assertFalse($onTrial->trialExpired());

        $this->assertFalse($expired->onTrial());
        $this->assertTrue($expired->trialExpired());
    }

    public function test_organization_factory(): void
    {
        $org = Organization::factory()->create();

        $this->assertNotNull($org->name);
        $this->assertNotNull($org->slug);
        $this->assertEquals('time_slot', $org->booking_type);
        $this->assertTrue($org->is_active);
    }

    public function test_organization_factory_item_rental(): void
    {
        $org = Organization::factory()->itemRental()->create();
        $this->assertEquals('item_rental', $org->booking_type);
    }

    public function test_organization_factory_on_trial(): void
    {
        $org = Organization::factory()->onTrial()->create();
        $this->assertTrue($org->onTrial());
    }

    public function test_booking_type_enum_values(): void
    {
        $owner = User::factory()->create();

        foreach (['time_slot', 'item_rental', 'both'] as $type) {
            $org = Organization::create([
                'name' => "Org {$type}",
                'slug' => "org-{$type}",
                'booking_type' => $type,
                'owner_id' => $owner->id,
            ]);
            $this->assertEquals($type, $org->booking_type);
        }
    }
}

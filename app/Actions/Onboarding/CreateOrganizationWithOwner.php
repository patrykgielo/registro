<?php

declare(strict_types=1);

namespace App\Actions\Onboarding;

use App\Enums\Industry;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class CreateOrganizationWithOwner
{
    public function __construct(
        private SeedOrganizationDefaults $seedDefaults,
    ) {}

    /**
     * Create an organization with its owner in a single transaction.
     *
     * @return array{user: User, organization: Organization}
     */
    public function execute(OnboardingData $data): array
    {
        return DB::transaction(function () use ($data) {
            $user = User::create([
                'first_name' => $data->firstName,
                'last_name' => $data->lastName,
                'email' => $data->email,
                'password' => Hash::make($data->password),
                'email_verified_at' => now(),
            ]);

            Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
            $user->assignRole('admin');

            $industry = $data->industry ? Industry::from($data->industry) : null;
            $bookingType = $industry ? $industry->bookingType() : $data->bookingType;

            $org = Organization::create([
                'name' => $data->orgName,
                'slug' => $data->slug,
                'booking_type' => $bookingType,
                'industry' => $data->industry,
                'owner_id' => $user->id,
                'trial_ends_at' => now()->addDays(14),
            ]);

            $user->organizations()->attach($org->id, ['role' => 'owner']);

            $this->seedDefaults->execute($org);

            return ['user' => $user, 'organization' => $org];
        });
    }
}

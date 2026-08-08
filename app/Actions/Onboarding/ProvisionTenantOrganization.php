<?php

declare(strict_types=1);

namespace App\Actions\Onboarding;

use App\Enums\Industry;
use App\Models\Organization;
use App\Models\OrganizationLifecycleLog;
use App\Models\TenantProvisioningState;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

/**
 * Creates (or finds) the single organization for a dedicated tenant-stack
 * container, on behalf of registro:tenant-provision.
 *
 * Used to be deliberately NOT CreateOrganizationWithOwner, the public
 * self-serve wizard's action (removed along with the wizard itself -- see
 * routes/web.php) -- that action inserted unconditionally and collected a
 * password inline, neither of which this needs:
 *
 * - Idempotent by slug: re-running the command (container restart, re-applied
 *   stack config) must find the existing org/owner rather than duplicate or
 *   overwrite them.
 * - Owner has no password: access is via the existing invite-link mechanism
 *   (User::initiatePasswordSetup(), same as Filament's UserResource) instead of
 *   a password collected inline -- there is no operator-facing form here.
 */
class ProvisionTenantOrganization
{
    public function __construct(
        private SeedOrganizationDefaults $seedDefaults,
    ) {}

    /**
     * @return array{
     *     organization: Organization,
     *     owner: User,
     *     organization_was_created: bool,
     *     owner_was_created: bool,
     * }
     */
    public function execute(
        string $slug,
        string $name,
        Industry $industry,
        string $ownerEmail,
        string $ownerFirstName,
        string $ownerLastName,
    ): array {
        return DB::transaction(function () use ($slug, $name, $industry, $ownerEmail, $ownerFirstName, $ownerLastName) {
            $owner = User::firstOrCreate(
                ['email' => $ownerEmail],
                [
                    'first_name' => $ownerFirstName,
                    'last_name' => $ownerLastName,
                    'password' => null,
                ]
            );
            $ownerWasCreated = $owner->wasRecentlyCreated;

            $org = Organization::firstOrCreate(
                ['slug' => $slug],
                [
                    'name' => $name,
                    'booking_type' => $industry->bookingType(),
                    'industry' => $industry->value,
                    'owner_id' => $owner->id,
                    'trial_ends_at' => now()->addDays(14),
                ]
            );
            $orgWasCreated = $org->wasRecentlyCreated;

            Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
            if (! $owner->hasRole('admin')) {
                $owner->assignRole('admin');
            }

            // syncWithoutDetaching is the idempotent half of attach(): re-running
            // this against an already-linked owner updates the pivot role (no-op
            // when unchanged) instead of throwing a duplicate-pivot error.
            $owner->organizations()->syncWithoutDetaching([$org->id => ['role' => 'owner']]);

            if ($orgWasCreated) {
                $this->seedDefaults->execute($org);

                OrganizationLifecycleLog::record($org, 'provisioned', null, [
                    'slug' => $org->slug,
                    'industry' => $industry->value,
                    'owner_email' => $owner->email,
                    'source' => 'cli',
                ]);
            }

            TenantProvisioningState::markProvisioned($org);

            return [
                'organization' => $org->refresh(),
                'owner' => $owner->refresh(),
                'organization_was_created' => $orgWasCreated,
                'owner_was_created' => $ownerWasCreated,
            ];
        });
    }
}

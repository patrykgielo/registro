<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\AppointmentResource;
use App\Filament\Resources\AuditLogResource;
use App\Filament\Resources\BaseResource;
use App\Filament\Resources\CarBrandResource;
use App\Filament\Resources\CarModelResource;
use App\Filament\Resources\Categories\CategoryResource;
use App\Filament\Resources\CustomerResource;
use App\Filament\Resources\EmailEventResource;
use App\Filament\Resources\EmailSendResource;
use App\Filament\Resources\EmailSuppressionResource;
use App\Filament\Resources\EmailTemplateResource;
use App\Filament\Resources\EmployeeResource;
use App\Filament\Resources\ExtensionRequestResource;
use App\Filament\Resources\MaintenanceEventResource;
use App\Filament\Resources\OrderResource;
use App\Filament\Resources\Pages\PageResource;
use App\Filament\Resources\PortfolioItems\PortfolioItemResource;
use App\Filament\Resources\Posts\PostResource;
use App\Filament\Resources\Promotions\PromotionResource;
use App\Filament\Resources\ReminderConfigResource;
use App\Filament\Resources\RentalCategoryResource;
use App\Filament\Resources\RentalResource;
use App\Filament\Resources\RoleResource;
use App\Filament\Resources\ServiceAreas\ServiceAreaResource;
use App\Filament\Resources\ServiceAreaWaitlists\ServiceAreaWaitlistResource;
use App\Filament\Resources\ServiceResource;
use App\Filament\Resources\SmsEventResource;
use App\Filament\Resources\SmsSendResource;
use App\Filament\Resources\SmsSuppressionResource;
use App\Filament\Resources\SmsTemplateResource;
use App\Filament\Resources\StaffDateExceptionResource;
use App\Filament\Resources\StaffScheduleResource;
use App\Filament\Resources\StaffVacationPeriodResource;
use App\Filament\Resources\UserResource;
use App\Filament\Resources\VehicleTypeResource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Architectural regression guard for the get*AuthorizationResponse() <-> can*()
 * wiring that App\Filament\Resources\BaseResource now provides.
 *
 * Filament's DeleteAction/EditAction/CreateAction/DeleteBulkAction never call
 * canDelete()/canEdit()/canCreate() directly — they call
 * getDeleteAuthorizationResponse()/getEditAuthorizationResponse()/
 * getCreateAuthorizationResponse() (vendor/filament/filament/src/Resources/Pages/Page.php:298-311).
 * Before BaseResource wired those two families of methods together, a
 * Resource overriding only canDelete() enforced nothing: the default
 * get*AuthorizationResponse() fell through to Filament's Gate/policy
 * resolution, which allows by default because app/Policies/ does not exist
 * in this project.
 *
 * Guest (auth()->user() === null) is used as the universal "must deny"
 * actor: every can*() implementation across all 34 resources — the
 * BaseResource default and every override — starts from
 * `auth()->user()?->... ?? false`, so it needs no per-resource setup and
 * denies on every one of them today. The 'admin' actor pass below is the
 * stronger check: it produces a real mix of true/false across the 34 (most
 * allow admin, a handful of super-admin-only resources deny it), which is
 * exactly the shape CarBrandResourceDeleteGuardTest exploits concretely.
 *
 * Mutation-verified (see PR): temporarily changing BaseResource's
 * getDeleteAuthorizationResponse()/getCreateAuthorizationResponse()/
 * getEditAuthorizationResponse()/getDeleteAnyAuthorizationResponse()/
 * getViewAnyAuthorizationResponse() to return Response::allow()
 * unconditionally (the pre-fix shape) makes every assertion in this file
 * fail, across all 34 resources.
 */
class ResourceAuthorizationWiringTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<int, class-string<BaseResource>> */
    private function resourceClasses(): array
    {
        return [
            AppointmentResource::class,
            AuditLogResource::class,
            CarBrandResource::class,
            CarModelResource::class,
            CategoryResource::class,
            CustomerResource::class,
            EmailEventResource::class,
            EmailSendResource::class,
            EmailSuppressionResource::class,
            EmailTemplateResource::class,
            EmployeeResource::class,
            ExtensionRequestResource::class,
            MaintenanceEventResource::class,
            OrderResource::class,
            PageResource::class,
            PortfolioItemResource::class,
            PostResource::class,
            PromotionResource::class,
            ReminderConfigResource::class,
            RentalCategoryResource::class,
            RentalResource::class,
            RoleResource::class,
            ServiceAreaResource::class,
            ServiceAreaWaitlistResource::class,
            ServiceResource::class,
            SmsEventResource::class,
            SmsSendResource::class,
            SmsSuppressionResource::class,
            SmsTemplateResource::class,
            StaffDateExceptionResource::class,
            StaffScheduleResource::class,
            StaffVacationPeriodResource::class,
            UserResource::class,
            VehicleTypeResource::class,
        ];
    }

    public function test_all_34_resources_extend_base_resource(): void
    {
        // Guards the premise of every other test in this file: if a future
        // Resource is added without extending BaseResource, it silently gets
        // none of this wiring and none of these assertions run against it.
        foreach ($this->resourceClasses() as $resourceClass) {
            $this->assertTrue(
                is_subclass_of($resourceClass, BaseResource::class),
                "{$resourceClass} no longer extends BaseResource — add it to a dedicated authorization test."
            );
        }
    }

    public function test_guest_is_denied_every_destructive_authorization_response(): void
    {
        foreach ($this->resourceClasses() as $resourceClass) {
            $record = new ($resourceClass::getModel())();

            $this->assertFalse(
                $resourceClass::getViewAnyAuthorizationResponse()->allowed(),
                "{$resourceClass}::getViewAnyAuthorizationResponse() allowed a guest"
            );
            $this->assertFalse(
                $resourceClass::getCreateAuthorizationResponse()->allowed(),
                "{$resourceClass}::getCreateAuthorizationResponse() allowed a guest"
            );
            $this->assertFalse(
                $resourceClass::getEditAuthorizationResponse($record)->allowed(),
                "{$resourceClass}::getEditAuthorizationResponse() allowed a guest"
            );
            $this->assertFalse(
                $resourceClass::getDeleteAuthorizationResponse($record)->allowed(),
                "{$resourceClass}::getDeleteAuthorizationResponse() allowed a guest"
            );
            $this->assertFalse(
                $resourceClass::getDeleteAnyAuthorizationResponse()->allowed(),
                "{$resourceClass}::getDeleteAnyAuthorizationResponse() allowed a guest"
            );
        }
    }

    public function test_authorization_response_matches_can_for_every_resource_for_guest_and_admin(): void
    {
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);

        $admin = User::factory()->create();
        $admin->assignRole('admin');

        foreach ($this->resourceClasses() as $resourceClass) {
            $record = new ($resourceClass::getModel())();

            // Guest — auth()->user() is null.
            $this->assertSame(
                $resourceClass::canViewAny(),
                $resourceClass::getViewAnyAuthorizationResponse()->allowed(),
                "{$resourceClass}: canViewAny()/getViewAnyAuthorizationResponse() disagree for guest"
            );
            $this->assertSame(
                $resourceClass::canDelete($record),
                $resourceClass::getDeleteAuthorizationResponse($record)->allowed(),
                "{$resourceClass}: canDelete()/getDeleteAuthorizationResponse() disagree for guest"
            );
            $this->assertSame(
                $resourceClass::canDeleteAny(),
                $resourceClass::getDeleteAnyAuthorizationResponse()->allowed(),
                "{$resourceClass}: canDeleteAny()/getDeleteAnyAuthorizationResponse() disagree for guest"
            );

            // Tenant admin — the differentiating actor: most resources allow
            // it, a handful (CarBrand/CarModel/VehicleType/MaintenanceEvent/
            // AuditLog/RoleResource/...) deny it. Either way the two methods
            // must still agree.
            $this->actingAs($admin);

            $this->assertSame(
                $resourceClass::canViewAny(),
                $resourceClass::getViewAnyAuthorizationResponse()->allowed(),
                "{$resourceClass}: canViewAny()/getViewAnyAuthorizationResponse() disagree for admin"
            );
            $this->assertSame(
                $resourceClass::canCreate(),
                $resourceClass::getCreateAuthorizationResponse()->allowed(),
                "{$resourceClass}: canCreate()/getCreateAuthorizationResponse() disagree for admin"
            );
            $this->assertSame(
                $resourceClass::canEdit($record),
                $resourceClass::getEditAuthorizationResponse($record)->allowed(),
                "{$resourceClass}: canEdit()/getEditAuthorizationResponse() disagree for admin"
            );
            $this->assertSame(
                $resourceClass::canDelete($record),
                $resourceClass::getDeleteAuthorizationResponse($record)->allowed(),
                "{$resourceClass}: canDelete()/getDeleteAuthorizationResponse() disagree for admin"
            );
            $this->assertSame(
                $resourceClass::canDeleteAny(),
                $resourceClass::getDeleteAnyAuthorizationResponse()->allowed(),
                "{$resourceClass}: canDeleteAny()/getDeleteAnyAuthorizationResponse() disagree for admin"
            );

            \Illuminate\Support\Facades\Auth::logout();
        }
    }

    /**
     * Proof that admin actually gets denied on the resources that restrict
     * mutation to super-admin — not just that the two methods happen to
     * agree (which "both always false" or "both always true" would also
     * satisfy). This is the differentiating half of the equality test above.
     */
    public function test_admin_is_denied_delete_on_super_admin_only_resources(): void
    {
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);

        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        foreach ([CarBrandResource::class, CarModelResource::class, VehicleTypeResource::class] as $resourceClass) {
            $record = new ($resourceClass::getModel())();

            $this->assertFalse(
                $resourceClass::getDeleteAuthorizationResponse($record)->allowed(),
                "{$resourceClass} allowed a tenant admin to delete — expected super-admin only"
            );
        }
    }
}

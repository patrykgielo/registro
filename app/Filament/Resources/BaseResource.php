<?php

namespace App\Filament\Resources;

use App\Support\TenantFeature;
use App\Traits\BelongsToOrganization;
use Filament\Resources\Resource;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

abstract class BaseResource extends Resource
{
    /**
     * The module this resource belongs to.
     * null = core resource, always visible.
     */
    protected static ?string $module = null;

    /**
     * Conditionally show/hide navigation based on module activation.
     * Core resources ($module = null) are always visible.
     */
    public static function shouldRegisterNavigation(): bool
    {
        if (! static::$shouldRegisterNavigation) {
            return false;
        }

        if (static::$module === null) {
            return true;
        }

        $tenant = TenantFeature::currentTenant();

        if ($tenant === null) {
            return true; // Platform panel / CLI — show all
        }

        return $tenant->hasModule(static::$module);
    }

    /**
     * Auto-detect tenant scoping based on whether the model uses BelongsToOrganization trait.
     *
     * Models WITH BelongsToOrganization → scoped to tenant automatically.
     * Models WITHOUT it (Role, CarBrand, User, etc.) → excluded automatically.
     *
     * No manual $isScopedToTenant configuration needed on any Resource.
     */
    public static function isScopedToTenant(): bool
    {
        $model = static::getModel();

        return in_array(BelongsToOrganization::class, class_uses_recursive($model));
    }

    /**
     * Get available Heroicon solid icons for selection.
     *
     * Dynamically scans blade-heroicons package for s-* (solid) icons.
     *
     * @return array<string, string> Icon name => Human-readable label
     */
    protected static function getHeroiconOptions(): array
    {
        $iconPath = base_path('vendor/blade-ui-kit/blade-heroicons/resources/svg');
        $files = glob($iconPath.'/s-*.svg');

        if (empty($files)) {
            return [
                'sparkles' => 'Sparkles',
                'rectangle-stack' => 'Rectangle Stack',
                'paint-brush' => 'Paint Brush',
                'sun' => 'Sun',
                'squares-plus' => 'Squares Plus',
                'swatch' => 'Swatch',
                'beaker' => 'Beaker',
                'shield-check' => 'Shield Check',
            ];
        }

        $icons = [];
        foreach ($files as $file) {
            $name = str_replace('.svg', '', basename($file));
            $name = str_replace('s-', '', $name);
            $icons[$name] = ucwords(str_replace('-', ' ', $name));
        }

        asort($icons);

        return $icons;
    }

    /*
    |--------------------------------------------------------------------------
    | Authorization
    |--------------------------------------------------------------------------
    |
    | Filament's table/page actions (DeleteAction, EditAction, CreateAction,
    | DeleteBulkAction, ...) never call canDelete()/canEdit()/canCreate()
    | directly — they call getDeleteAuthorizationResponse(), getEditAuthorizationResponse(),
    | etc. (vendor/filament/filament/src/Resources/Pages/Page.php:298-311). Those
    | methods, inherited unmodified from Filament\Resources\Resource\Concerns\HasAuthorization,
    | consult a Laravel Policy via the Gate — and app/Policies/ does not exist in
    | this project, so with no policy and strict mode off they resolve to
    | Response::allow() for everyone (see get_authorization_response() in
    | vendor/filament/filament/src/helpers.php). A Resource overriding only
    | canDelete() therefore changes nothing: the override is never consulted.
    |
    | This block closes that gap for every one of the 34 Resources extending
    | BaseResource, in one place, by making can*() the thing Filament actually
    | asks. See .claude/rules/filament-resources.md "Autoryzacja" for the
    | incident this fixes and app/docs/security/patterns/resource-authorization.md
    | for the full design writeup.
    |
    */

    /**
     * Deny-by-default for every action a child Resource has not explicitly opted
     * into. Every one of the 34 Resources under this class already overrides
     * canViewAny() — this default only bites a Resource with none at all
     * (ServiceAreaResource was exactly that case; it now defines its own).
     *
     * Mutating actions (create/edit/delete/...) default to `admin` or
     * `super-admin` — the same gate every canViewAny() override in this codebase
     * already uses except AppointmentResource (which also allows `staff` and
     * defines its own matching overrides, since staff run the calendar day to
     * day) and the small number of Resources that already define their own full
     * can*() set (StaffVacationPeriodResource, UserResource, RoleResource, the
     * super-admin-only communication/vehicle Resources, ...). Concrete logic,
     * NOT delegating to get*AuthorizationResponse() — that delegation is exactly
     * the infinite recursion trap: the default getDeleteAuthorizationResponse()
     * below calls canDelete(), so canDelete() must never call it back.
     */
    protected static function isDefaultManagingActor(): bool
    {
        return auth()->user()?->hasAnyRole(['admin', 'super-admin']) ?? false;
    }

    public static function canViewAny(): bool
    {
        return false;
    }

    public static function canView(Model $record): bool
    {
        return static::isDefaultManagingActor();
    }

    public static function canCreate(): bool
    {
        return static::isDefaultManagingActor();
    }

    public static function canEdit(Model $record): bool
    {
        return static::isDefaultManagingActor();
    }

    public static function canDelete(Model $record): bool
    {
        return static::isDefaultManagingActor();
    }

    public static function canDeleteAny(): bool
    {
        return static::isDefaultManagingActor();
    }

    public static function canReplicate(Model $record): bool
    {
        return static::isDefaultManagingActor();
    }

    public static function canRestore(Model $record): bool
    {
        return static::isDefaultManagingActor();
    }

    public static function canRestoreAny(): bool
    {
        return static::isDefaultManagingActor();
    }

    public static function canForceDelete(Model $record): bool
    {
        return static::isDefaultManagingActor();
    }

    public static function canForceDeleteAny(): bool
    {
        return static::isDefaultManagingActor();
    }

    public static function canReorder(): bool
    {
        return static::isDefaultManagingActor();
    }

    /**
     * Everything below wires the methods Filament's actions actually call to
     * the can*() methods above. A child Resource overriding canDelete() now
     * changes real behavior because this is the only implementation of
     * getDeleteAuthorizationResponse() in the class tree that matters — it is
     * never itself overridden except by UserResource historically, which is
     * now redundant with this and has been removed in favour of it.
     */
    public static function getViewAnyAuthorizationResponse(): Response
    {
        return static::canViewAny() ? Response::allow() : Response::deny();
    }

    public static function getViewAuthorizationResponse(Model $record): Response
    {
        return static::canView($record) ? Response::allow() : Response::deny();
    }

    public static function getCreateAuthorizationResponse(): Response
    {
        return static::canCreate() ? Response::allow() : Response::deny();
    }

    public static function getEditAuthorizationResponse(Model $record): Response
    {
        return static::canEdit($record) ? Response::allow() : Response::deny();
    }

    public static function getDeleteAuthorizationResponse(Model $record): Response
    {
        return static::canDelete($record) ? Response::allow() : Response::deny();
    }

    public static function getDeleteAnyAuthorizationResponse(): Response
    {
        return static::canDeleteAny() ? Response::allow() : Response::deny();
    }

    public static function getReplicateAuthorizationResponse(Model $record): Response
    {
        return static::canReplicate($record) ? Response::allow() : Response::deny();
    }

    public static function getRestoreAuthorizationResponse(Model $record): Response
    {
        return static::canRestore($record) ? Response::allow() : Response::deny();
    }

    public static function getRestoreAnyAuthorizationResponse(): Response
    {
        return static::canRestoreAny() ? Response::allow() : Response::deny();
    }

    public static function getForceDeleteAuthorizationResponse(Model $record): Response
    {
        return static::canForceDelete($record) ? Response::allow() : Response::deny();
    }

    public static function getForceDeleteAnyAuthorizationResponse(): Response
    {
        return static::canForceDeleteAny() ? Response::allow() : Response::deny();
    }

    public static function getReorderAuthorizationResponse(): Response
    {
        return static::canReorder() ? Response::allow() : Response::deny();
    }
}

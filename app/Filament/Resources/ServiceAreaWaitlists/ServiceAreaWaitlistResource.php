<?php

namespace App\Filament\Resources\ServiceAreaWaitlists;

use App\Filament\Resources\BaseResource;
use App\Filament\Resources\ServiceAreaWaitlists\Pages\EditServiceAreaWaitlist;
use App\Filament\Resources\ServiceAreaWaitlists\Pages\ListServiceAreaWaitlists;
use App\Filament\Resources\ServiceAreaWaitlists\Schemas\ServiceAreaWaitlistForm;
use App\Filament\Resources\ServiceAreaWaitlists\Tables\ServiceAreaWaitlistsTable;
use App\Models\ServiceAreaWaitlist;
use BackedEnum;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Service Area Waitlist Resource
 *
 * SECURITY (VULN-003 follow-up, 2026-07-05): at the time ServiceAreaWaitlist had
 * no organization_id, so any tenant that enabled the `service_area` module would
 * have seen EVERY other tenant's waitlist submissions (name, email, phone,
 * address, GPS) — a GDPR-relevant PII leak. Restricted to super-admin only, same
 * pattern as AuditLogResource / MaintenanceEventResource / EmailEventResource /
 * SmsEventResource.
 *
 * Since 2026_11_07_110000 the rows are tenant-owned (BelongsToOrganization), so
 * the query below is tenant-scoped and a super-admin on a tenant subdomain sees
 * that tenant's sign-ups only. The super-admin gate is DELIBERATELY unchanged:
 * letting tenant admins work their own waitlist is now possible but is a product
 * decision, not part of the isolation fix.
 */
class ServiceAreaWaitlistResource extends BaseResource
{
    protected static ?string $model = ServiceAreaWaitlist::class;

    protected static ?string $module = 'service_area';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationLabel = 'Lista Oczekujących';

    protected static ?string $modelLabel = 'wpis na liście oczekujących';

    protected static ?string $pluralModelLabel = 'lista oczekujących';

    protected static string|UnitEnum|null $navigationGroup = 'settings';

    protected static ?int $navigationSort = 41;

    public static function getNavigationBadge(): ?string
    {
        if (! auth()->user()?->hasRole('super-admin')) {
            return null;
        }

        return (string) static::getModel()::where('status', 'pending')->count();
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function form(Schema $schema): Schema
    {
        return ServiceAreaWaitlistForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ServiceAreaWaitlistsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListServiceAreaWaitlists::route('/'),
            // 'create' route removed - waitlist entries created by customers via API only
            'edit' => EditServiceAreaWaitlist::route('/{record}/edit'),
        ];
    }

    /**
     * Restrict access to super-admins only. The model is tenant-scoped (BelongsToOrganization);
     * the gate is kept super-admin-only for now — opening it to tenant admins is a product decision.
     */
    public static function canViewAny(): bool
    {
        return auth()->user()?->hasRole('super-admin') ?? false;
    }

    public static function canView($record): bool
    {
        return auth()->user()?->hasRole('super-admin') ?? false;
    }

    public static function canEdit($record): bool
    {
        return auth()->user()?->hasRole('super-admin') ?? false;
    }

    public static function canDelete($record): bool
    {
        return auth()->user()?->hasRole('super-admin') ?? false;
    }

    public static function canDeleteAny(): bool
    {
        return auth()->user()?->hasRole('super-admin') ?? false;
    }

    public static function canCreate(): bool
    {
        // Belt-and-suspenders — no 'create' page is registered (waitlist
        // entries are created by customers via API only), but this closes
        // the same gap the sibling global resources close explicitly.
        return auth()->user()?->hasRole('super-admin') ?? false;
    }
}

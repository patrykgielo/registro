# Laravel Senior Architect — Project Memory

## Architecture
- Multi-tenant SaaS: Organization → Industry → modules/features/terminology
- Panels: `/platform` (super-admin, no tenant) | `/admin` (tenant, org-scoped)
- Service = universal model (ServiceType::TimeSlot | ServiceType::ItemRental)
- Module gating: BaseResource.$module, Organization.hasModule(), TenantFeature::active()

## Key Models
- User: first_name/last_name (NO `name` column — accessor only)
- Organization: has industry, modules, features
- Service: universal — both timeslot bookings and item rentals
- RentalItem: REMOVED — rentals are Service with service_type=item_rental

## Patterns
- Actions pattern for complex operations (app/Actions/)
- SettingsManager for config (app/Services/SettingsManager.php)
- Spatie Roles: ALWAYS Role::firstOrCreate() before assignRole()
- BelongsToOrganization trait for tenant scoping
- FILESYSTEM_DISK=public ALWAYS (never local)

## Filament v4 (breaking changes)
- form(Schema $schema): Schema NOT form(Form $form): Form
- string|\BackedEnum|null $navigationIcon NOT ?string
- Filament\Actions\EditAction NOT Filament\Tables\Actions\EditAction
- New PanelProviders → register in bootstrap/providers.php + restart PHP-FPM

## Testing
- Tests run in Docker only (PHP 8.3, local=8.2)
- .env.testing MUST exist → DB_CONNECTION=sqlite, DB_DATABASE=:memory:
- 5 pre-existing failures: BookingServiceArea(4) + TenantFeature(1)

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
- Test pattern: RefreshDatabase + factories (no seeders in rental tests)
- Throttle middleware disabled in tests via withoutMiddleware()

## Rental Inventory System (Steps 1-10 complete, PRs #14/#15)
- RentalAvailabilityService: getAvailableQuantity, getMonthlyAvailability, createHold, confirmHold, calculatePricing
- RentalBookingController: show/storeStep1/showStep2/storeStep2/showStep3/confirm/showConfirmation/checkAvailability/monthlyAvailability
- Routes: /wypozyczalnia/{slug} (booking flow) + /api/rental/{slug}/dostepnosc + /api/rental/{slug}/kalendarz
- Hold TTL: 15 minutes (HOLD_TTL_MINUTES const)
- RentalStatus: Held→Pending→Confirmed→Active→Returned | Cancelled | Expired
- RentalStatus.blocksAvailability(): Held, Pending, Confirmed, Active
- service/show.blade.php has sticky sidebar (lg:col-span-1) — mini-calendar goes there
- RentalResource already has confirm/markPickedUp/markReturned/cancel row actions
- RentalFactory: has confirmed/active/returned/cancelled states + held() state (added Step 12)
- rentals.customer_id is NULLABLE (migration 2026_03_25_000001 — guest bookings require null)
- Controller tests: withoutMiddleware([ThrottleRequests, ResolveTenant]) — BelongsToOrganization scope is no-op without tenant, queries work unscoped
- Step 12 complete: 67 tests passing (21 availability + 31 controller + 15 pricing)

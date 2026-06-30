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
- SettingsManager at app/Support/Settings/SettingsManager.php (NOT app/Services/)
- Spatie Roles: ALWAYS Role::firstOrCreate() before assignRole()
- BelongsToOrganization trait for tenant scoping
- FILESYSTEM_DISK=public ALWAYS (never local)
- SettingsManager null-tenant invariant: isBookingEnabled/isRentalEnabled return permissive values (true) when tenant=null; controllers do their own abort_unless($org, 404)
- Rental/Booking separation: isBookingEnabled()=false for item_rental orgs; isRentalEnabled()=true for item_rental+both; CheckRentalEnabled middleware guards /koszyk/* routes

## Filament v4 (breaking changes)
- form(Schema $schema): Schema NOT form(Form $form): Form
- string|\BackedEnum|null $navigationIcon NOT ?string
- Filament\Actions\EditAction NOT Filament\Tables\Actions\EditAction
- New PanelProviders → register in bootstrap/providers.php + restart PHP-FPM

## Order Notifications (feature/order-notifications — 2026-03-29)
- 3 domain events: OrderPaid, OrderConfirmed, OrderCancelled (app/Events/)
- 3 notifications: OrderPaidNotification (customer+admin), OrderConfirmedNotification, OrderCancelledNotification (app/Notifications/)
- TemplateKey: ORDER_PAID, ORDER_CONFIRMED, ORDER_CANCELLED, ADMIN_NEW_ORDER (all email-only)
- OrderPaid dispatched directly in Przelewy24Service (after transitionTo+update) — NOT via state machine hook
- confirmed/cancelled states use afterTransitionHooks() in OrderStatusStateMachine
- afterTransitionHooks() pattern: returns array keyed by $to state → array of callables($from, $model)
- AppServiceProvider::registerEventListeners() wired: OrderPaid → customer+admin, OrderConfirmed/Cancelled → customer only
- 8 new seeder templates (4 keys × PL/EN) in EmailTemplateSeeder (38 total)
- Docs: app/docs/features/order-notifications.md

## Order / Checkout (Phase: legal-data-overhaul, branch: feature/checkout-legal-data)
- Orders now have customer_type (natural_person|business), deposit tracking, legal consent timestamps
- ValidPolishPESEL (app/Rules/), ValidPolishREGON (app/Rules/) — use mod-10 and mod-11 respectively
- CartService::convertToOrder() calculates deposit_amount from service.deposit_amount × qty per item
- For business customers: invoice_requested always forced to true in service layer
- save_to_profile in checkout payload → CartService::saveProfileData() persists back to User
- CheckoutController::show() now passes $profileData to view for Alpine pre-fill

## Tenant Lifecycle Audit Log (Faza 5.5+5.6)
- OrganizationLifecycleLog: DURABLE, no FK, no BelongsToOrganization — survives org hard-delete
- OrganizationLifecycleLog::record($org, $event, $actor, $context) static helper — use everywhere
- closure_requested_at direct assignment: $org->closure_requested_at = now(); $org->save() (NOT fillable)
- OrganizationObserver::updating() only fires on lifecycle_state change — closure_requested_at saves safely
- SettingsManager::closureRequestEmail() — falls back to contactInformation()['email']
- Notifications to super-admins: NotificationFacade (aliased) + User::role('super-admin')->get()
- SystemSettings has Filament Notification alias conflict: `use Illuminate\Support\Facades\Notification as NotificationFacade`
- Worktree test isolation: copy new files to main app + run migrate to execute tests from worktree

## Testing
- Tests run in Docker only (PHP 8.3, local=8.2)
- .env.testing MUST exist → DB_CONNECTION=sqlite, DB_DATABASE=:memory:
- 7 pre-existing failures: BookingServiceArea(4) + TenantFeature(1) + CustomerOrdersTest(2)
- CheckoutFlowTest::validCheckoutPayload() updated to include customer_type, legal acceptances, PESEL, address

## Hooks
- [feedback_stop_hook_stderr.md](feedback_stop_hook_stderr.md) — Stop/SubagentStop hooks capture stderr only; all echo must use >&2

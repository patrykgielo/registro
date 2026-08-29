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

## Offline Settlement Mode (feature/offline-settlement-mode — 2026-08-16)
- [project_offline_settlement_mode.md](project_offline_settlement_mode.md) — Faza 1 of payment-settlement-modes.md: pay-at-pickup checkout, `orders.settlement_method`, `OrderService::recordOfflinePayment()`, TTL decoupling needed ZERO scope changes (write-path only), new TemplateKey needs its own production migration (documented in migrations.md now). Faza 1a superseded 2026-08-22: seeder approach reverted, default flipped straight in `SettingsManager::isOfflineSettlementEnabled()` — covers existing tenants too, broke the `['online']` fail-safe several unrelated Feature tests relied on. Same-day follow-up: that flip was missing from the working tree (verify code memories with git, don't trust them), and `->default(true)` on the Filament Toggle is a NO-OP in this codebase — real fix is `afterStateHydrated` + raw `SettingsManager::get()`, see the file

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

## Wielooddziałowość / Lokalizacje (Faza 1 kroki 1.1/1.2/1.6, 2026-08-27)
- [project_lokalizacje_faza1_kroki_1_1_1_2_1_6.md](project_lokalizacje_faza1_kroki_1_1_1_2_1_6.md) — branch `feature/lokalizacje-encja` (README miał złą nazwę gałęzi). `primary_slot` mechanizm to Observer (nie `booted()`) bo koordynuje DWA wiersze; `updating()` demotes stary → 2 kolejne commity, NIE jedna transakcja (świadomie, `return false`+`saveQuietly()` psułby `save()`'s return value dla Filamenta) — pełna atomowość w `Location::promoteToPrimary()`, tej ma użyć przyszła akcja 1.3. Backfill `down()` wymagał `created_at=updated_at`, samo name/slug match nie wystarczało
- [project_location_slug_unique_hotfix.md](project_location_slug_unique_hotfix.md) — hotfix 2026-08-27: backfill dał każdemu tenantowi ten sam slug, `LocationForm`'s `->unique()` był global zamiast `organization_id`-scoped → nikt nie mógł zapisać edycji swojej siedziby. Naprawa + `modifyRuleUsing` pattern w `filament-resources.md`; 7 innych zasobów miało ten sam bug (znalezione, naprawione dzień później — [[project_tenant_scoped_slug_validation_rollout]]). Osobne, większe odkrycie: `APP_FALLBACK_LOCALE=pl` bez `lang/pl/validation.php` = surowe klucze walidacji w całej apce od zawsze, prawdopodobnie też na produkcji
- [project_tenant_scoped_slug_validation_rollout.md](project_tenant_scoped_slug_validation_rollout.md) — 2026-08-28, branch `fix/tenant-scoped-slug-validation`: ten sam bug naprawiony w 7 zasobach (services/categories/pages/posts/portfolio/promotions/rental_categories) przez wspólny `TenantScopedUniqueRule::forCurrentTenant()` helper zamiast 7 kopii closure; `CategoryResource` testowany przez `callTableAction` (brak dedykowanych stron Edit/Create)
- [project_lokalizacje_faza2_stan_magazynowy.md](project_lokalizacje_faza2_stan_magazynowy.md) — Faza 2 (2026-08-28, branch `feature/lokalizacje-stan-magazynowy`): `service_location_stocks` kotwica, `quantity_total` mirror. `location_id` FK musi być `cascadeOnDelete` (nie `restrictOnDelete`) bo `locations.organization_id` już jest cascade — restrict raciłby dwie siostrzane kaskady bez gwarantowanej kolejności. `Service::factory()->itemRental()->for($org,...)` **NIE działa** — `for()` zawsze aplikuje się PIERWSZY, późniejszy state z tym samym kluczem (jak `itemRental()`'s `organization_id`) go nadpisuje; poprawny wzorzec to `->create(['organization_id' => $org->id])`. **2 blokery code-reviewera naprawione tego samego dnia:** `service_id` FK też musiał być cascade (restrict = niemożliwe usunięcie usługi ze stanem — zero testów deletion w repo dla `ServiceResource` do tego dnia); samonapędzająca się inflacja `quantity_total` przy dezaktywacji drugiego oddziału naprawiona per-SERVICE guardem, NIE samym `handle()`-only fixem — ten drugi wariant naprawia SUM, ale rozjeżdża `quantity_total` z mirrorem (pole zostaje `dehydrated`, `handle()` cicho odmawia)

## Tenant-Scoped Storage URL (2026-08-29, branch fix/tenant-scoped-storage-url)
- [project_tenant_scoped_storage_url.md](project_tenant_scoped_storage_url.md) — `config/filesystems.php`'s public disk URL was frozen at `APP_URL` at config-load; `ResolveTenant::forceTenantOriginUrls()` now also sets it per-request (both branches) alongside the existing `URL::forceRootUrl()`. Fixed a FilePond preview hang (CORS, `fetch()` vs plain `<img>`) on shared-stack tenant subdomains; confirmed safe for php-fpm (no Octane), queue/CLI, and `/platform` (never runs `ResolveTenant`)

## Testing
- Tests run in Docker only (PHP 8.3, local=8.2)
- .env.testing MUST exist → DB_CONNECTION=sqlite, DB_DATABASE=:memory:
- develop baseline (post VULN-003 Layers 1-3, as of 2026-07-03): 740 passed / 3 pre-existing failed (CustomerOrdersTest×2 + TenantFeatureTest×1) / 4 skipped — see [project_vuln003_layer2.md](project_vuln003_layer2.md) for the Layer 2-4 mechanism + test pattern
- CheckoutFlowTest::validCheckoutPayload() updated to include customer_type, legal acceptances, PESEL, address
- `email_templates` is intentionally global/NULL-organization_id (migration `2026_06_29_120000_fix_tenant_scoped_unique_constraints` skips it) but `EmailTemplate` still uses `BelongsToOrganization` — any test with a real resolved tenant that triggers a templated notification needs `Notification::fake()` or it 500s with "template not found" (root cause of CustomerOrdersTest's 2 pre-existing failures)

## Notifications inside DB::transaction() (feature/queue-after-commit — 2026-08-08)
- Fixed 3 sites in RentalExtensionService (requestExtension/approve/reject): notify() called inside DB::transaction(), all 4 queue connections have after_commit=false → fired before commit, survived rollback. Fix: notification classes implement ShouldQueueAfterCommit (declarative, not moving notify() calls — see app/docs/features/rental-extension.md for why)
- StartOrganizationOffboarding is the OTHER correct pattern (narrow transaction, notify() already outside it) — don't confuse the two; ShouldQueueAfterCommit is for when the transaction is wide and the payload depends on data mutated inside it
- Notification::fake()/Queue::fake() CANNOT prove/disprove this fix — both bypass SendQueuedNotifications/Queue::push()/shouldDispatchAfterCommit() entirely. Must mock the real terminal boundary (EmailGatewayInterface, or EmailService one level up when a real tenant is resolved in-test — see next bullet) and force a rollback via an outer DB::transaction() in the test
- RefreshDatabase's own DatabaseTransactionsManager (Illuminate\Foundation\Testing\DatabaseTransactionsManager) treats level 1 as commit-root, so ShouldQueueAfterCommit callbacks fire correctly inside RefreshDatabase tests without special setup — full mechanism + test pattern in .claude/rules/notifications.md
- Reconfirmed: EmailTemplate's BelongsToOrganization scope excludes seeded global (organization_id=NULL) templates once a real tenant is resolved in a test → real sendFromTemplate() 500s "template not found"; mock EmailService itself (not just the gateway) in tests that resolve a real tenant

## Hooks
- [feedback_stop_hook_stderr.md](feedback_stop_hook_stderr.md) — Stop/SubagentStop hooks capture stderr only; all echo must use >&2

## VULN-003 Layer 2 (2026-07-03)
- [project_vuln003_layer2.md](project_vuln003_layer2.md) — BelongsToOrganization fail-closed hardening, tenant_resolution_attempted mechanism, test-fix patterns

## Password Setup TTL + Public Wizard Removal (2026-08-08)
- [project_setup_ttl_and_wizard_removal.md](project_setup_ttl_and_wizard_removal.md) — `User::PASSWORD_SETUP_TTL_HOURS=24` (not config, single source of truth); public `/register` self-serve wizard removed entirely (CLI-only provisioning now); `TenantRegistered` dispatched from `registro:tenant-provision`, mail off critical path
- [feedback_pendingcommand_lazy_execution.md](feedback_pendingcommand_lazy_execution.md) — `$this->artisan()` result assigned to a variable defers execution to `__destruct()`; chain in one statement or call `->run()` explicitly before asserting DB state

## Runbook Completeness (2026-08-09, branch feature/runbook-completeness)
- [project_runbook_completeness.md](project_runbook_completeness.md) — new `registro:password-setup-link` command; offboarding (Część 7) + restore-from-backup (Część 8) procedures added to `instalacja-tenanta-od-zera.md`. Key finds: `docker-compose.prod.yml`'s top-level `name:` key (not cwd) drives volume naming; `sync-certificate.sh` dies platform-wide if a stack dir outlives its containers; legal retention survives teardown only via the restic backup (nothing else does)

## PESEL Per-Tenant Toggle (2026-08-17, branch feature/pesel-per-tenant-toggle)
- [project_pesel_per_tenant_toggle.md](project_pesel_per_tenant_toggle.md) — `checkout.pesel_required` (default false), `Rule::requiredIf(closure)` pattern; found but did NOT fix: `signatory_id_number` (B2B) is an unvalidated PESEL side-door

## Security Dependency Updates (2026-08-16, PR #200, branch feature/security-dependency-updates)
- [project_security_dependency_updates_2026-08-16.md](project_security_dependency_updates_2026-08-16.md) — composer audit 35→0, 11 packages, no composer.json changes. Reusable verification pattern: dompdf via git-stash-on-lock-only + real PDF pdftotext diff; guzzle/commonmark risk via grepping actual usage against the specific CVE, not assuming exposure

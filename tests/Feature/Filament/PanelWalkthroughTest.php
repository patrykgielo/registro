<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Actions\Onboarding\ProvisionTenantOrganization;
use App\Actions\Onboarding\Seeders\SeedEquipmentRental;
use App\Enums\Industry;
use App\Enums\TemplateKey;
use App\Models\Appointment;
use App\Models\CarBrand;
use App\Models\CarModel;
use App\Models\EmailTemplate;
use App\Models\Location;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Page;
use App\Models\PortfolioItem;
use App\Models\Post;
use App\Models\Promotion;
use App\Models\ReminderConfig;
use App\Models\Rental;
use App\Models\RentalCategory;
use App\Models\Service;
use App\Models\ServiceArea;
use App\Models\SmsTemplate;
use App\Models\StaffDateException;
use App\Models\StaffSchedule;
use App\Models\StaffVacationPeriod;
use App\Models\User;
use App\Models\VehicleType;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Data-driven walkthrough of every resource registered on the `admin` panel — the "click
 * through the panel" layer that would have caught all four blockers found by hand in this
 * session (bare `->unique(ignoreRecord: true)` on Service/RentalCategory slugs and friends,
 * quantity-inflation-on-no-op-save, restrictOnDelete making a service undeletable): none of
 * them needed NEW code to reproduce, only exercising EXISTING code the way a human clicking
 * "Zapisz" on an unchanged record does. 1589 green tests on develop before this file all
 * tested hypotheses about new code; nothing asked "does a no-op save on an existing record
 * still work".
 *
 * Enumerates `Filament::getPanel('admin')->getResources()` (35 at the time this was written)
 * instead of listing them by hand, so a resource added in a future phase is covered
 * automatically — the whole point is not writing test #36 by hand.
 *
 * For EVERY resource with an `edit` page the walkthrough is accessible to (see EXEMPT_CANVIEW
 * below):
 *   - `index` page loads (catches authorization/query errors)
 *   - an EXISTING record opens on `edit` and saves with ZERO changes -> assertHasNoFormErrors()
 *     (this is the check that catches a global `->unique(ignoreRecord: true)` on a column whose
 *     real DB constraint is `UNIQUE(organization_id, column)` -- two tenants seeded IDENTICALLY,
 *     see setUp(), so the SAME slug exists on both, exactly like two tenants both getting
 *     "siedziba-glowna" from the real backfill migration did in production)
 *   - that same no-op save does not mutate the row (full attribute diff, not just "no
 *     exception") -- this is the check that would have caught the quantity-inflation bug
 *   - a freshly created MINIMAL record (created directly via the model, not through the
 *     Filament create form -- see "Scope decision" below) is deleted through the resource's
 *     own header DeleteAction, and the only failure mode counted is an UNCAUGHT exception
 *     escaping the Livewire call -- a graceful "cannot delete, in use" notification is a
 *     legitimate guard, not a bug; a raw QueryException bubbling out of a `restrictOnDelete`
 *     FK is exactly blocker 4's shape and is NOT graceful.
 *
 * Two tenants are provisioned through the REAL onboarding building blocks
 * (ProvisionTenantOrganization + SeedEquipmentRental), seeded IDENTICALLY -- not two random
 * factory orgs -- because it is the COLLISION between two tenants sharing a slug that made
 * blockers 1/2 (unique ignoreRecord: true) load-bearing in production. A single tenant with
 * random Faker data would never produce that collision.
 *
 * Scope decision on the CREATE step: the task as briefed asks for "utworzenie minimalnego
 * rekordu -> usunięcie". This walkthrough creates that minimal record directly via the model
 * (Eloquent create / an existing factory), not by filling in each of the 21 different
 * Filament CREATE forms field-by-field. Reasons: (1) blocker 3 (quantity inflation) and
 * blocker 1/2 (unique scope) are both EDIT-time bugs, already fully covered by the no-op-save
 * check above; the only thing the CREATE step adds is blocker-4-style DELETE coverage, which
 * needs a record to exist, not a form to have been used to create it; (2) hand-mapping valid
 * minimal `fillForm()` data for 21 structurally different forms (nested repeaters, file
 * uploads, nullable-vs-required per business type) would multiply this file's size several
 * times over for a check whose value is concentrated entirely in the DELETE half; (3) it
 * removes an entire class of false positives -- a walkthrough that fails because ITS OWN
 * guessed form data was invalid would be indistinguishable from a real regression. Team lead
 * asked to be told plainly where the delivered scope differs from the brief -- this is that
 * disclosure, not a silent narrowing.
 */
class PanelWalkthroughTest extends TestCase
{
    use RefreshDatabase;

    /**
     * canViewAny() for a genuine `admin`-tenant walkthrough legitimately returns false for
     * these five -- confirmed by reading each resource's own canViewAny(), not assumed:
     *
     * - RoleResource: `hasRole('super-admin')` only (own docblock: roles are global,
     *   `config('permission.php').teams === false`, editing one changes what EVERY tenant's
     *   admins can do -- a platform, not tenant, concern).
     * - EmailSuppressionResource / SmsSuppressionResource: `hasRole('super-admin')` only
     *   (global suppression lists, not per-tenant data).
     * - ServiceAreaWaitlistResource: `hasRole('super-admin')` only (own docblock: the model
     *   has NO organization_id at all -- a single "outside area" submission can apply to
     *   several nearby tenants at once, so it deliberately has no single tenant owner;
     *   opening it to tenant admins would leak name/email/phone/GPS across tenants).
     * - MaintenanceEventResource: `hasRole('super-admin')` only, AND only has `index`+`view`
     *   pages (no `edit` at all) -- listed here for completeness, the "has edit page" filter
     *   below would have skipped it anyway.
     *
     * A tenant `admin` hitting any of these gets Filament's standard 403, which is the
     * CORRECT behaviour, not a finding. Running this walkthrough a second time as
     * super-admin to cover these five is a reasonable follow-up, not done here -- the brief's
     * scenario ("otwórz istniejący rekord... admin tenanta") is specifically about the tenant
     * admin path these five are deliberately not on.
     */
    private const EXEMPT_CANVIEW = [
        \App\Filament\Resources\RoleResource::class,
        \App\Filament\Resources\EmailSuppressionResource::class,
        \App\Filament\Resources\SmsSuppressionResource::class,
        \App\Filament\Resources\ServiceAreaWaitlists\ServiceAreaWaitlistResource::class,
        \App\Filament\Resources\MaintenanceEventResource::class,
    ];

    /**
     * create+delete is skipped for these two even though both have a working `edit` page:
     *
     * - OrderResource: `getPages()` has no `create` key AND `EditOrder` has no `DeleteAction`
     *   header action (verified by reading both files) -- orders are legal records
     *   (migrations.md: restrictOnDelete, must survive >=5-6yrs), deliberately undeletable
     *   from the panel at all. Nothing to exercise.
     * - CustomerResource: `getEloquentQuery()` only surfaces a User that
     *   `whereHas('rentalsAsCustomer'|'orders'|'customerAppointments', ...)` in this tenant --
     *   a synthetic "minimal" customer with none of those would 404 on mount, never reaching
     *   the delete step at all. Giving it one to satisfy that query means its deletion is
     *   guarded by the SAME `restrictOnDelete` FK (rentals.customer_id / orders.user_id are
     *   legal records) this walkthrough's own pass/fail rule treats as "legitimate guard, not
     *   a bug" for every OTHER resource -- exercising it here would produce a false positive
     *   this file cannot tell apart from a real regression without customer-specific
     *   knowledge. Existing-record edit-no-op-save IS still covered below.
     */
    private const EXEMPT_CREATE_DELETE = [
        \App\Filament\Resources\OrderResource::class,
        \App\Filament\Resources\CustomerResource::class,
        // canDelete() is `hasRole('super-admin')` only (UserResource.php) -- a tenant admin can
        // create/edit accounts (canCreate() IS hasAnyRole(['admin','super-admin'])) but never
        // delete one. Existing-record edit-no-op-save IS still covered below, and it found a
        // real bug (see report) -- this exemption is scoped to delete only.
        \App\Filament\Resources\UserResource::class,
    ];

    /**
     * `canViewAny()` admits a tenant admin (`hasAnyRole(['admin', 'super-admin'])`, confirmed by
     * reading each file) so the index check runs, but `canEdit()`/`canCreate()`/`canDelete()` on
     * all three are `hasRole('super-admin')` only -- confirmed by reading each resource's own
     * guards, and matching this repo's own models.md rule ("VehicleType/CarBrand/CarModel →
     * read-only dla non-super-admin (subsystem being removed, do not promote)"). Mounting
     * EditRecord for a record `canEdit()` rejects does not 403 cleanly -- Filament aborts before
     * the Livewire component finishes mounting, which this walkthrough's own Livewire::test()
     * call surfaces as an opaque "Invalid Livewire snapshot structure" instead of a clean
     * assertion failure, confirmed empirically while building this file. Index-only for these
     * three, same reasoning as EXEMPT_CANVIEW but one level less restrictive.
     */
    private const EXEMPT_EDIT_CREATE_DELETE = [
        \App\Filament\Resources\CarBrandResource::class,
        \App\Filament\Resources\CarModelResource::class,
        \App\Filament\Resources\VehicleTypeResource::class,
    ];

    private Organization $tenantA;

    private Organization $tenantB;

    private User $adminA;

    private User $staffUserA;

    private User $customerUserA;

    private CarBrand $carBrandA;

    private Service $timeSlotServiceA;

    private VehicleType $vehicleTypeA;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['super-admin', 'admin', 'staff', 'customer'] as $name) {
            Role::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        $provision = app(ProvisionTenantOrganization::class);
        $seedVertical = app(SeedEquipmentRental::class);

        $resultA = $provision->execute(
            slug: 'walkthrough-tenant-a',
            name: 'Wypożyczalnia Testowa',
            industry: Industry::EquipmentRental,
            ownerEmail: 'owner-a@walkthrough.test',
            ownerFirstName: 'Ada',
            ownerLastName: 'Testowa',
        );
        $this->tenantA = $resultA['organization'];
        $this->adminA = $resultA['owner'];

        $resultB = $provision->execute(
            slug: 'walkthrough-tenant-b',
            name: 'Wypożyczalnia Testowa', // deliberately IDENTICAL name to tenant A
            industry: Industry::EquipmentRental,
            ownerEmail: 'owner-b@walkthrough.test',
            ownerFirstName: 'Bob',
            ownerLastName: 'Testowy',
        );
        $this->tenantB = $resultB['organization'];

        // Identical vertical catalog for both -- SeedEquipmentRental's category/item names are
        // a fixed, hardcoded list (app/Actions/Onboarding/Seeders/SeedEquipmentRental.php), so
        // this alone produces 7 categories + 13 services per tenant with IDENTICAL names, and
        // both RentalCategory and Service auto-slug from name on create() -- guaranteed
        // cross-tenant slug collisions, no manual slug-setting needed.
        $seedVertical->seed($this->tenantA);
        $seedVertical->seed($this->tenantB);

        // Identical primary location name/slug too -- this is the exact production incident
        // shape (2026_08_27_120001_backfill_primary_location_for_organizations gave every
        // tenant's primary branch the literal slug "siedziba-glowna"). LocationObserver
        // auto-promotes the first location of an org to primary.
        // 'phone' pinned explicitly -- known flaky fixture (test-engineer memory:
        // LocationFactory's fake()->phoneNumber() is en_US locale and occasionally generates an
        // "x1234" extension that fails the form's ->tel() validation; reproduced once while
        // building this file as a spurious "phone field format is invalid" on a no-op save).
        Location::factory()->create([
            'organization_id' => $this->tenantB->id,
            'name' => 'Siedziba główna',
            'slug' => 'siedziba-glowna',
            'phone' => '+48501234567',
        ]);
        Location::factory()->create([
            'organization_id' => $this->tenantA->id,
            'name' => 'Siedziba główna',
            'slug' => 'siedziba-glowna',
            'phone' => '+48501234568',
        ]);

        $this->staffUserA = User::factory()->create();
        $this->staffUserA->assignRole('staff');
        $this->staffUserA->organizations()->attach($this->tenantA->id, ['role' => 'staff']);

        $seededService = Service::where('organization_id', $this->tenantA->id)->firstOrFail();

        $this->customerUserA = User::factory()->create();
        $this->customerUserA->assignRole('customer');
        Rental::factory()->create([
            'organization_id' => $this->tenantA->id,
            'customer_id' => $this->customerUserA->id,
            'service_id' => $seededService->id,
        ]);

        $this->carBrandA = CarBrand::create([
            'name' => 'Testmark',
            'slug' => 'testmark-'.uniqid(),
        ]);

        // AppointmentResource's service_id belongs to a service, and services carry their own
        // organization_id -- reuse one real time-slot service scoped to tenant A rather than the
        // unrelated cross-org service Appointment::factory() would default to.
        $this->timeSlotServiceA = Service::factory()->create(['organization_id' => $this->tenantA->id]);

        // Memoized for the same reason: VehicleTypeFactory picks from only 5 fixed names with a
        // globally unique slug, and Appointment::factory()'s own default 'vehicle_type_id' =>
        // VehicleType::factory() would otherwise create a fresh random one on every call,
        // eventually colliding with VehicleTypeResource's own fixtures.
        $this->vehicleTypeA = VehicleType::factory()->create();

        // Same collision shape as the primary location above, for the 4 other resources whose
        // source read the same way (bare `->unique(ignoreRecord: true)` on a column whose real
        // DB constraint is UNIQUE(organization_id, slug) -- confirmed in PageResource.php:63,
        // PostResource.php:63, PromotionResource.php:62, PortfolioItemResource.php:62, plus
        // CategoryResource.php:57, which this walkthrough cannot reach at all, see report).
        // Tenant B gets an identically-titled row so tenant A's own existingRecordFor() row
        // (created lazily, same literal title, below) collides on slug the same way the seeded
        // catalog and the primary location already do.
        Page::create(['organization_id' => $this->tenantB->id, 'title' => 'Strona istniejąca']);
        Post::create(['organization_id' => $this->tenantB->id, 'title' => 'Wpis istniejący', 'body' => '<p>Treść wpisu</p>']);
        Promotion::create(['organization_id' => $this->tenantB->id, 'title' => 'Promocja istniejąca', 'body' => '<p>Treść promocji</p>']);
        PortfolioItem::create(['organization_id' => $this->tenantB->id, 'title' => 'Realizacja istniejąca']);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        session(['tenant_id' => $this->tenantA->id]);
        $this->actingAs($this->adminA);
    }

    public function test_every_admin_panel_resource_survives_a_no_op_save_and_a_minimal_create_delete(): void
    {
        $violations = [];
        $checked = [];

        foreach (Filament::getPanel('admin')->getResources() as $resourceClass) {
            $short = class_basename($resourceClass);
            $checked[] = $short;

            $this->walkResource($resourceClass, $short, $violations);
        }

        $this->assertNotEmpty($checked, 'Filament::getPanel(admin)->getResources() returned nothing -- enumeration itself is broken.');

        if ($violations !== []) {
            $this->fail(
                count($violations)." problem(s) found across the admin panel:\n\n"
                .implode("\n\n", $violations)
            );
        }
    }

    /**
     * @param  list<string>  $violations
     */
    private function walkResource(string $resourceClass, string $short, array &$violations): void
    {
        $pages = $resourceClass::getPages();

        // --- index ---------------------------------------------------------------------
        if (in_array($resourceClass, self::EXEMPT_CANVIEW, true)) {
            // Confirm the exemption is still true rather than trusting the docblock forever:
            // if canViewAny() is ever loosened to admit tenant admins, this walkthrough should
            // start covering it instead of silently skipping a resource that no longer needs
            // the exemption.
            if ($resourceClass::canViewAny()) {
                $violations[] = "{$short}: listed in EXEMPT_CANVIEW as super-admin-only, but canViewAny() now returns true for a tenant admin -- remove the exemption, this resource should be walked.";
            }

            return;
        }

        if (! array_key_exists('index', $pages)) {
            $violations[] = "{$short}: has no 'index' page at all -- every registered resource is expected to have one.";

            return;
        }

        try {
            Livewire::test($pages['index']->getPage())->assertSuccessful();
        } catch (\Throwable $e) {
            // Deliberately does NOT `return` here -- an index crash is its own finding, but it
            // must not silently suppress the edit/create/delete checks below for the same
            // resource (they mount a different Livewire component and do not depend on the
            // index page having worked).
            $violations[] = "{$short}: index page threw ".$e::class.': '.$e->getMessage();
        }

        if (! array_key_exists('edit', $pages)) {
            // Index-only (or index+view, e.g. EmailSendResource/SmsSendResource/
            // AuditLogResource/EmailEventResource/SmsEventResource) or a Manage*-style page
            // combining CRUD into one route (CategoryResource) -- nothing more this generic,
            // route-keyed harness can exercise. See report for CategoryResource: same class of
            // bug found by direct source read, not exercised here.
            return;
        }

        if (in_array($resourceClass, self::EXEMPT_EDIT_CREATE_DELETE, true)) {
            $record = $this->existingRecordFor($short);

            if ($record !== null && $resourceClass::canEdit($record)) {
                $violations[] = "{$short}: listed in EXEMPT_EDIT_CREATE_DELETE as super-admin-only, but canEdit() now returns true for a tenant admin -- remove the exemption, this resource should be walked.";
            }

            return;
        }

        $existing = $this->existingRecordFor($short);

        if ($existing === null) {
            $violations[] = "{$short}: has an 'edit' page but this walkthrough has no fixture for it -- add one to existingRecordFor().";

            return;
        }

        // fresh(), not the in-memory $existing straight out of create(): a column with a DB-level
        // ->default(...) that this fixture didn't set explicitly is genuinely absent from the
        // in-memory model's own attribute array (Eloquent never round-trips a DB DEFAULT back
        // onto the object that triggered the INSERT) even though the row landed with a real
        // value. Diffing against that in-memory snapshot manufactured a false "mutation" for
        // every such column on every resource, independent of anything the save actually did --
        // caught by reproducing it against CustomerResource/PageResource/ReminderConfigResource
        // while building this file, all "<absent> -> $dbDefault", not a genuine before/after.
        $beforeAttributes = $existing->fresh()->getAttributes();

        try {
            $editComponent = Livewire::test($pages['edit']->getPage(), ['record' => $existing->getRouteKey()])
                ->call('save');
        } catch (\Throwable $e) {
            $violations[] = "{$short}: opening/saving an UNCHANGED existing record threw ".$e::class.': '.$e->getMessage();

            return;
        }

        if ($editComponent->errors()->isNotEmpty()) {
            $violations[] = "{$short}: saving an existing record WITH NO CHANGES produced form errors: "
                .json_encode($editComponent->errors()->toArray());
        }

        $afterAttributes = $existing->fresh()->getAttributes();
        unset($beforeAttributes['updated_at'], $afterAttributes['updated_at']);

        // Plain `!==` on the two arrays is not enough: PHP's array equality for `!==` is also
        // order-sensitive, and `fresh()` can legitimately rebuild the attribute array in a
        // different key order than the original without a single value having changed (bit for
        // bit reproduced against ServiceAreaResource while building this file -- it reported an
        // EMPTY diff via array_diff_assoc despite `!==` firing, i.e. order, not content, was the
        // only difference). Comparing key-by-key across the union of both key sets is order-
        // independent and also surfaces a key present on only one side.
        $keyUnion = array_unique([...array_keys($beforeAttributes), ...array_keys($afterAttributes)]);
        $diff = [];
        foreach ($keyUnion as $key) {
            $before = $beforeAttributes[$key] ?? '<absent>';
            $after = $afterAttributes[$key] ?? '<absent>';

            if ((string) $before !== (string) $after) {
                $diff[$key] = ['before' => $before, 'after' => $after];
            }
        }

        if ($diff !== []) {
            $violations[] = "{$short}: saving an existing record WITH NO CHANGES mutated it. Changed columns: "
                .json_encode($diff);
        }

        // --- create + delete -------------------------------------------------------------
        if (in_array($resourceClass, self::EXEMPT_CREATE_DELETE, true)) {
            return;
        }

        $fresh = $this->newRecordFor($short);

        if ($fresh === null) {
            $violations[] = "{$short}: has an 'edit' page but this walkthrough has no create-fixture for it -- add one to newRecordFor().";

            return;
        }

        try {
            Livewire::test($pages['edit']->getPage(), ['record' => $fresh->getRouteKey()])
                ->callAction('delete');
        } catch (\Throwable $e) {
            $violations[] = "{$short}: deleting a freshly created, unused record threw ".$e::class.': '.$e->getMessage()
                .' (a graceful "cannot delete" notification is fine; an uncaught exception, e.g. from a restrictOnDelete FK, is blocker-4-shaped and IS a finding).';
        }
    }

    private function existingRecordFor(string $short): ?Model
    {
        $org = $this->tenantA;

        return match ($short) {
            'ServiceResource' => Service::where('organization_id', $org->id)->firstOrFail(),
            'RentalCategoryResource' => RentalCategory::where('organization_id', $org->id)->firstOrFail(),
            'LocationResource' => Location::where('organization_id', $org->id)->firstOrFail(),
            'CarBrandResource' => $this->carBrandA,
            'CarModelResource' => CarModel::create([
                'car_brand_id' => $this->carBrandA->id,
                'name' => 'Model Istniejący',
                'slug' => 'model-istniejacy',
            ]),
            'VehicleTypeResource' => $this->vehicleTypeA,
            'ServiceAreaResource' => ServiceArea::factory()->create(['organization_id' => $org->id]),
            'RentalResource' => Rental::where('organization_id', $org->id)->firstOrFail(),
            'OrderResource' => Order::factory()->create(['organization_id' => $org->id]),
            'AppointmentResource' => Appointment::factory()->create([
                'organization_id' => $org->id,
                'staff_id' => $this->staffUserA->id,
                'service_id' => $this->timeSlotServiceA->id,
                'vehicle_type_id' => $this->vehicleTypeA->id,
                // AppointmentResource's customer_id Select only offers tenant customers
                // (mirrors CustomerResource's own scoping) -- the factory's own default
                // (a bare new User::factory(), no 'customer' role, no org tie) is not among
                // them, which surfaced as a "The selected klient is invalid" form error.
                'customer_id' => $this->customerUserA->id,
            ]),
            'UserResource' => tap(
                User::factory()->create(),
                fn (User $u) => $u->organizations()->attach($org->id, ['role' => 'admin'])
            ),
            'EmployeeResource' => $this->staffUserA,
            'CustomerResource' => $this->customerUserA,
            'StaffScheduleResource' => StaffSchedule::create([
                'organization_id' => $org->id,
                'user_id' => $this->staffUserA->id,
                'day_of_week' => 1,
                'start_time' => '09:00',
                'end_time' => '17:00',
            ]),
            'StaffDateExceptionResource' => StaffDateException::create([
                'organization_id' => $org->id,
                'user_id' => $this->staffUserA->id,
                'exception_date' => now()->addDays(5)->toDateString(),
                'exception_type' => 'unavailable',
            ]),
            'StaffVacationPeriodResource' => StaffVacationPeriod::create([
                'organization_id' => $org->id,
                'user_id' => $this->staffUserA->id,
                'start_date' => now()->addDays(10)->toDateString(),
                'end_date' => now()->addDays(15)->toDateString(),
            ]),
            // Both key fields are a restricted Select (TemplateKey::optionsForChannel()), not a
            // free-text input -- an arbitrary string like 'walkthrough-existing' fails form
            // validation ("selected klucz szablonu is invalid"). USER_REGISTERED/PASSWORD_RESET
            // and APPOINTMENT_CREATED/APPOINTMENT_CONFIRMED are real, deliberately NOT
            // TENANT_WELCOME/TENANT_REGISTERED_OPERATOR -- see the report: TemplateKey::label()
            // has no match arm for either and throws, a real pre-existing bug this walkthrough
            // must not itself trip over while building an unrelated fixture.
            'EmailTemplateResource' => EmailTemplate::create([
                'organization_id' => $org->id,
                'key' => TemplateKey::USER_REGISTERED->value,
                'language' => 'pl',
                'subject' => 'Temat',
                'html_body' => '<p>Treść</p>',
                'variables' => [],
            ]),
            'SmsTemplateResource' => SmsTemplate::create([
                'organization_id' => $org->id,
                'key' => TemplateKey::APPOINTMENT_CREATED->value,
                'language' => 'pl',
                'message_body' => 'Treść SMS',
                'variables' => [],
            ]),
            'ReminderConfigResource' => ReminderConfig::create([
                'organization_id' => $org->id,
                'name' => 'Istniejące przypomnienie',
                'channel' => 'sms',
                'template_key' => \App\Enums\TemplateKey::APPOINTMENT_REMINDER_24H->value,
            ]),
            'PageResource' => Page::create([
                'organization_id' => $org->id,
                'title' => 'Strona istniejąca',
            ]),
            // Same "form requires it, DB allows null" gap as Promotion's body -- see its comment.
            'PostResource' => Post::create([
                'organization_id' => $org->id,
                'title' => 'Wpis istniejący',
                'body' => '<p>Treść wpisu</p>',
            ]),
            // Promotion's form requires 'body' (RichEditor) even though the column is nullable
            // in the DB -- every real promotion created through the form always has one, so
            // leaving it null here would be a false positive from this fixture, not a real bug.
            'PromotionResource' => Promotion::create([
                'organization_id' => $org->id,
                'title' => 'Promocja istniejąca',
                'body' => '<p>Treść promocji</p>',
            ]),
            'PortfolioItemResource' => PortfolioItem::create([
                'organization_id' => $org->id,
                'title' => 'Realizacja istniejąca',
            ]),
            default => null,
        };
    }

    private function newRecordFor(string $short): ?Model
    {
        $org = $this->tenantA;

        return match ($short) {
            'ServiceResource' => Service::factory()->itemRental()->create([
                'organization_id' => $org->id,
                'rental_category_id' => RentalCategory::where('organization_id', $org->id)->firstOrFail()->id,
            ]),
            // RentalCategoryFactory's fixed 8-name pool overlaps with SeedEquipmentRental's own
            // 7 category names (both include "Sprzęt ogrodniczy" etc.), and its 'slug' is
            // computed from the factory's OWN randomly-picked name, not an overridden 'name' --
            // override both explicitly so this doesn't randomly collide with the seeded catalog
            // already in tenant A.
            'RentalCategoryResource' => RentalCategory::factory()->create([
                'organization_id' => $org->id,
                'name' => $freshName = 'Nowa kategoria '.uniqid(),
                'slug' => \Illuminate\Support\Str::slug($freshName),
            ]),
            'LocationResource' => Location::factory()->create(['organization_id' => $org->id, 'phone' => '+48501234569']),
            'CarBrandResource' => CarBrand::create(['name' => 'Nowa marka', 'slug' => 'nowa-marka-'.uniqid()]),
            'CarModelResource' => CarModel::create([
                'car_brand_id' => $this->carBrandA->id,
                'name' => 'Nowy model',
                'slug' => 'nowy-model-'.uniqid(),
            ]),
            // VehicleType.slug is globally unique with only 5 fixed names in the factory --
            // override it so this doesn't randomly collide with existingRecordFor()'s own row.
            'VehicleTypeResource' => VehicleType::factory()->create(['slug' => 'vehicle-type-new-'.uniqid()]),
            'ServiceAreaResource' => ServiceArea::factory()->create(['organization_id' => $org->id]),
            'RentalResource' => Rental::factory()->create([
                'organization_id' => $org->id,
                'service_id' => Service::where('organization_id', $org->id)->where('service_type', \App\Enums\ServiceType::ItemRental)->firstOrFail()->id,
            ]),
            'AppointmentResource' => Appointment::factory()->create([
                'organization_id' => $org->id,
                'staff_id' => $this->staffUserA->id,
                'service_id' => $this->timeSlotServiceA->id,
                'vehicle_type_id' => $this->vehicleTypeA->id,
                // AppointmentResource's customer_id Select only offers tenant customers
                // (mirrors CustomerResource's own scoping) -- the factory's own default
                // (a bare new User::factory(), no 'customer' role, no org tie) is not among
                // them, which surfaced as a "The selected klient is invalid" form error.
                'customer_id' => $this->customerUserA->id,
            ]),
            'UserResource' => tap(
                User::factory()->create(),
                fn (User $u) => $u->organizations()->attach($org->id, ['role' => 'admin'])
            ),
            'EmployeeResource' => tap(User::factory()->create(), function (User $u) use ($org) {
                $u->assignRole('staff');
                $u->organizations()->attach($org->id, ['role' => 'staff']);
            }),
            'StaffScheduleResource' => StaffSchedule::create([
                'organization_id' => $org->id,
                'user_id' => $this->staffUserA->id,
                'day_of_week' => 2,
                'start_time' => '10:00',
                'end_time' => '18:00',
            ]),
            'StaffDateExceptionResource' => StaffDateException::create([
                'organization_id' => $org->id,
                'user_id' => $this->staffUserA->id,
                'exception_date' => now()->addDays(6)->toDateString(),
                'exception_type' => 'unavailable',
            ]),
            'StaffVacationPeriodResource' => StaffVacationPeriod::create([
                'organization_id' => $org->id,
                'user_id' => $this->staffUserA->id,
                'start_date' => now()->addDays(20)->toDateString(),
                'end_date' => now()->addDays(21)->toDateString(),
            ]),
            'EmailTemplateResource' => EmailTemplate::create([
                'organization_id' => $org->id,
                'key' => TemplateKey::PASSWORD_RESET->value,
                'language' => 'pl',
                'subject' => 'Temat',
                'html_body' => '<p>Treść</p>',
                'variables' => [],
            ]),
            'SmsTemplateResource' => SmsTemplate::create([
                'organization_id' => $org->id,
                'key' => TemplateKey::APPOINTMENT_CONFIRMED->value,
                'language' => 'pl',
                'message_body' => 'Treść SMS',
                'variables' => [],
            ]),
            'ReminderConfigResource' => ReminderConfig::create([
                'organization_id' => $org->id,
                'name' => 'Nowe przypomnienie',
                'channel' => 'sms',
                'template_key' => \App\Enums\TemplateKey::APPOINTMENT_REMINDER_2H->value,
            ]),
            'PageResource' => Page::create(['organization_id' => $org->id, 'title' => 'Nowa strona '.uniqid()]),
            'PostResource' => Post::create([
                'organization_id' => $org->id,
                'title' => 'Nowy wpis '.uniqid(),
                'body' => '<p>Treść wpisu</p>',
            ]),
            'PromotionResource' => Promotion::create([
                'organization_id' => $org->id,
                'title' => 'Nowa promocja '.uniqid(),
                'body' => '<p>Treść promocji</p>',
            ]),
            'PortfolioItemResource' => PortfolioItem::create(['organization_id' => $org->id, 'title' => 'Nowa realizacja '.uniqid()]),
            default => null,
        };
    }
}

# Tenant Branding — Logo Fallback Contract

**Fixed:** 2026-08-13, branch `feature/remove-foreign-branding`

---

## The bug

Every tenant who never uploaded a logo was showing **this codebase's previous owner's brand**
(`public/images/logo.svg`, a Paradocks asset present unchanged since the initial "migrated from
Paradocks codebase" commit, 2026-03-02) on every public page header/footer, on the Filament admin
panel, and on the pre-launch page.

`SettingsManager::headerLogo()`/`footerLogo()` never returned an empty value — they fell back to
`asset('images/logo.svg')` — so text fallbacks that were already written in the calling templates
(`@if($logo) <img> @else <span>{{ appName }}</span> @endif`) were dead code, never reached.
`resources/views/errors/maintenance-prelaunch.blade.php` had the same shape of bug via a
separate, never-configurable `contact.logo_path` setting key that a 2025-12-06 migration seeded to
the same placeholder for every tenant.

No test caught this because Feature tests assert on response content existing, not on which brand
identity renders when nothing is configured — and no one had opened the rendered page.

## The contract (now enforced)

`SettingsManager::headerLogo(): ?string` and `footerLogo(): ?string` return **`null`** — never a
bundled asset path — when the tenant hasn't uploaded a logo (`appearance.header_logo`/
`appearance.footer_logo` unset). Every caller MUST branch on that null and fall back to **text**
(the tenant's own name — `config('app.name')` or `SettingsManager::appName()`/`logoAlt()`), never to
another bundled image asset.

Callers implementing this contract:

| File | Fallback |
|------|----------|
| `resources/views/components/nav/header.blade.php` | `config('app.name')` text span |
| `resources/views/components/nav/footer.blade.php` | never had a logo — `config('app.name')` text only |
| `resources/views/components/ios/footer.blade.php` (legacy, unreferenced) | `SettingsManager::appName()` text |
| `resources/views/components/ios/nav-logo.blade.php` (legacy, unreferenced) | `logoAlt()` text |
| `resources/views/errors/maintenance-prelaunch.blade.php` | `logoAlt()` text, now sourced from `SettingsManager::headerLogo()`/`logoAlt()` instead of the dead `contact.logo_path` key |
| `app/Providers/Filament/AdminPanelProvider.php` `->brandLogo()`/`->darkModeBrandLogo()` | closures return `null` when unconfigured, letting Filament's own `->brandName('Registro Admin')` fallback render (`vendor/filament/filament/resources/views/components/logo.blade.php`) |

## Dead code — do not "fix" these files

Four files are confirmed dead — referenced **nowhere** in `resources/` or `app/`, by grep, not
assumption: three reusable `ios/*` components (`footer.blade.php`, `nav-logo.blade.php`,
`hero-banner.blade.php`) and one full page view behind a dead route
(`resources/views/booking/create.blade.php` — its only route, `booking.create`, is wired to
`BookingController::create()`, which only writes two session keys and redirects; nothing calls
`view('booking.create...')`). The live footer wired into `layouts/app.blade.php` is
`resources/views/components/nav/footer.blade.php`, which never rendered a logo at all (just
`config('app.name')` text) and was never affected by this bug.

All four carried this task's copy problem anyway and were fixed in place rather than deleted, for
defensive consistency — a future caller reusing one of these, or a future fix that makes
`booking/create.blade.php` reachable again, would otherwise inherit the exact bug this whole document
is about:

| File | What it carried | Fix |
|---|---|---|
| `ios/footer.blade.php` | Onerror-only logo fallback (same unreachable-fallback shape as the live bug), a "P"-badge gradient fallback, a hardcoded detailing tagline, a fake phone/email | Logo: `@if`-guarded like the live components. Tagline: removed. Phone/email: switched to `SettingsManager::contactInformation()` with an absent-means-hidden guard. |
| `ios/nav-logo.blade.php` | Same onerror-only logo fallback shape | Explicit `@if`/`@else` text fallback, matching the live header. |
| `ios/hero-banner.blade.php` | Hardcoded `@props` defaults: `title => 'Detailing jak z przyszłości'`, `subtitle => 'Profesjonalne usługi detailingowe dla Twojego auta'` | Both defaults set to `null`; `$title`'s render is now `@if`-guarded the same way `$subtitle`'s already was (that existing guard was itself unreachable, same bug shape as everywhere else in this document — the default made `$subtitle` always truthy). |
| `booking/create.blade.php` | `{{ $service->description ?? 'Profesjonalna usługa detailingowa' }}` | `@if($service->description)` guard, no hardcoded fallback. |

**Deliberately not deleted.** Removing four dead Blade files outright is a legitimate cleanup, but a
different change with a different risk profile than a branding fix — folding it into this branch would
make the diff harder to reason about and the revert coarser. Neutralising the copy in place, as done
here, is the scoped fix; deleting the files is a sensible follow-up someone should take as its own
deliberate change, not a byproduct of this one.

**The actual customer-facing bug you'd see in a screenshot came from `nav/header.blade.php`**, not
these four. If you're chasing a branding bug in the header/footer/hero and land in one of these files,
you're in the wrong place — check what `layouts/app.blade.php` (or whatever page you're looking at)
actually includes first.

### Final sweep result (resources/views/, case-insensitive, every phrase removed by this document)

Run at the end of this task, not assumed clean: `grep -rniI` across `resources/views/` for every
distinct string this document records having removed ("detailing" bare, "Mobilne Myjnie", "Tapicerki",
"Marszałkowska", `contact@example.com`, `+48123456789`, "terenie klienta", "wnętrza auta", "Parking
naziemny", "przyjeżdżamy do Ciebie", "60 sekund", "Umów wizytę już dziś", `kontakt@registro.local`).

Found one more this way, in `resources/views/booking/create.blade.php:102` — a hardcoded
`?? 'Profesjonalna usługa detailingowa'` fallback for `$service->description`, inside the same dead
file as the `important_info_points`/`map.*` false-live-claim above. Fixed: `@if($service->description)`
guard, no hardcoded fallback. Every other match left after that fix is inside an explanatory comment
describing a prior removal (this document's own text, and the comments left in the fixed Blade files),
not live copy.

A parallel sweep of `app/` (Filament Resources, seeders, console commands) surfaced "detailing" and
"Marszałkowska" too, but none of it is this bug: `Industry::AutoDetailing` and
`SeedAutoDetailing.php` are the real, supported auto-detailing vertical (this platform's other product
line, not a leftover); `->placeholder('Marszałkowska')` on address fields is ordinary input-placeholder
UX (never submitted, never stored, gone the moment a user types); `EmailTemplateResource.php`'s
`'service_name' => 'Detailing Premium'` is admin-only template-preview sample data (`'ul. Przykładowa
123'` — "Przykładowa" literally means "example" — right next to it makes the intent explicit), never
sent to a real customer. None of these needed a change.

**Re-run after fixing `booking/create.blade.php:102` and `ios/hero-banner.blade.php`, as requested,
not skipped:** same `grep -rniI` across `resources/views/` for the same phrase list. Result: every
remaining "detailing" match is inside an explanatory comment (`booking-wizard/steps/service.blade.php`,
`maintenance-prelaunch.blade.php`, `ios/hero-banner.blade.php`, `cms/partials/sidebar.blade.php` — all
this document's own prior edits describing what was removed and why). Zero matches for any of the other
tracked phrases (`Mobilne Myjnie`, `Tapicerki`, `Marszałkowska`, `contact@example.com`,
`+48123456789`, `terenie klienta`, `wnętrza auta`, `Parking naziemny`, `przyjeżdżamy do Ciebie`,
`60 sekund`, `Umów wizytę już dziś`, `kontakt@registro.local`, `prania tapicerki`). Clean.

## Data cleanup

`database/migrations/2026_08_13_130000_remove_foreign_default_logo_path.php` deletes any
`contact.logo_path` Setting row whose value is still exactly `['/images/logo.svg']` (the seeded
placeholder from `2025_12_06_142446_add_prelaunch_settings.php`) — does not touch a row with any
other value. **`down()` is irreversible by design** (`throw`s): the row it deletes points at
`public/images/logo.svg`, which this same branch permanently removes from the repo, so recreating
the row would restore a reference to a file that no longer exists, not the prior working state.
Uses `DB::table()`, not the `Setting` Eloquent model — `Setting` uses `BelongsToOrganization`, whose
global scope behaves differently depending on tenant context at migrate time (see `.claude/rules/models.md`
"Globalne wiersze... resolveActive()" for the 2026-08-08 incident this exact shape caused for
`email_templates`). Migration test: `tests/Feature/Database/RemoveForeignDefaultLogoPathMigrationTest.php`.

## Second bug found in the same pass: seeded copy describes a different business

`database/seeders/SettingSeeder.php` and `2025_12_06_142446_add_prelaunch_settings.php` seeded, as
GLOBAL (`organization_id IS NULL`) defaults used by **every** tenant without its own override:

- `contact.email` = `contact@example.com`, `contact.phone` = `+48123456789`,
  `contact.address_line` = `ul. Marszałkowska 1`, `contact.city` = `Warszawa`,
  `contact.postal_code` = `00-001` — a fabricated identity, not just an unbranded default.
- `contact.logo_alt` = `Registro - Mobilne Myjnie Parowe` — would have been rendered as visible text
  by the very fallback this branch introduces (`maintenance-prelaunch.blade.php`'s `<span>{{ $logoAlt }}</span>`),
  making it the same bug class as the logo asset, discovered while fixing the logo asset.
- `prelaunch.tagline` = "Registro polega na tym, że to my przyjeżdżamy do Ciebie, a nie Ty do Nas!"
  (mobile-service pitch: "we come to you"), `prelaunch.description_1`/`description_2` = mobile
  car-wash/detailing copy — this project sells **equipment rental only** (see root `CLAUDE.md`).
  False about every tenant's actual trade, not merely generic.
- `prelaunch.launch_date` = `2026-01-25` — a fixed date that is already in the past for any tenant
  relying on the default; same "silently wrong" shape, found in the same file during the same pass,
  not a copy/identity problem specifically.

**Decision per key** (neutral/empty/removed — no new marketing copy was written for equipment
rental, since that call belongs to product/marketing, not this branch):

| Key | Kept? | Reasoning |
|---|---|---|
| `prelaunch.page_title`, `.heading`, `.date_label`, `.contact_heading`, `.copyright_text`, `.html_lang` | Kept | Industry-agnostic boilerplate ("Coming soon", "Got questions?"); `page_title`/`copyright_text` naming "Registro" mirrors the same neutral-platform-name convention already used by the header/footer `config('app.name')` fallback |
| `contact.email/phone/address_line/city/postal_code`, `contact.logo_alt`, `prelaunch.tagline/description_1/description_2/launch_date` | Removed | Asserts a specific false identity or business, or (launch_date) a stale fact — see above |

`database/migrations/2026_08_13_140000_remove_foreign_default_contact_and_prelaunch_copy.php` cleans
the already-seeded rows for existing installs (exact-value match, same safety pattern as the logo
migration). `SettingSeeder.php` no longer seeds these keys for newly-provisioned tenants.
`errors/maintenance-prelaunch.blade.php`'s own hardcoded 3rd-tier fallbacks (below the DB and the
per-event Filament config) were fixed the same way — `tagline`/`description_1`/`description_2`/the
launch-date block/the contact block now render **nothing** when unset, instead of the same
mobile-detailing copy or a hardcoded stale date (`'10.01.2026'`) baked directly into the blade file.
This mirrors `errors/maintenance-deployment.blade.php`'s existing `!empty($contact['email'])` guard —
a correct pattern that already existed one file over.
Migration test: `tests/Feature/Database/RemoveForeignDefaultContactAndPrelaunchCopyMigrationTest.php`
(`down()` here IS meaningfully reversible — plain text, unlike the logo asset, nothing was deleted
out from under it).

## Third pass: exhaustive sweep of every remaining global settings row

The first pass fixed `contact.*`/`prelaunch.*` because those were the groups pointed at. That scoping
missed two things — a live key in a different group entirely, and two more groups nobody had checked.
**Every** `organization_id IS NULL` row was then read out of the dev DB and classified — not just the
groups someone flagged — specifically so a future reader doesn't have to guess whether their group was
covered. 69 rows going in; every one of the following was checked against `resources/views/` (grep,
not assumption) for whether anything actually reads it.

### The miss: `appearance.logo_alt` is the live key, not `contact.logo_alt`

`SettingsManager::logoAlt()` (`app/Support/Settings/SettingsManager.php:653`) reads
`appearance.logo_alt` — a completely different group from `contact.logo_alt`, which the first-pass
migration deleted. `appearance.logo_alt` was still "Registro - Mobilne Myjnie Parowe" in the dev DB,
rendered as `alt=` on every logo `<img>` (footer, Filament brand logo, `nav-logo.blade.php`) and as
**visible fallback text** wherever no logo image is configured at all. Confirmed no third `logo_alt`
key exists anywhere (`grep -rn logo_alt` across `app/`+`database/` — only `appearance.logo_alt`, read
by two Filament pages, `SystemSettings.php` and `DesignHub.php`, both correctly targeting the same
key). Removed by `2026_08_13_150000_...` below; `SettingSeeder.php` already didn't seed it going
forward (fixed in the prior pass without a matching DB migration — that gap is exactly what got missed).

**Second layer to the same miss: it existed as two rows, not one.** Global default
(`organization_id IS NULL`) AND a tenant-scoped override on `grent` — the one real tenant in dev —
carrying the identical foreign text. `SettingsManager::logoAlt()` reads whichever row applies to the
current tenant, so the tenant-scoped copy was exactly as customer-facing as the global one, arguably
more so (it survives even after the global default is eventually configured correctly). This is why
**none of the three migrations in this document filter by `organization_id`** — every one of them
matches on `(group, key, exact value)` alone, across every organization, and only the exact-value
guard is what makes that safe: a tenant who typed something real and different, global or
tenant-scoped, is never touched. Verified directly against the dev DB (a full string sweep for every
placeholder phrase across the whole `settings` table, any group/key/org, returns zero matches after
all three migrations) and pinned per-migration with an explicit tenant-scoped test case for every
removed key (`test_up_deletes_a_tenant_scoped_row_with_the_exact_placeholder_value_too` in each of the
three migration test files).

**Found the same asymmetry once more, in `down()` this time.** `2026_08_13_150000`'s `up()` trims
`marketing.important_info_points` org-agnostically (any row whose value matches the 3-item BEFORE
array, any `organization_id`), but its first-written `down()` only restored the global row
(`whereNull('organization_id')`) — a tenant-scoped row `up()` had trimmed would stay trimmed forever
after a rollback. Not hypothetical for the same reason `logo_alt` wasn't: a tenant holding its own copy
of a seeded default already happened once in this exact dev DB, for a different key. Fixed to check
each row's *current* value against `IMPORTANT_INFO_POINTS_AFTER` before restoring it, mirroring `up()`'s
guard exactly — proven red (a tenant-scoped row inserted, trimmed by `up()`, still trimmed after
`down()`) then green.

**That fix generalizes only to UPDATE-based rows, not DELETE-based ones — and `2026_08_13_140000` plus
the rest of `2026_08_13_150000` are entirely DELETE-based.** Asked to make those two migrations'
`down()` mirror `up()` the same way. They can't be, and this is a real structural limit, not
unwillingness to fix it: `up()` DELETEs a matching row outright, any `organization_id`. `Setting` has
neither `SoftDeletes` nor an audit trail (checked — only `BelongsToOrganization`, no
`spatie/laravel-activitylog` or equivalent anywhere in this project). Once a tenant-scoped row is
deleted, nothing in the database records that it existed or which `organization_id` it had — `down()`
has no way to know what to recreate. This is exactly why the `important_info_points` fix above WAS
achievable: that row is UPDATEd, never deleted, so it (and its `organization_id`) survives `up()` and
can be found again later by its current value. A DELETE destroys the very information an UPDATE-style
restore depends on.

Both migrations' `down()` now say this explicitly in their own docblocks rather than the previous,
overstated "meaningful, honest reversal" framing, which was true only for the global default.
`down()` still restores the global row (useful, and the common case), but a tenant-scoped copy that
`up()` deleted is gone for good after a rollback too. Pinned as a characterization test, not a
red-then-green fix — there is no green to reach for the impossible case:
`test_down_cannot_restore_a_tenant_scoped_row_that_up_deleted` (140000) and
`test_down_cannot_restore_a_tenant_scoped_removed_row_that_up_deleted` (150000), both asserting the
tenant-scoped row stays gone after `up()` then `down()`.

### `marketing.*` — two different severities in one group

Nine of eleven keys (`hero_title`, `hero_subtitle`, `services_heading`, `services_subheading`,
`features_heading`, `features_subheading`, `features`, `cta_heading`, `cta_subheading`) are confirmed
**dead** — grep of `resources/views/` finds no reader at all. The homepage is CMS-driven (see
`.claude/rules/blade-components.md`), so this whole sub-shape predates that migration and is vestigial.
Lower severity: an admin opening the Marketing tab in Settings finds a form pre-filled with car-wash
copy ("Profesjonalne Pranie Tapicerki Samochodowej"), but no customer ever sees it. Still a real
defect, just a different one — removed from seeding and from the existing dev rows, but the dead
Filament fields/tab themselves were **not** removed (a bigger UI-scope decision than cleaning up
seeded data, left to the owner).

**Correction: `important_info_heading`/`important_info_points` are NOT live either.** An earlier pass
through this document claimed `resources/views/booking/create.blade.php:515,517` renders them, and
concluded these two of eleven `marketing.*` keys were a real live render — that claim was wrong, and
code review caught it. `resources/views/booking/create.blade.php` is dead code: `BookingController::create()`
(the only route that names it, `booking.create`) only writes two session keys and
`return redirect()->route('booking.step', 1)` — it never calls `view(...)`, and nothing else does
either (a deprecation comment in `tests/Feature/ProfileSynchronizationTest.php:178` confirms it was
retired in v0.7.0). So all eleven `marketing.*` keys are dead, exactly as this document originally
said before that earlier, wrong correction — the whole group belongs in the "confirmed dead" paragraph
above, not split out.

The migration still trims `important_info_points` rather than deleting it, and that stays the right
call independent of live/dead status: two of the three seeded points were generic booking-policy
statements ("Rezerwacja wymaga wpłaty zaliczki" / "Możliwość anulacji do 24h przed wizytą") worth
keeping regardless of whether anything currently renders them; the third ("Usługi realizowane na
terenie klienta") was the same false mobile-service-model claim as the removed prelaunch tagline and
is dropped. No new copy was written — an existing false item was removed from an existing list, same
principle as everywhere else in this document, just without the "live render" justification this
document previously and incorrectly gave it.

### `booking_wizard.*` — live render paths, unreachable by today's only tenant type

`before_visit_items` (car-prep checklist) and `service_location_types` (parking-type picker) are both
rendered by real Blade files —
`resources/views/booking-wizard/confirmation.blade.php` and
`resources/views/booking-wizard/steps/vehicle-location.blade.php` respectively — driving the
appointment ("time_slot") booking wizard. No tenant reaches this today (equipment rental, the only
live tenant type, uses Cart/Checkout, not this wizard), but it is not dead code, unlike most of
`marketing.*` — any tenant that ever enables time_slot bookings on defaults would see it. Both removed
from seeding and from existing dev rows.

`vehicle-location.blade.php`'s `service_location_types` section already correctly guarded on
`@if(count($serviceLocationTypes ?? []) > 0)` — removing the seeded row was sufficient there.
`confirmation.blade.php`'s checklist did **not**: it had its own hardcoded 3rd-tier
`$defaultBeforeVisitItems` array carrying the identical car-wash text, independent of the Setting row
— the same shape of bug as `maintenance-prelaunch.blade.php`'s hardcoded fallbacks in the first pass,
found a second time in a different file. Fixed the same way: wrapped the whole checklist block in
`@if(!empty($beforeVisitItems))`, no hardcoded fallback content at all.

### Considered and kept

| Key(s) | Why kept |
|---|---|
| `map.default_latitude/longitude/zoom` (Warsaw coordinates) | Its only consumer is the same dead `booking/create.blade.php` as `marketing.important_info_points` above — not currently rendered anywhere, so belongs with the rest of "confirmed dead". Kept anyway, on different grounds: geographic coordinates for an initial map-center point are not a text identity claim the way "Profesjonalne Pranie Tapicerki Samochodowej" is — even if this key becomes live again, Warsaw as a default map center asserts nothing false about a tenant's trade or address. Different bug class from everything else in this document, independent of whether the render path is live. |
| `sms.alert_email` = `admin@example.com` | Standard Laravel env-driven config pattern (`config('services.sms.alert_email')`, overridable via `SMS_ALERT_EMAIL`) — an internal ops-alert recipient, never customer-facing, never rendered in any Blade file. Not the same bug class as a customer-facing placeholder. |
| `general.app_name`, `email.from_name`, `sms.sender_name` = `"Registro"` | Mirrors the same neutral-platform-name convention already established throughout this document (`config('app.name')` fallback). |
| `email.from_address` = `noreply@registro.local`, `email.smtp_host` = `smtp.gmail.com` | Real platform-level SMTP relay configuration (Registro's own Gmail relay, per prior deployment notes), not a placeholder. |
| `booking.*`, `auth.*`, `design.*`, `map.country_code/map_id/debug_panel_enabled`, `appearance.header_logo/footer_logo` | Technical configuration, no text identity/business claim. |

### Migration and coverage

`database/migrations/2026_08_13_150000_remove_foreign_default_appearance_marketing_and_wizard_copy.php`
— same `DB::table()` pattern, same exact-value-match safety, reversible `down()` (plain text/arrays
throughout, nothing deleted out from under any of it). Test:
`tests/Feature/Database/RemoveForeignDefaultAppearanceMarketingAndWizardCopyMigrationTest.php` (31
cases: every removed key individually for both a global row and a tenant-scoped row, the
`important_info_points` trim as an UPDATE not a DELETE for both scopes, a tenant-customized-value
survives for both the DELETE and UPDATE cases, and full `down()` restoration). The other two migration
test files gained the same tenant-scoped-row cases for every key they touch.
`tests/Unit/Support/Settings/SettingsManagerLogoTest.php` gained a `logoAlt()` contract test.
`tests/Feature/BookingWizardChecklistNoForeignCopyTest.php` (new) pins the
`confirmation.blade.php` fix — proven red (asserting against the pre-fix hardcoded checklist) then
green, same as every other fix in this document.

### Related, out-of-scope finding: two settings stores that do not agree, and why the ordering here matters

**Fixed 2026-08-14 on `feature/settings-store-disconnect`** — see the correction note at the end of
this section. The rest of this section is kept as originally written (the finding, and why fixing
the placeholder-`contact.*` removal first, in this branch, mattered) because that reasoning is still
the reason the fix below was safe to make afterward.

There are **two separate places** "settings" can live, and code in this area reads the wrong one:

- **`settings` table** — what `SettingsManager::set()`/`get()`/`getForOrganization()` read and write
  (tenant row first, falling back to the global `organization_id IS NULL` row). This is where
  `SystemSettings.php`'s Contact tab actually saves `contact.address_line`/`contact.email`/`contact.phone`,
  and where the fabricated placeholders this doc is about used to live.
- **`organizations.settings` JSON column** — a completely different store, holding only `modules` and
  `features`. Written **solely** by `SeedOrganizationDefaults::seedIndustryFeatures()`. Nothing, ever,
  writes a `contact` key into it.

`app/Services/Order/OrderProtocolPdfService::pickupDetails()` and
`app/Notifications/OrderPaidNotification::buildRentalVariables()` both read pickup address/phone from
the **JSON column** — deliberately, per that method's own comment ("queue-safe, no SettingsManager") —
not from the `settings` table the admin form actually writes to. Verified against the dev DB: every
organization's `settings` JSON column contains only `modules`/`features`/`location` keys, `contact` is
NULL for all of them, including `grent` (13 services, 10 real orders).

**Two live, customer-facing consequences today, both unrelated to this branch's own fix:**

1. The paid-order confirmation email has never told a customer where to collect their equipment —
   `pickup_address`/`pickup_phone` resolve to empty strings in every order-paid email ever sent.
2. The handover/return protocols (PR #178) render an **empty landlord block** for every tenant — not
   because of a schema limitation, but because they read a store nothing populates.

**Why removing the placeholder `contact.*` values in this branch, before that store disconnect gets
fixed, is deliberate and matters:** if someone repairs `OrderProtocolPdfService`/`OrderPaidNotification`
to read the `settings` table (the store that actually has the data) while the fabricated placeholders
from `SettingSeeder.php` were still sitting in it, a **signed handover protocol would print
"ul. Marszałkowska 1" and `contact@example.com` as the renting company's legal address** — a much worse
outcome than an empty field. Removing the placeholders first (this branch) makes that impossible;
fixing the store disconnect afterward, on a `settings` table that only ever holds real tenant-entered
data or nothing, is safe by construction.

**Not fixed here — this is a behavior change to notifications and to a legal document that just
shipped, and needs its own review.** Flagged to the branch owner as a separate decision to make.

**Correction (2026-08-14, `feature/settings-store-disconnect`):** fixed. Both call sites now read
via `SettingsManager::getForOrganization('contact.*', $order->organization, $default)` — the
tenant row, falling back to the global `organization_id IS NULL` row, i.e. exactly the store
`SystemSettings`' Contact tab writes. `getForOrganization()` takes the organization explicitly
rather than resolving `TenantFeature::currentTenant()`, so it stays queue-safe (`OrderPaidNotification`
is `ShouldQueue`) without needing the JSON-column shortcut. `OrderProtocolPdfService` gained a
constructor-injected `SettingsManager` for this (previously stateless). Cache correctness verified:
`SettingsManager::set()`'s invalidation key is built from `TenantFeature::currentTenant()`'s id,
which is the same id `getForOrganization()` uses for its cache key when called with that tenant's
`Organization` — so a Contact-tab save in the admin panel (where `currentTenant()` resolves to the
tenant being edited) invalidates exactly the key a later queue-worker read for that tenant's order
will recompute. Regression coverage:
`tests/Feature/Notifications/OrderPaidNotificationPickupAddressTest.php` (new) and
`tests/Unit/Services/OrderProtocolPdfServiceTest.php`'s `pickupDetails()`/landlord-block tests
(extended) — both write settings through `SettingsManager::set()` with no ambient tenant at read
time, to prove the queue-worker path specifically, not just the happy path. Independently
reproduced by generating a real dompdf PDF and reading it back with `pdftotext` on the host — the
landlord block renders correctly. Full narrative: `order-notifications.md`, `order-protocols.md` §5.

**Second correction, same day, from code review of the first correction:** the cache-correctness
claim above ("invalidates exactly the key a later queue-worker read... will recompute") only
covers a tenant that has its OWN `settings` row. It does not cover **inheritance**. A tenant with
no row of its own reads (and, pre-fix, cached) the global fallback UNDER THE TENANT'S OWN cache
key. `SettingsManager::set()`'s invalidation only ever clears a tenant-scoped key; `setGlobal()`'s
invalidation only ever clears the global key. Neither one ever cleared the tenant-scoped cache
entry holding the inherited value — so an operator correcting the address at the platform-global
level, then generating a handover protocol for a tenant that inherits it, got the stale address
until the 3600s TTL expired on its own. Pre-existing in `SettingsManager` since before this branch,
not introduced by it — but this branch is what puts that path on the route for a signed document,
so it was fixed here rather than deferred. Fix: `getForOrganization()` no longer caches an
inherited global value under the tenant key at all — only "this tenant has no row of its own"
(`SettingsManager::TENANT_ROW_MISS` sentinel) is cached there; the actual inherited read delegates
to `getGlobal()`, reusing the cache key `setGlobal()` already invalidates correctly. Proven
red-then-green: `tests/Unit/Support/Settings/SettingsManagerGlobalInvalidationTest.php`.

**Third correction, same review round — a third call site, a live regression the fix itself
introduced, and a weak test:**

1. **A third call site.** `resources/views/orders/show.blade.php` (customer's own order page) read
   `$order->organization?->settings` directly in a `@php` block, computing `$hasPickupInfo` from
   it — the identical JSON-column bug, missed by the original sweep because that sweep grepped
   `app/` only, never `resources/views/`. `$hasPickupInfo` was therefore always `false`: the
   "Miejsce odbioru sprzętu" section has never rendered on that page for any tenant. Fixed by
   moving the extraction into `OrderController::show()`'s new `pickupDetails()` (same convention
   as the other two call sites), passed to the view as `$pickup`. Re-swept `resources/views/`,
   `resources/js/`, `database/`, `routes/` in addition to `app/` this time — all clean, only
   legitimate `modules`/`features`/`location` readers and this document's own explanatory comments
   remain. Proven red-then-green:
   `tests/Feature/Orders/OrderShowPickupLocationTest.php`.
2. **A live regression the first fix itself activated.** `EmailTemplateSeeder`'s `order-paid`
   HTML body concatenated `{{pickup_address}}{{pickup_phone}}` with no separator — dormant only
   because both variables were always empty before the fix above made them real. Once populated,
   this rendered `…00-100 Warszawa+48123123123` glued together in every HTML confirmation email —
   worse than the pre-fix empty state, not merely cosmetic, so it needed fixing in this same
   change rather than being deferred like the (separate, still-open) unconditional-label rough
   edge. Fixed in `EmailTemplateSeeder.php` (adds `<br>`) plus
   `database/migrations/2026_08_14_100000_fix_order_paid_pickup_html_separator.php` for
   already-provisioned tenants (`order-paid` is seeded ONLY by `EmailTemplateSeeder`, at
   first-tenant provisioning — no migration seeds it at all, a pre-existing gap
   `OrderHandoverReturnEmailTemplateMigrationTest`'s docblock already flagged for six template
   keys including this one). Exact-value `WHERE html_body = <old>` match, same safety convention
   as `2026_08_12_120000_seed_order_handover_return_email_templates.php`: a tenant's own
   customisation, or an operator's unrelated hand-edit of the global row, is never touched.
   Full narrative and the unconditional-label decision: `order-notifications.md`.
3. **A test that would have passed against the glued output.**
   `OrderPaidNotificationPickupAddressTest`'s first test asserted the address and the phone as
   independent `assertStringContainsString()` calls — `"…Warszawa+48123123123"` satisfies both
   independently, so the test pinned wiring, not the actual rendered adjacency. Rewritten to
   assert the exact junction fragment (`'ul. Testowa 5, 00-100 Warszawa<br>+48123123123'`) plus an
   explicit `assertStringNotContainsString('Warszawa+48123123123', ...)`. Confirmed this version
   actually fails against the pre-fix (glued) template before re-confirming green.

**Also from this review round, unrelated to the three above:** `SettingsManager::set()` gained a
docblock warning that it targets the GLOBAL row whenever no tenant is resolved (e.g. run from
`tinker`/a console command with no ambient tenant) — `setGlobal()` already warned about the
opposite-direction footgun (LC-9, `models.md`), `set()` did not. Not reachable through any
Filament-panel path this codebase uses; documentation only, no behavior change.

### Root-cause follow-up: three copies of the same lookup, one of them wrong twice

Every fix above (OrderPaidNotification, OrderProtocolPdfService, then the third call site in
OrderController) independently re-derived the same five-key `contact.*` lookup, and each
carried a docblock saying "read via `getForOrganization()`, NOT `organization->settings`". That
convention is exactly the one that produced the original bug — a docblock the next caller has to
read and trust, on each of three near-identical copies, is not a structural guarantee. Raised in
review: would a single accessor make the wrong-store mistake impossible for a fourth caller,
rather than merely documented against?

Added `SettingsManager::contactDetailsFor(?Organization): array` — the ONE place that reads
`contact.address_line`/`postal_code`/`city`/`phone`/`email` via `getForOrganization()`, returning
all five as raw strings (`''` default, never `null`). All three call sites now call it instead of
spelling out the five `getForOrganization('contact.*', ...)` calls themselves:

- `OrderController::show()` passes the raw five-key array straight to the view (needs address
  line and postal+city on separate `<dt>/<dd>` lines — no combining).
- `OrderProtocolPdfService::pickupDetails()` and `OrderPaidNotification::buildRentalVariables()`
  each combine `address_line`+`postal_code`+`city` into one display string locally — DELIBERATELY
  not folded into `contactDetailsFor()` itself. That combining is a display-shape choice each
  caller owns, not a store decision; a method that also decides "and here's the one true way to
  join these fields as text" would just be a fourth per-caller convention wearing a shared name.
  `contactDetailsFor()`'s own docblock says so explicitly, so the temptation to add an
  "assembled" variant next time is answered before it's asked.

A new caller can now still get the DISPLAY SHAPE wrong (unlikely to matter much — it's just
string formatting) but cannot get the STORE wrong, because there is no longer a second `contact.*`
lookup to copy from that reads the wrong one. Coverage:
`tests/Unit/Support/Settings/SettingsManagerContactDetailsTest.php` (new) — including a
poisoned-JSON-column test that plants the WRONG-store shape data (`settings.contact.address_line`
on the `organizations` row) and asserts `contactDetailsFor()` never surfaces it.

## Fourth pass: hardcoded text with no settings row at all

Everything above this section is a Setting row problem. Not every instance of this bug is — the
following two were plain hardcoded strings in Blade files, so no amount of sweeping the `settings`
table would ever have found them. Found by code review, not by this document's own sweep methodology,
which only ever looked at data.

- `resources/views/components/cms/partials/sidebar.blade.php` — a CTA widget hardcoding "Umów wizytę
  już dziś! Profesjonalny detailing dla Twojego auta. Rezerwacja online w 60 sekund." Included
  unconditionally by `resources/views/components/cms/layouts/default.blade.php`, one of the layout
  options any tenant's Page/Post/Promotion can select (`PageLayout::DEFAULT` in
  `PageResource`/`PostResource`/`PromotionResource`) — the most customer-facing single instance of
  this bug found anywhere in this task, reachable on every CMS page/post using that layout, for every
  tenant. Removed entirely rather than reworded: a shared layout partial hardcoding a specific business
  pitch isn't fixed by writing a different specific pitch. If a CTA belongs in this partial, it needs
  to be content-driven (a CMS block, a per-tenant setting) — a feature decision for the owner, not this
  branch.
- `resources/views/booking-wizard/steps/service.blade.php` — step 1 of the live booking wizard
  (`booking.step`, distinct from the dead `booking.create` above) hardcoded "Wybierz usługę detailingu
  dla Twojego pojazdu" as the step subtitle. Removed; the heading ("Wybierz usługę") alone is
  industry-agnostic and needed no replacement.
- `resources/views/components/ios/footer.blade.php` — already known dead/unreferenced (see "Dead
  code" section above), but was left half-fixed in an earlier pass: the logo fallback was corrected,
  but a hardcoded "Profesjonalne usługi detailingowe dla Twojego auta" tagline and a fake phone/email
  (`+48123456789`, `kontakt@registro.local`) were still in the file. Finished: tagline removed (no
  replacement), phone/email switched to `SettingsManager::contactInformation()` with the same
  absent-means-hidden guard used everywhere else in this document.

## Regression coverage

- `tests/Unit/Support/Settings/SettingsManagerLogoTest.php` — pins the null-by-default contract at
  the `SettingsManager` level, plus a generic "never resolves to `public/images/*`" guard so a
  differently-named future foreign/placeholder asset would also fail the test.
- `tests/Feature/NoForeignBrandingRegressionTest.php` — renders a real tenant homepage with no logo
  configured and asserts the response never contains `images/logo` and does show the text fallback.
- `tests/Feature/Database/RemoveForeignDefaultLogoPathMigrationTest.php`,
  `tests/Feature/Database/RemoveForeignDefaultContactAndPrelaunchCopyMigrationTest.php`,
  `tests/Feature/Database/RemoveForeignDefaultAppearanceMarketingAndWizardCopyMigrationTest.php` —
  exercise all three migrations' `up()`/`down()` for real on SQLite (not `MigrationRollbackTest`'s
  static regex, which only checks `down()` has a non-empty body and proves nothing about behavior).
- `tests/Feature/BookingWizardChecklistNoForeignCopyTest.php` — renders the real appointment
  confirmation page with nothing configured, asserts the checklist section and its car-wash copy are
  both absent.
- `tests/Feature/NoHardcodedForeignCopyRegressionTest.php` — pins the two hardcoded-text findings from
  the "Fourth pass" section: renders a real CMS page on the `default` layout and a real booking wizard
  step 1, asserts the removed car-detailing strings are gone.
- `tests/Feature/NoForeignBrandingFinalSweepTest.php` — pins the `ios/hero-banner.blade.php` fix from
  the final sweep: renders the component directly via `Blade::render()`, asserts no hardcoded
  detailing defaults and that a caller-supplied title/subtitle still renders correctly.

All were verified red (asserting against the pre-fix fallback) then green (current code) before
being kept.

## If you add a new logo/brand-image call site

1. Read logo path via `SettingsManager::headerLogo()`/`footerLogo()` — never read
   `contact.logo_path`, `appearance.header_logo` etc. directly.
2. Branch on null. The `@else` MUST render text (tenant/app name), never another `asset('images/...')`
   call — that reintroduces this exact bug with a different filename.
3. Do not add a new bundled placeholder brand asset under `public/images/` "just in case" — there is
   no Registro logo asset by design (see `frontend-ui-architect` agent constraints:
   "Do not invent a Registro logo").

## If you add a new default setting a tenant could see unconfigured

The same rule applies to text, not just images: a seeded default is either genuinely
industry-agnostic ("Coming soon", "Got questions?") or it should be absent, with the caller
rendering nothing — never a specific fabricated identity (an email/phone/address that looks real)
and never copy describing a business this project doesn't sell (equipment rental only). "Neutral" is
not "invent different neutral copy" — if in doubt, seed nothing and let it stay blank.

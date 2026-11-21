---
name: service-form-stage0-safety-net
description: STAGE 0 characterization tests for the admin product form (ServiceResource) - where they live, the helper trait, and the non-obvious Filament/Livewire facts measured while writing them
metadata:
  type: project
---

`tests/Feature/Filament/ServiceFormEditCharacterizationTest.php`, `ServiceFormCreateCharacterizationTest.php` and the shared trait
`ServiceFormTestSupport.php` (not a `*Test.php`, so PHPUnit ignores it - same precedent as `CatalogTestHelpers`) pin what the
product form does BEFORE the tabs / per-tab-save refactor. Known defects are pinned in separate methods commented
`// KNOWN GAP (stage 1 will change this): ...`; a deliberate fix should flip exactly those tests.

**Why:** owner's hard requirement that the refactor loses nothing. **How to apply:** before stage 1+ changes, run both files; a red
test that is NOT a KNOWN GAP test is a real regression; a red KNOWN GAP test is the fix landing (update it deliberately).

Measured facts that were NOT what the architect's analysis assumed (all pinned as reality):
- A no-op save is NOT byte-identical for `content` (Builder blocks gain default keys `full_width:false`, `css_id:null`, ... on the FIRST form save)
  nor for `metadata` (keys other than `specs` are dropped). Tests do first-save/second-save: 2nd save is a true no-op.
- Blocks stored without an optional toggle/slider get `false`/`0`, not the block default (hero.overlay_opacity, cta_banner.background_orbs, custom_html.container_wrapper).
- `custom_html` is `visible()` only for super-admin, yet an admin's save keeps an already-stored custom_html block untouched.
- `FileUpload` hydration drops a path whose file is missing on the disk -> ANY save nulls `featured_image` (needs `Storage::fake('public')` + real files in fixtures).
- `fillForm([])` RESETS the create form (defaults lost); `fillForm([...])` fires `afterStateUpdated` (name -> slug) - same as `->set()`.
- Clearing `name` (null or '') throws TypeError (closure typed `string $state`). Duplicate name in a tenant / cleared `price` = unhandled QueryException (DB unique / NOT NULL, form has no rule).
- The stale form really does revert an inline stock edit (anchor 9 -> 5). Multi-location create leaves `quantity_total` NULL (not 0).
- `LocationObserver` refuses to deactivate a tenant's ONLY active location (exception) - to simulate "zero active locations" delete via `DB::table('locations')`.
- Only the FIRST relation-manager tab is rendered in a Livewire test (`assertSeeLivewire` works for LocationStocks, not Units) - assert Units via tab label text.
- JSON key order: MySQL reorders -> compare through `ksortDeep()` (trait), never `assertSame` on a raw metadata/content array.

Added in the review round (same day):
- Completeness guards are DERIVED: `formFieldInventory()` (trait) reads `getSchema('form')->getFlatFields(withHidden: true)` (layout-neutral, drop keys containing a repeater-row uuid) and `isDehydrated()`; `unboundColumns()` = `Schema::getColumnListing` minus form names; `fillUnboundColumns()` writes non-default values so a save that touches `description`/`booking_count_*`/... turns e1/e2 red. Hand lists remain only as E7 classification.
- A KNOWN GAP is asserted in exactly ONE test (c1/c3 exclude quantity_total, duration_minutes, metadata; their gaps have own tests).
- `assertBlocksKeepTheirData` is strict + `assertArrayHasKey` first; a loose `assertEquals($v, $after[$k] ?? null)` cannot see a dropped falsy key (mutation: background_orbs not dehydrated -> only the strict one fails).
- Surprises: image block with a missing file FAILS VALIDATION (whole page unsaveable) while a gallery silently drops the missing item; a stored `hero_overlay_opacity` outside the 11 options or an icon outside the list makes the record unsaveable (`in` rule); unbound keys inside a block and unregistered block types are carried through verbatim; setting a new upload on a block FileUpload that already has a path keeps the OLD path (new file orphaned) - a first upload works; `callAction(TestAction::make('loadSpecTemplate')->schemaComponent('specyfikacja-techniczna::data::section'))` (no `form.` prefix, Filament adds it); `Section` keys are slugified headings + `::data::section`.
- Sed on this box: backslashes get halved by the tool layer; for namespace-bearing mutations use `perl -0pi -e` with `\x5c`.

Runtime: Edit file ~170 s on SQLite (116 tests; scalar-field provider is the bulk), Create ~30 s.

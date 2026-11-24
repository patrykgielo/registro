---
name: mysql-json-key-order-pr316
description: PR #316 first CI run on MySQL 8.0 - 7 tests failed only on JSON key order/format; fix = Tests\TestCase::assertSameJson/ksortDeep; plus the measured production consequence (dirty-on-reassign) and why Filament save paths are NOT affected
metadata:
  type: project
---

First real `test.yml` run (MySQL 8.0, `--parallel`) on PR #316: 7 failures, all green on SQLite.
Cause (confirmed on a throwaway `mysql:8.0.46`): MySQL's native JSON type re-orders object keys
(shorter key first, then bytewise: `label,value,unit` -> `unit,label,value`; `type,data` -> `data,type`;
`label,hours` -> `hours,label`) and prints `: ` / `, ` with a space (`{"0": "a", "2": "b"}`, `["a", "b"]`).
SQLite returns the text exactly as written. Not a bug in the code under test.

**Fix:** `Tests\TestCase::ksortDeep()` (moved from `ServiceFormTestSupport`) + `assertSameJson()`:
sort keys of associative arrays only, lists keep order, then strict `assertSame`. NEVER `assertEquals`
here - it makes `null == ''` and `800 == '800'`, and `NormalizesSpecsShape` documents that `unit: null`
vs `''` matters. Raw JSON strings: decode first (assoc mode for "dict with a gap", object mode
`json_decode($raw)` to tell `[...]` from `{"0":..}`, since assoc mode decodes both to the same array).
A hidden second failure was masked behind the first in the same test (`ServiceFormTileHighlightsTest`
`'["a","b"]'` raw compare) - after fixing a MySQL-only assertion, re-run the WHOLE test method on MySQL,
the first failing assert hides the rest. `CatalogSpecHighlightTest:113-114` / `TileHighlights:139-140`
`assertStringNotContainsString('"highlight":false', raw)` are vacuous on MySQL (space after `:`) - left
alone, the preceding `assertArrayNotHasKey` is the real check.

**Production consequence (measured, mysql 8.0.46 vs sqlite, throwaway container):**
`HasAttributes::originalIsEquivalent()` compares `array`-cast attributes by decoding both sides and `===`
(order-sensitive). So on MySQL, *assigning* an array with the same content but a different key order
marks it dirty:
- `Service::metadata = [specs=>[[label,value,unit]]]; save()` -> MySQL 1x `UPDATE services SET metadata, updated_at`
  (updated_at bumped, no audit - Service has no `Auditable`); SQLite 0 UPDATEs.
- `Location::opening_hours = [[label,hours]]` same content -> MySQL `isDirty=true`, 1 phantom `audit_logs`
  row (`updated`, old_values opening_hours vs new_values opening_hours, identical content); SQLite 0.
- Reading `$m->x` and assigning it back (`$m->x = $m->x`) is NOT dirty on either engine.
**But no current save path does this:** Filament `EditService` / `EditLocation` no-op save = 0 `metadata`/
`opening_hours` UPDATEs and 0 audit rows on BOTH engines (the Repeater dehydrates the loaded state in
place, so the row key order read from MySQL is written back unchanged; `array_replace`/`array_merge`
in `EditService::mutateFormDataBeforeSave` / `CartService` keep stored key order). Only latent: new code
that REBUILDS a row array in a different key order and assigns it (importer, API, console) on an
existing Auditable/updated_at-sensitive model. Side effects if it happened: phantom audit row,
`updated_at` bump -> sitemap `<lastmod>` and `RevertCatalogImport` "edited after import" guard.

Repro technique used: throwaway network + `mysql:8.0`, app image with `--network`, MySQL env as in
`test.yml`; first run on a fresh MySQL spends ~145 s in `migrate:fresh` (looks like a hang); run only
`--filter` method names, never a whole Filament class sequentially on MySQL (minutes).

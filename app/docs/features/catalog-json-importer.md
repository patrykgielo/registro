# Catalog JSON importer (`catalog:import`)

Imports a rental-equipment catalogue (products = `Service` with `service_type=item_rental`, their
`RentalCategory`, stock and images) into ONE tenant from a JSON file, and can undo exactly one
import. Built for onboarding a client whose catalogue already exists as data.

| Command | Purpose |
|---|---|
| `catalog:import {organization} {file}` | validate the WHOLE file, then write everything or nothing |
| `catalog:import-revert {batch}` | undo one import batch, scoped by its ledger |
| `catalog:import-status [organization] [--batch=]` | list batches / one batch's ledger counts (read-only) |
| `catalog:import-unlock {organization}` | force-release a stuck `catalog-import:{org}` lock (killed process) |

`{organization}`: `id:123`, `slug:abc`, or a bare value. A bare number that is **both** the id of one
organization and the slug of a *different* one is an error naming both prefixed forms (silently
picking one would import into the wrong tenant). The resolved target — `Organizacja docelowa: <name>
(id N, slug S)` — is printed **before** any validation or fetching. The same resolver serves
`catalog:import-status` and `catalog:import-unlock`. Code: `app/Actions/Catalog/*`, `app/Support/Catalog/*`,
`app/Console/Commands/Catalog*.php`, ledger models `CatalogImportBatch` / `CatalogImportItem`,
migration `2026_11_07_100000_create_catalog_import_tables.php`, config `config/catalog_import.php`.

## Import flow (nothing is written before step 6)

1. **File**: a plain filesystem path (any `scheme://` — `phar://`, `php://`, `http://`… — is refused; it
   must resolve with `realpath()` to a readable regular file), ≤ 5 MB, ≤ 500 products,
   `schema_version: 1`, no unknown root keys.
2. **Organization**: lifecycle active, rentals supported (`supportsRentals()` + module `rentals`), at
   least one ACTIVE location (without one the products would not be bookable).
3. **Products** (`CatalogProductValidator`): the Filament forms' rules (table below), the database
   constraints the forms leave to the DB (name/slug unique per organization, unit identifier unique
   per organization) and some stricter rules of its own — see "Parity with the Filament form".
   Strings are trimmed and empty strings become null first (what the panel's TrimStrings +
   ConvertEmptyStringsToNull do). Unknown keys are errors (typo guard).
4. **Duplicates**: `external_id` already in a non-reverted batch of this organization → error, or
   skipped with `--skip-existing`. A name/slug collision with a product that is NOT in the ledger is
   always a hard error (never overwritten, never merged).
5. **Images**: every image is fetched (`SafeImageFetcher`) or read (`LocalImageLoader`), sniffed
   (`ImageInspector`) into a scratch dir. `--images=required` (default): one bad image fails the
   import; `optional`: warning, product imported without it; `skip`: images ignored.
6. **One `DB::transaction`**: categories, services, stock/units, files, ledger. A *catchable* failure
   rolls the rows back and deletes the files already stored — **unless a fresh read shows the batch
   row exists** (the exception came from/after the commit): then the files are KEPT, the error is
   logged and the operator is told to check `catalog:import-status` — never "nothing was saved".
   Afterwards the `sitemap:{org}` cache key is forgotten; if that fails it is a warning (the import is
   committed).

   **Known limit:** a SIGKILL, OOM-kill or PHP fatal during step 6 cannot run any cleanup. Image files
   already written to `storage/app/public/services/{featured,images}/` then have **no ledger record**.
   Hint: list files there newer than the failed run (`find … -newer <marker> -type f`) and delete the
   ones no `services.featured_image` / `services.content` row references.

`--dry-run` runs steps 1-5. It is **not** side-effect free: it takes the per-organization lock, creates
(and removes) a scratch directory under `storage/app/tmp/catalog-import/`, and makes the network calls
of step 5 — unless `--no-image-check` or `--images=skip` is given. It writes no rows and stores no
files. Errors are printed as a table `#index | external_id | field | message`; everything
JSON-derived in it (field names are attacker-chosen keys, external ids, paths) has C0/C1 control
characters stripped and formatter tags escaped before it reaches the terminal.

**The lock is held for the whole run.** The run holds `Cache::lock('catalog-import:{id}')` and sets the
tenant request attribute for its duration (restored afterwards): in console `BelongsToOrganization`
does not know the tenant, so without it `Auditable` would write `audit_logs.organization_id = NULL`.
"Whole run" is guaranteed twice: (a) the TTL is derived — `catalog_import.image_phase_max_seconds`
(default 900) + 3600 s for validation, the write transaction and a margin; (b) the lock is refreshed
(`Lock::refresh()`, implemented by every cache store this app can use: array, file, database, redis,
memcached) before each distinct image request and right before the write phase. A lock found lost
(expired or force-released) aborts the run before anything is written — and so does **any store error**
during the refresh (a DB error inside `DatabaseLock::refresh()` means "unknown", which is treated as
lost); only the base class's "this driver does not support refreshing" is tolerated.

**This check is point-in-time.** Heartbeats run before each distinct image and once before the write;
the window between the last heartbeat and the commit (seconds) is not covered. Losing the lock inside
it needs a TTL expiry beyond budget + 1 h, or a manual `catalog:import-unlock` during a live run. Even
then two racing imports cannot duplicate products: the unique indexes on name/slug per organization stop
the second one. The revert protects itself independently with row locks (below).

At the start of a run, scratch directories untouched for longer than `max(6 h, 2 × lock TTL)` — i.e.
longer than a live run can take — are removed (leftovers of killed runs). Non-positive
`image_phase_max_seconds` / `image_phase_max_bytes` / `auto_increment_lock_wait_seconds` fall back to
the documented defaults (900 s / 1 GiB / 5 s) instead of being used. One in-flight request cannot
overshoot the budget either: its connect/total timeouts are clipped to the time left.

### Options

```
catalog:import {organization} {file}
  --dry-run            validate everything, write nothing
  --skip-existing      skip products whose external_id is already imported
  --images=required    required | optional | skip
  --image-delay=1000   min pause (ms) between requests to the SAME host
  --no-image-check     with --dry-run: do not download images
  --actor=             free text stored on the batch (e.g. operator e-mail)
```

`config/catalog_import.php`: `user_agent`, `image_delay_ms`, `max_retries`, `max_retry_wait_seconds`,
`image_phase_max_seconds` (900), `image_phase_max_bytes` (1 GiB), `auto_increment_lock_wait_seconds` (5).

## JSON schema (v1, `item_rental` only)

```json
{
  "schema_version": 1,
  "products": [
    {
      "external_id": "bosch-gbh-2-26",
      "name": "Młot udarowo-obrotowy Bosch GBH 2-26",
      "slug": "mlot-bosch-gbh-2-26",
      "brand": "Bosch",
      "category": { "name": "Elektronarzędzia", "slug": "elektronarzedzia", "icon": "wrench", "description": "…", "sort_order": 1 },
      "excerpt": "Lekki młot SDS-plus do betonu i muru.",
      "description": "Plain-text fallback description.",
      "body_html": "<p>Opis. <em>Zdjęcia: Jan Kowalski, CC BY-SA 4.0 (Wikimedia Commons)</em></p>",
      "icon": "wrench-screwdriver",
      "price_per_day": 120,
      "price_per_hour": 25,
      "price_per_week": 600,
      "price_per_day_long": 90,
      "price_threshold_days": 3,
      "deposit_amount": 500,
      "price_on_request": false,
      "is_active": true,
      "sort_order": 10,
      "published_at": "2026-11-01 08:00:00",
      "stock": { "quantity": 3 },
      "specs": [ { "label": "Moc", "value": 800, "unit": "W" }, { "label": "Waga", "value": 3.2, "unit": "kg" } ],
      "features": [ "Walizka w komplecie", "3 wiertła SDS" ],
      "seo": { "meta_title": "Wynajem młota Bosch GBH 2-26", "meta_description": "Wypożycz młot udarowy Bosch GBH 2-26." },
      "featured_image": {
        "url": "https://upload.wikimedia.org/wikipedia/commons/thumb/a/ab/Hammer_drill.jpg/1280px-Hammer_drill.jpg",
        "alt": "Młot Bosch GBH 2-26",
        "credit": "Jan Kowalski", "license": "CC BY-SA 4.0",
        "source_page": "https://commons.wikimedia.org/wiki/File:Hammer_drill.jpg"
      },
      "gallery": [
        { "path": "images/walizka.jpg", "alt": "Walizka", "caption": "Zestaw w walizce" }
      ]
    }
  ]
}
```

| Field | Required | Rule (source) |
|---|---|---|
| `external_id` | yes | string ≤ 100, unique in the file and among live batches — compared trimmed and case-insensitively ("ABC" = "abc"); see "Name / key folding" below |
| `name` | yes | ≤ 255 (`ServiceResource.php:105-113`); unique per organization, trim-/case-/diacritics-insensitive like `services_org_name_unique` (the form has NO rule for this — the panel would 500), also inside the file |
| `slug` | no (`Str::slug(name)`) | `^[a-z0-9]+(-[a-z0-9]+)*$` (anchored with `D`), ≤ 255, unique per organization (`:115-123`) |
| `brand` | no | ≤ 255 (`:314`) |
| `category` | yes | string (name) or `{name, slug?, icon?, description?, sort_order? (0..2147483647)}`. Matched by slug within the organization: existing → REUSED (never modified, never deleted by revert); otherwise created (`is_active=true`). `icon` must be a heroicon name |
| `excerpt` | no | ≤ 500 (`:132`) |
| `description` | no | plain text (fallback of `excerpt`) |
| `body_html` | no | purified with the **importer-only `catalog_import` profile** (`config/purifier.php`): only what the admin RichEditor toolbar produces — `p br b strong i em u a[href\|title] ul ol li h2 h3 blockquote`. No `img` (no tracking pixel), `div`, `span`, `style`, `h4-h6`. The product page still renders with `default`; a subset on write renders identically. ≤ 65535 BYTES measured on the purified output |
| `description`, `category.description`, `units[].notes` | no | ≤ 65535 BYTES (MySQL TEXT) — a validation error, not a "Data too long" after the images were fetched |
| `icon` | no | heroicon name; default null (rental cards fall back to `cube`) |
| `price_per_day` | yes | numeric, **≥ 0, ≤ 99999999.99, ≤ 2 decimals** (the one deliberate tightening: the form accepts negatives) |
| `price_per_hour`, `price_per_week`, `deposit_amount` | no | same price rule |
| `price_per_day_long` + `price_threshold_days` | both or neither | long rate ≤ `price_per_day`; threshold 1-365 (`:243-258`) |
| `price_on_request` | no | boolean; hides price and cart, shows the inquiry form |
| `is_active` / `sort_order` (0..2147483647) / `published_at` | no | defaults `true` / file index / now (the form defaults `published_at` to now; null would show "Wersja robocza" in the admin list) |
| `stock` XOR `units` | exactly one | one writer of stock per product |
| `stock.quantity` | | int ≥ 1; only for an organization with exactly ONE active location (the form's "Ilość w magazynie") |
| `stock.locations` | | `{"<location-slug>": int ≥ 0}`, active locations only, sum ≥ 1 |
| `units[]` | | ≤ 500 per product; `{identifier?, inventory_number?, status?, acquired_at?, notes?, location? (string or int slug)}`; `status` ∈ `available|maintenance|in_transit|retired`; `location` slug required unless exactly one active location; `identifier` unique per organization (trim-/case-insensitive, also inside the file). Quantity is then derived by `ServiceUnitObserver` (available units only) |
| `specs[]` | no | ≤ 30; `{label ≤100 (required), value ≤100 chars/number (required), unit ≤20 or null}` — stored as a LIST (`NormalizesSpecsShape`) |
| `features` | no | list, ≤ 4 items, each ≤ 100 |
| `seo.meta_title` / `seo.meta_description` | no | ≤ 60 / ≤ 160 |
| `featured_image`, `gallery[]` (list of objects, ≤ 10) | no | `{url \| path, alt?, caption?, credit?, license?, source_page?}` — exactly one of `url`/`path`; a non-object entry (`"abc"`, `null`, `5`) is a validation error |

Prices are **gross (brutto) PLN** — net is only displayed, computed from the tenant's
`general.vat_rate`. There are no VAT/currency fields. `deposit_amount` (kaucja) is not VAT-able.
`price` is set equal to `price_per_day` (seeder parity; the product page's JS and schema.org read
it). `area_served` is left null (the form's "Poznań" default is wrong for other tenants). Rating,
review and popularity counters are not importable.

### Name / key folding

Names, slugs, unit identifiers and `external_id`s are compared the way the `utf8mb4_unicode_ci` unique
indexes compare them: trimmed, case-insensitive and — for **Latin** text — accent-insensitive
("Zażółć" = "zazolc", "Młot 18V +" = "mlot 18v +"). Text that a transliterator cannot read losslessly
(emoji, CJK, other scripts) is compared as itself, only lower-cased: two different emoji-only or
CJK names are two different products, never false duplicates. One residual MySQL quirk the validator
cannot see: `utf8mb4_unicode_ci` (UCA 4.0) gives supplementary-plane characters such as emoji no weight,
so two names that differ *only* in emoji collide at the database level and surface as a rolled-back
phase-6 error (nothing is written).

### Parity with the Filament form

The importer validates with the form's rules where the form has them (`CatalogImportFormParityTest`
feeds each case to the real `CreateService` page and to the importer and requires the same verdict).
Where it is **deliberately stricter**:

* prices ≥ 0, ≤ 99999999.99, at most 2 decimals (the form accepts negatives and silently rounds);
* slugs must match `^[a-z0-9]+(-[a-z0-9]+)*$`; category slugs likewise;
* specs need both `label` and `value` (the form allows empty rows);
* unknown keys are errors; `gallery`/`features`/`specs` must be lists;
* upper bounds the form lacks: `sort_order` ≤ INT max, ≤ 500 units, ≤ 30 specs, TEXT columns ≤ 65535
  **bytes** (the form would hit "Data too long");
* name/identifier uniqueness is checked (the form leaves it to the DB constraint);
* `body_html` is purified with the narrower `catalog_import` profile.

Remaining parity: field maxima (name/brand/excerpt/meta/features/specs), the long-rate pair rules
(`requiredWith`, `lte:price_per_day`, threshold 1-365), `stock.quantity` min 1 and its
single-location condition, icon list. One known form bug is *not* mirrored: `BaseResource::
getHeroiconOptions()` mangles icon names containing "s-" (`arrows-pointing-in` → `arrowpointing-in`);
the importer accepts the real heroicon names (a test pins the exact difference).

## Stock — what makes a product bookable

Mirrors `CreateService::afterCreate()`: `SyncServiceLocationStock::forService()` materialises a
`service_location_stocks` row per active location, then the quantity is routed
(`RouteQuantityFieldToPrimaryLocationStock` for `stock.quantity`; direct rows + `recalculateQuantityTotal()`
for `stock.locations`; `ServiceUnit` rows + observer for `units`). Without an anchor row a location
reads as zero available.

## Images

* Storage: disk `public`, first image → `services/featured/<random40>.<ext>` (`services.featured_image`),
  the rest → `services/images/<random40>.<ext>` as Builder `image` blocks in `services.content`
  (the product page renders `image` blocks; it does NOT render the `gallery` block). Block data carries
  every key the Builder would write itself, so opening and saving the product in the panel is a
  byte-identical no-op.
* Bytes are stored **as-is** (no re-encode — Filament does not either, and GD here has no WebP).
  jpeg/png/webp by content sniffing; extension comes from the sniffed type; ≤ 6144 KB (the form's
  cap); 32 px ≤ each side ≤ 8000 px and ≤ 25 MP, read from the header — **the importer never decodes
  or re-encodes an image** (no server-side decode exists today; if one is ever added, those caps are
  what keeps GD inside `memory_limit`). `/thumb/…/1280px-…` Wikimedia URLs are ordinary paths and work.
* `credit` / `license` / `source_page` are persisted per stored path in `services.metadata.image_sources`
  and in the ledger (`catalog_import_items.snapshot` of the file row). They are not rendered anywhere —
  put a visible attribution line in `body_html`. **The admin form binds only `metadata.specs`, so saving
  the product in the panel drops `metadata.image_sources`; the ledger copy stays.**
* `path` images are resolved relative to the JSON file's directory, confined by `realpath()` (no `..`,
  no absolute path, no symlink escape), validated like a downloaded image. **Any file in that directory
  tree that is a valid jpeg/png/webp is readable by the JSON** — keep catalogue files in their own
  directory, not in a folder that also holds other images.
* The image phase has a **global budget** (below); each distinct URL / file is fetched once per run.

### SSRF policy (`SafeImageFetcher`, `PublicIpGuard`)

* http/https only; ports 80/443 only; no `user:pass@`.
* Every A/AAAA record of the host must be globally routable — one private/loopback/link-local/CGNAT/
  metadata/multicast/ULA/documentation address (or an IPv4-mapped/NAT64/6to4 form of one) rejects the host.
* The vetted IP is pinned into the request (`CURLOPT_RESOLVE`) → no DNS rebinding after the check.
* Redirects: followed by hand, max 3, **each hop re-checked**.
* Per request: 5 s connect / 20 s total timeouts; download aborted at the byte cap; the cap is also
  enforced on the body. **Globally** (`FetchBudget`): `image_phase_max_seconds` (default 900) of wall
  clock — retry waits, redirects and the per-host pause included — and `image_phase_max_bytes`
  (default 1 GiB, summed over each distinct image once). It is checked before every attempt and
  before every sleep, sleeps are truncated to what is left, and exceeding it is a validation error
  ("faza pobierania obrazów przerwana — budżet …"): nothing is written, scratch is removed.
* `decode_content=false` + `Accept-Encoding: identity`: the cap counts real bytes (no gzip bomb).
  `proxy=''`: an env `HTTP(S)_PROXY` would resolve the host itself and bypass the DNS pin.
  URLs containing `\`, whitespace or control characters are refused up front (parse_url and libcurl
  disagree on them). A test captures the final Guzzle options and fails if the pin disappears — it runs
  under `Http::fake()`, so it proves the option is *passed*, not that libcurl honours it; that was
  verified statically against Guzzle 7.15's `CurlFactory` (`curl` options are applied as-is, `proxy => ''`
  sets `CURLOPT_PROXY ''`).
* **Politeness** (Wikimedia answers 429 after two quick requests): descriptive User-Agent
  `RegistroCatalogImporter/1.0 (+APP_URL)` (`config/catalog_import.php`, env `CATALOG_IMPORT_USER_AGENT`,
  never an e-mail); sequential downloads with a minimum pause per host (`--image-delay`, default 1000 ms);
  429/503 retried max 3 times honouring `Retry-After` (seconds or HTTP-date, each wait capped at 60 s,
  exponential backoff 2/4/8 s without it); every other 4xx/5xx fails that image at once.

## Revert (`catalog:import-revert {batch}`)

**Hard guarantee: it deletes only the products created by that batch and the data that belongs to
them.** Scope = the ledger (`catalog_import_items`, `origin=created`) — never "all rows of the
organization", "newer than date", or "in category". (Contrast `onboarding:seed-vertical --force`, whose
`purgeExistingData()` deletes every service and category of the organization; only its safety mechanics
are reused here.)

Order: `analyse()` (read-only guard) → dry-run exit (needs no `--force`), or the gate: **without
`--force` a non-interactive run (`-n`, cron, pipe) is refused** ("Tryb nieinteraktywny: dodaj --force",
exit 1, nothing deleted); interactively the prompt `To NIEODWRACALNE. Cofnąć import?` appears unless
`--force` is given (same rule as `onboarding:seed-vertical`). The pre-purge `Log::warning` records
`interactive` and `force` → `Cache::lock("catalog-import:{org}")` (the SAME lock `catalog:import` takes; held for the
whole execution, fails fast with a clear message when an import or another revert of that organization
is running) → `DB::transaction` → after commit: files, sitemap cache, optional AUTO_INCREMENT.

Inside the transaction (MySQL REPEATABLE READ: a plain SELECT keeps answering from the first snapshot,
so a row someone committed a moment ago would be invisible — and `ON DELETE CASCADE` / `SET NULL` would
then destroy it):

1. the batch row is locked, then the batch's services are locked **first, `ORDER BY id`** (the
   codebase's lock order, rental-availability.md) — a child row (appointment, cart item, order item…)
   cannot be inserted meanwhile, its FK check needs an S lock on the parent;
2. the whole analysis is repeated with **locking reads** (`SELECT … FOR UPDATE`) — anything that appeared
   since the command's own analysis changes the plan: by default the revert is refused ("stan zmienił
   się od analizy"); with `--partial` a newly appeared dependent just blocks *that* product and the
   rest proceeds;
3. units, stock, staff assignments (`service_staff`, counted and shown in the plan and the result —
   product data, never a block), services are deleted with affected-row assertions (a mismatch rolls back);
4. candidate categories are locked, then a locking read checks whether any service still uses each one,
   only then is it deleted (`services.rental_category_id` is `ON DELETE SET NULL`).

SQLite compiles `FOR UPDATE` away, so the tests prove the refusal logic and the ordering of statements
only on MySQL (`CatalogImportAutoIncrementMysqlTest::test_revert_issues_its_locking_reads_in_the_documented_order`
checks the SQL and its order; replaying the actual race with a second connection is not covered).

### What the guard verifies (fatal = nothing is deleted, no flag overrides)

* every ledger row (service, unit, stock, created category) still belongs to the batch's
  `organization_id` AND has `created_at <= batch.imported_at` — a recycled id always has a LATER
  `created_at`, whereas name/slug equality would pass for "deleted and re-created with the same name";
  services must still be `item_rental`, units/stock must still point at their ledgered service;
* the batch is `completed` / `partially_reverted`.

### Hard dependents (refuse; **no flag overrides**)

`order_items`, `rentals`, `appointments` (and therefore extension requests) referencing a batch product.

### Soft blocks

A product renamed in the panel (name/slug differ from the ledger) is a soft block too
("nazwa/slug zmienione po imporcie"), not an identity failure.

| Condition | Overridden by |
|---|---|
| `cart_items` of a batch product | `--discard-cart-items` (deletes ONLY those cart items) |
| product `updated_at` > batch `imported_at`; units not in the ledger; non-zero stock rows not in the ledger; a ledgered stock row whose quantity/`updated_at` changed; an edited ledgered unit; `featured_image`/image blocks differ from the ledger | `--include-modified` (the foreign units/stock of those products go with them) |

A zero-quantity stock row not in the ledger is NOT a modification (`ServiceLocationStockObserver` adds
one per service whenever a location is created).

Default is **all-or-nothing**: one blocked product aborts the whole revert. `--partial` reverts the
unblocked products and leaves the rest (batch → `partially_reverted`).

### FK cascades reachable from the deleted tables

| Delete from | Cascades / effects |
|---|---|
| `services` | `service_units`, `service_location_stocks`, `service_staff`, `appointments` (→ `reminder_logs`): CASCADE, all keyed by `service_id`; `cart_items`, `order_items`, `rentals`: RESTRICT |
| `service_units` | `order_items.service_unit_id`: SET NULL |
| `rental_categories` | `services.rental_category_id`: **SET NULL** — hence a category is deleted only when NO service of any owner still points at it |

Units and stock are deleted explicitly (by `service_id` + `organization_id`) and their counts asserted
before the services go. No model events fire; `audit_logs` rows are left alone (they are the trail).
No soft deletes are involved.

### Categories and files

* A category is deleted only if THIS batch created it, it is unedited since import and nothing
  references it any more; a **reused** category is never deleted.
* Files: only recorded ledger paths matching `^services/(featured|images)/<40 alnum>.(jpg|png|webp)$` (anchored, `D`);
  skipped when the content hash differs from the ledger, or any `services` row outside the revert still
  references the file name (`featured_image`, `content`, `metadata`, `body`). Directories are never
  removed.

### After the commit: guarded steps and the retry path

Files, the sitemap forget, the AUTO_INCREMENT reset **and the lock release** run *after* the commit
(files are not transactional, `ALTER` is DDL). Each is guarded separately (as is the final log line): a
failure is logged (`Log::error`, with batch/org/step) and collected as a warning — **nothing in the
revert or the import throws once the DB work has committed**, so a committed run can never be
reported as failed. The command says the products ARE removed (committed), lists every failed step with
its exact retry command, and exits 0. It never uses the word "rollback" for these.

If the exception comes from the **commit itself** (connection lost mid-commit), the revert does not
guess: it asks the database with a fresh read whether the batch changed. Changed → "ZATWIERDZONE"
(products removed; run `--cleanup-only` for the housekeeping); unchanged → a genuine rollback;
the read itself fails → "stan nieznany — sprawdź catalog:import-status". The import does the same with
`batchState()`, and on a committed/unknown batch it KEEPS the stored files.

**Retry: `--cleanup-only`.** `catalog:import-revert N --cleanup-only` runs ONLY the idempotent steps on a
batch in `reverted` or `partially_reverted` status (refused on any other status): leftover files
(same pattern / sha256 / no-live-reference checks), the sitemap forget — always, even with nothing else
to do, because that is the retry of a failed sitemap step — and with `--reset-auto-increment` the
reset (same planner conditions). It never analyses and never deletes a row, so a recycled id cannot be
hit, and it needs no `--force`. It prints what it did ("usunięto plików N, mapa strony odświeżona") and
exits 1 if a step failed again. **Every printed retry hint uses it** (`… --cleanup-only`, plus
`--reset-auto-increment` for the AI step; a failed lock release points at `catalog:import-unlock`).
A plain re-run on a `reverted` batch routes to the same cleanup. Files that were kept or could not be
deleted stay discoverable (the ledger marks a file removed — `snapshot.file_removed_at` — only after it
is really gone).

A plain re-run on a `partially_reverted` batch is a normal revert of the products that are left: if
their blockers cleared, they are reverted; if all are still blocked it prints them and refuses
("pozostałe produkty zablokowane — nic z nich nie usunięto", exit 1) **without doing any implicit
cleanup** — one command does one thing — and, when leftover files exist, prints the `--cleanup-only`
hint.

### `--reset-auto-increment` (opt-in)


Per table of `services`, `rental_categories`, `service_units`, `service_location_stocks`, after commit,
`ALTER TABLE x AUTO_INCREMENT = <lowest id the batch created in x>` — only when ALL hold
(`AutoIncrementResetPlanner`):

1. driver is exactly `mysql` (never SQLite/MariaDB);
2. no row with `id >=` that lowest id remains in the table (ANY organization — a partial revert or a
   later foreign insert makes it skip);
3. no other still-live (non-reverted) ledger row of that entity type — of ANY batch — points at an id >= the lowest;
4. no `audit_logs` row has `auditable_type` = that model and `auditable_id >=` the lowest id. Units are
   created through Eloquent, so their `service_units` reset is typically skipped. **Stock rows are
   different:** `stock.quantity` rows come from `insertOrIgnore` and have no audit rows (so
   `service_location_stocks` can reset), while `stock.locations` rows are then *updated* through Eloquent
   and do have them. `services` and `rental_categories` are not `Auditable` at all.

MySQL itself clamps the value to `MAX(id)+1`, so a reset can never create a duplicate key — the rules
protect what remembers the old id outside the table. Each skip is reported with its reason. DDL
implicitly commits, which is why it runs after the transaction.

The ALTER is **bounded**: `ALTER TABLE … AUTO_INCREMENT` waits for the metadata lock of any open
transaction that touched the table — and every later query on that table, from every tenant, queues
behind the waiting ALTER. So before each ALTER `SET SESSION lock_wait_timeout =
catalog_import.auto_increment_lock_wait_seconds` (default 5) is issued and the previous value restored
in a `finally`. On error 1205 the table's decision is `reset=false`, reason "tabela zajęta — ponów:
catalog:import-revert N --cleanup-only --reset-auto-increment" — a refusal, not an exception (the retry path above).
After a successful ALTER the **real** counter is read from `information_schema` with
`information_schema_stats_expiry = 0` (MySQL 8 caches that value for a day) and reported; if MySQL
clamped it, the output says "przycięty przez MySQL … ustawił X zamiast Y" instead of claiming Y.

## Verified end-to-end (dev, 2026-11-07)

Tenant `firma` (one location), `database/data/catalogs/demo-sprzet-budowlany.json` — 50 products,
95 image references, 28 distinct Wikimedia URLs:

* First run failed on ONE image (HTTP 404, a hand-built Commons URL) → zero rows, zero files, no batch.
  Commons file URLs must come from the API (`prop=imageinfo`), the `a/ab/` hash path cannot be guessed.
* Import: 50 services, 9 categories, 50 stock rows (Σ 112 = Σ `quantity_total`), 95 files, ledger
  204 rows, 0 `audit_logs` with NULL organization; ~50 s with `--image-delay=1500`. Without per-URL
  memoization it would have been 95 requests against a host that answers 429 after ~2 quick ones.
* `catalog:import-revert 1 --reset-auto-increment`: 0 services/categories/stock/files left;
  AUTO_INCREMENT of `services`, `rental_categories`, `service_location_stocks` back to 1; re-import
  reused ids 1-50.
* Public `/wypozyczalnia`, category and product pages (prices, long rate, deposit, availability,
  specs, attribution) and the admin "Produkty" list render the imported data.

Dataset provenance (estimated prices, licensed illustrative photos): `database/data/catalogs/README.md`.

## Releasing a stuck lock

A killed process (SIGKILL, OOM) leaves `catalog-import:{org}` held until its TTL runs out
(`image_phase_max_seconds` + 1 h for imports, 1 h for reverts). Release it with

```
php artisan catalog:import-unlock id:123          # prompts; non-interactive needs --force
```

**`php artisan cache:clear` does not do this:** on `CACHE_STORE=database` it empties `cache` while locks
live in `cache_locks`; in production the Redis cache is on db 1 and `lock_connection` is `default` on
db 0 (`config/cache.php`, `config/database.php`). The command uses
`Cache::lock("catalog-import:{id}")->forceRelease()`, logs a `Log::warning`, and warns that releasing
while a run is alive allows concurrent runs of the same organization.

## Known limitations

* Batches imported before strings were trimmed / empty strings nulled may show a "treść różni się"
  warning on `--skip-existing` for fields with surrounding whitespace or `""`. It is only a warning.

* v1 creates only; it never updates an existing product (a changed payload for an already-imported
  `external_id` is skipped with a warning).
* `audit_logs` entries written by the import (units, and stock rows updated through Eloquent) survive a revert.
* Edited-after-import detection uses second-resolution timestamps; an admin edit in the same second as
  the import's end is not seen.
* The file-reference check covers `services` columns only (a page that embeds a batch image by URL would
  not keep the file).
* Images keep their EXIF/metadata: bytes are stored as-is, exactly like the Filament upload.
* **SIGKILL / OOM-kill / PHP fatal during the import write phase** leaves already-written image files
  with no ledger record (no code runs to delete them) and the lock held until its TTL — see "Releasing a
  stuck lock" and the cleanup hint under "Import flow". The DB work itself is transactional.
* The revert's locking reads (`FOR UPDATE` on `cart_items`, `order_items`, `rentals`, `appointments`)
  and the `service_staff` read take next-key/gap locks and can delay *other tenants'* inserts into those gaps for up to
  `innodb_lock_wait_timeout` while a revert runs. It never corrupts data; run big reverts off-peak.
* Possible deadlock between a revert and an admin editing a unit or stock row of a batch product in the
  panel (lock-order inversion with `ServiceUnitObserver::recalculateAnchor()`, which locks the stock row
  before `services`). InnoDB kills one side; the revert then rolls back cleanly and can be re-run.
* A product deleted *by hand* keeps a live ledger row: a plain re-import reports "już zaimportowany"
  and `--skip-existing` skips it. The remedy is to revert the batch (the revert tolerates the missing row).
* `gallery`, `features` and `specs` must be JSON lists; objects with string keys are rejected.

## Tests

`tests/Unit/Catalog/*` (SSRF, retry/politeness with an injected sleeper/clock, image sniffing, local
paths, AUTO_INCREMENT decision table), `tests/Feature/Catalog/*`: command flow, all-or-nothing, parity
with the real `CreateService` form (drift guard), validator, revert guards and the survival matrix
(`CatalogImportRevertTest`). `CatalogImportAutoIncrementMysqlTest` is MySQL-only and skipped by default
(needs a throwaway MySQL and `CATALOG_MYSQL_TESTS=1`).

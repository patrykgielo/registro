# Package Upgrades Log

## 2026-11-24 — Laravel 12.66.0→12.73.0 + svg-sanitize 0.22.0→1.1.1 (bump bezpieczeństwa)

**Branch:** `feature/laravel-12-73-security` · cel: `composer audit --locked` = 0 (było 14 ostrzeżeń w 7 pakietach,
wszystkie sprzed bumpu Filamenta 4.17).

```bash
composer update laravel/framework league/commonmark league/flysystem symfony/mime symfony/routing symfony/yaml --with-dependencies
composer require "enshrined/svg-sanitize:^1.1.1"
```

`--with-dependencies` (nie `-W`): 36 pakietów zmienionych + 1 nowy, `filament/*` i `livewire/livewire` **bez zmian** —
więc bez `filament:assets`/`livewire:publish`. `-W` ruszyłby 72 pakiety. `svg-sanitize` nie ma łatki 0.22.x —
jedyna droga to 1.x, stąd zmiana w `composer.json` (`^0.22.0` → `^1.1.1`). Laravel 12.73.0 to najnowsze 12.x
(12.74 nie istnieje — sprawdzone w Packagist i GitHub releases).

| Package | Before | After | Ostrzeżenia naprawione |
|---|---|---|---|
| `laravel/framework` | 12.66.0 | 12.73.0 | krytyczne TokenGuard (<12.68), JSON:API relationship inclusion (<12.68), XSS strony debug (<12.69), Concurrency output injection (<12.71) |
| `enshrined/svg-sanitize` | 0.22.0 | 1.1.1 | ×3: XSS przez DTD/encje, DoS `<use>` z `XLINK:HREF`, crash na deklaracji atrybutu DTD |
| `league/commonmark` | 2.10.0 | 2.10.7 | ×2 (DoS tabel GFM — high; obejście DisallowedRawHtml) |
| `league/flysystem` | 3.35.2 | 3.36.0 | normalizacja ścieżek (znaki kontrolne); `flysystem-local` 3.31.0→3.35.3 |
| `symfony/mime` · `routing` · `yaml` | 7.4.15–16 | 7.4.22–23 | wstrzykiwanie nagłówka maila; host placeholder w URL matcherze; DoS parsera YAML ×2 (jedno high) |
| zależności | | | `nesbot/carbon` 3.13.2→3.15.1, `monolog` 3.10→3.13, `vlucas/phpdotenv` 5.6.4→5.8.1, `guzzle` 7.15.3→7.17.0 (+promises/psr7), `laravel/serializable-closure` 2.0.15→2.2.0, `ramsey/uuid` 4.9.3→4.10.0, `symfony/{mailer,translation,clock,uid,css-selector,polyfill-*}`, `doctrine/*`, `nette/schema`, `webmozart/assert`, `phpoption`, `egulias/email-validator`, `cron-expression`, `termwind`; nowy `symfony/polyfill-time` |

**Czy fixy w ogóle nas dotyczą:** TokenGuard — nie (guard `session`, brak Sanctum); JSON:API resources i
`Concurrency::` — nieużywane. To higiena, nie łatanie otwartej dziury.

**Przegląd zmian zachowania 12.67→12.73 vs. nasz kod** (odczyt upstream diffów; żadna zmiana kodu niepotrzebna):

- `Rule::exists/unique()->where()` — wartości porównywane dosłownie (`'NULL'`/`'NOT_NULL'` to już zwykłe stringi),
  ale `where($col, null)` nadal = `whereNull`. Nie przekazujemy tych stringów; `where('organization_id', $tenant?->id)`
  działa jak wcześniej.
- `<x-dynamic-component>` — nazwa komponentu musi pasować do `/^[\w\-:.]+$/`, inaczej `InvalidArgumentException`
  (500 na stronie publicznej). Ikony pochodzą z `Select`/`Rule::in(heroicon)`, więc zła wartość nie powinna trafić
  do bazy. Reguła dla programisty: `.claude/rules/blade-components.md`.
- `email` odrzuca >254 znaki (RFC 5321) — mamy `max:255`, nieszkodliwe. `in` ścisłe (`===` na stringach), wildcard
  na pustych kluczach, `decimal` z kwantyfikatorami possessive — bez wpływu.
- `AuthenticateSession` zapisuje hash hasła w sesji przy następnym żądaniu — **wdrożenie nikogo nie wyloguje**.
- Carbon 3.14–3.15 (CarbonPeriod unserialize, `round()` ≥60 s, `#[NoDiscard]`) — nie używamy dotkniętych API.
- `svg-sanitize` 1.1: API bez zmian (`sanitize()` → `string|false`; teraz `false` zamiast wyjątku), ale ostrzej:
  odrzuca SVG z DTD/encjami, wycina zdalne odwołania i `javascript:`. Nasz kod już kasuje plik i rzuca wyjątek
  na `false`. **Skutek dla użytkownika:** eksport ze starszego Illustratora (DOCTYPE z wewnętrznym podzbiorem,
  `[<!ENTITY ns_svg …>]`) jest teraz odrzucany przy wgrywaniu logo („SVG contains dangerous content"). Zwykły DOCTYPE
  bez podzbioru, `<style>`, gradienty i BOM przechodzą. Obejście dla tenanta: zapisać plik ponownie jako „SVG 1.1" bez
  DTD albo wgrać PNG.

**Nowy test:** `tests/Feature/Security/SvgSanitizationTest.php` (8 testów) — prawdziwa ścieżka
`DesignHub::processLogoUpload()` (czyste SVG, `<script>`/`onload`, `javascript:`, zdalne odwołania, DTD z encją →
odrzucone, DTD z `ATTLIST` → bez crasha, `<use XLINK:HREF>` → bez crasha) + test kontraktu dla dwóch kopii w
`SystemSettings`. Wcześniej nic nie testowało sanitizera. Sprawdzone mutacją: bez wywołania `sanitize()` w `DesignHub`
pada 6 z 8. Downgrade do 0.22 łapie test odrzucenia DTD z encją i kontrakt na śmieciowe wejście; testy `ATTLIST`
i `<use XLINK:HREF>` to tylko smoke testy 1.x — ich payloady nie wywracają też 0.22, więc nie odtwarzają tamtych
ostrzeżeń. Kopie w `SystemSettings` są pokryte tylko kontraktem — usunięcie tam `sanitize()` nie zostanie złapane.

**Testy:** `pint --test` PASS (1136 plików) · pełny suite: 2874 passed, 12 skipped, 0 failed.
`CatalogImportRound3Test::…stale_scratch_threshold…` padł raz w baseline przed bumpem (wyścig na współdzielonym
`storage/app/tmp/catalog-import` przy `--parallel`) — po bumpie przeszedł; to niestabilny test, nie regresja.

**Deploy (ważne dla operatora):**
- `phpdotenv` 5.8: niezamknięty `"` w `.env` to teraz **błąd startu**, nie cicho zgubiona wartość. Szablony w repo
  są poprawne — przed wdrożeniem sprawdź `.env` na serwerze (oraz pliki z sekretami) pod kątem pojedynczego `"`.
- Po `composer install` zrestartuj długożyjące procesy (Horizon, scheduler), żeby załadowały nowy framework.

## 2026-11-22 — Filament 4.11.5→4.17.0 (+ Livewire 3.8.0→3.8.12)

**Branch:** `feature/filament-4-17` · `composer.json` bez zmian (`filament/filament: ^4.11` już dopuszcza 4.17).

```bash
composer update filament/filament livewire/livewire --with-dependencies
php artisan filament:assets && php artisan livewire:publish --assets
```

Samo `filament/filament` zatrzymuje się na 4.13.4 (4.14+ wymaga `livewire ^3.8.6`, a Livewire jest root requirement,
więc bez wskazania nie rusza). `-W` / `filament/*` ciągnie `laravel/framework` 12.66→12.73 i ~50 pakietów — niepotrzebnie.

| Package | Before | After |
|---|---|---|
| `filament/*` (actions, filament, forms, infolists, notifications, query-builder, schemas, support, tables, widgets) | 4.11.5 | 4.17.0 |
| `livewire/livewire` | 3.8.0 | 3.8.12 |
| `laravel/prompts` | 0.3.22 | 0.3.27 |
| `symfony/{console,error-handler,event-dispatcher,http-foundation,http-kernel,html-sanitizer,finder,process,string,var-dumper,polyfill-*,*-contracts}` | 7.4.1x | 7.4.17–7.4.23 |
| `anourvalar/eloquent-serialize`, `composer/class-map-generator`, `composer/pcre` | — | nowe (zależności Filamenta) |
| `blade-icons`, `danharrin/livewire-rate-limiting`, `eloquent-power-joins`, `league/{csv,uri*}`, `masterminds/html5`, `nette/{php-generator,utils}`, `openspout`, `paragonie/constant_time_encoding`, `pragmarx/google2fa(-qrcode)`, `spatie/{invade,laravel-package-tools}`, `ueberdosis/tiptap-php` | — | patch/minor |

`guava/calendar` zostaje 2.1.1 (wymaga `filament/filament ^4.0-beta`). Assety w gicie: 29 plików zmienionych,
11 nowych (`builder.js`, `repeater.js`, `toggle-buttons.js`, `text-input/*`, 7 czcionek Inter), 20 osieroconych czcionek
`inter-*.woff2` usuniętych (nie są w `index.css` ani w vendorze), `public/vendor/livewire/**` — 6 zmienionych.

Wpływ na kod/testy i lista zmian 4.12–4.17, które nas dotykają: `.claude/rules/filament.md` →
„Aktualizacja 4.11.5 → 4.17.0". Trzy istniejące testy `->html()`/widoku (`ServiceFormContentAndSpecLayoutTest` ×2,
`ServiceFormTabsTest` ×1) zaktualizowano za zgodą właściciela do nowego markupu (Repeater/Builder/Tabs renderują się
teraz z PHP) — zmieniły się asercje markupu, nie logika.

**Audyt wyłączonych pól:** grep po `app/Filament/**` nie znalazł ani wyłączonego roota (`disabledSchema`,
`$form->disabled()`), ani wyłączonego kontenera (Section/Group/Fieldset) — każde `->disabled(` stoi na polu, więc zmiana
4.17 „disabled schemas no longer persist or submit state" nie zmienia niczego, co zapisujemy. Przypomnienie:
`->disabled()` ustawia `saved(false)`, wartość wyłączonego pola zapisuje dopiero `->disabled()->dehydrated()`.

**Deploy (ważne dla operatora):** Livewire 3.8.12 wiąże snapshot z `memo.origin` i `memo.route`; snapshot bez nich
(strona otwarta przed wdrożeniem) dostaje 404, więc każda otwarta strona `/admin` i `/platform` zawiedzie przy następnej
akcji, dopóki nie zostanie odświeżona (Livewire: „Refresh already-open pages"). Warstwa ta stoi PRZED naszym
`ResolveTenant`/`RequireTenant` (które nadal są wymagane) — `app/docs/security/patterns/livewire-tenant-isolation.md`.

**`composer audit`:** po bumpie 14 ostrzeżeń, wszystkie istniały wcześniej (żadne nie pochodzi z tego bumpu); bump
naprawił 15 innych, m.in. omijanie MFA w Filamencie, XSS w Livewire i DoS w tiptap. Pozostałe, w tym krytyczny
`laravel/framework <12.68` (TokenGuard), wymagają osobnego bumpu frameworka.

## 2026-05-23 — Laravel 12.46→12.60.2 + Filament 4.5.2→4.11.5

**Branch:** `feature/package-upgrade-may-2026`
**Commit:** `5ab0d6a`

### Versions

| Package | Before | After |
|---------|--------|-------|
| `laravel/framework` | 12.46.0 | 12.60.2 |
| `filament/filament` (+ all sub-packages) | 4.5.2 | 4.11.5 |
| `livewire/livewire` | 3.7.3 | 3.8.0 |

### Security Fixes Applied

**Filament:**
- CVE-2026-33080: Stored XSS via unsanitized field values
- User enumeration via password reset timing
- 2FA code reuse after successful login
- `ImageColumn` XSS via unsanitized alt text
- Temp upload auth bypass (unauthenticated file access)
- `AttachAction`/`AssociateAction` scope bypass on Select field (4.11.4)

**Laravel:**
- No CVEs; see bug fixes below.

### Bug Fixes (Laravel 12.46→12.60.2)

- **12.54** — Queue deadlock on job reservation exception in high-concurrency scenarios
- **12.55.1** — `trans_choice()` float argument not working for Polish-locale pluralization
- **12.57** — Rate limiter infinite TTL when using custom `incrementBy` value
- **12.59** — Infinite recursion: Eloquent model scopes + private property access

### Bug Fixes (Filament 4.5.2→4.11.5)

- **4.6.3** — `MorphToSelect` with `afterStateUpdated()` fired callback multiple times
- **4.9.3 / 4.11.2** — Multi-tenant dashboard/home route precedence conflicts
- **4.9.4** — `Group` component: hidden sibling caused shared `statePath` data to be silently dropped on save
- **4.11.3** — `Select` field retained stale cached relation options after record save
- **4.11.3** — Re-authorization during Livewire hydration triggered incorrectly in multi-tenant context
- **4.11.4** — `AttachAction`/`AssociateAction` did not enforce scope on inner Select field

### Audit Findings (pre-upgrade grep)

| Risk | Status |
|------|--------|
| `Group::make()` with hidden siblings | Not used — no action needed |
| `AttachAction`/`AssociateAction` | 2 usages (ServicesRelationManager, MembersRelationManager) — no custom scope modifiers, no behavioral change |
| `MorphToSelect` | Not used — no action needed |
| `assertFormExists()` in tests | Not used — no action needed |

### Test Results

```
Tests: 7 failed, 418 passed (863 assertions)
```

All 7 failures are **pre-existing** (known before this upgrade):
- `BookingServiceAreaBypassTest` × 4
- `CustomerOrdersTest` × 2
- `TenantFeatureTest` × 1

No new failures introduced.

### Build

- `npm run build`: success (60 modules, 2.95s)
- `./vendor/bin/pint --test`: PASS (588 files)
- `php artisan filament:upgrade`: assets published, caches cleared

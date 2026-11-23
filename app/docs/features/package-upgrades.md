# Package Upgrades Log

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

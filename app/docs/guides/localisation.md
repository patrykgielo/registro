# Localisation contract (pl + en)

Owner requirement (ClickUp 86cbb2frk): **"translacja musi być dostarczona i obsługiwać polski i angielski."**

## Scope: what is covered, and what is NOT

**Covered in both languages and kept in step by `tests/Feature/Localisation/`:**
validation messages and field names (`validation.php`, incl. `custom` and `attributes`), login / register / password
reset / email verification, pagination, the Polish tax-ID rules (`rules.php`), the whole **storefront** — layout, header,
footer, CMS blocks, `services/**`, `rentals/**`, `cart/**`, `checkout/**` (incl. the return page), `orders/**` (list, detail,
handover / return protocol PDFs), `profile/**`, `portfolio/**`, `posts/**`, `partials/**`, `errors/**` (closed / suspended /
maintenance / pre-launch pages), the customer-facing `components/**` — the flash messages of `CartController`,
`OrderController` and the `Check{Rental,Booking,Registration}Enabled` middlewares, the price / duration text built by
`Service::formatted_rental_price` / `formatted_duration`, the checkout, availability, pickup-branch and rental-extension
exception messages, admin navigation groups, service-area strings, and every global email/SMS template row.

### What stays Polish — on purpose (do not "fix" these)

These are decisions, not omissions. `NoHardcodedPolishInViewsTest::EXEMPT` lists every exempt **file** (never a directory) under
its reason, and `test_every_exempt_file_still_contains_polish` fails per file when the file is gone or no longer holds Polish —
so a file that was cleaned up leaves the list instead of staying exempt forever. The flip side: a *new* view under
`filament/**` or the legacy flow that contains Polish fails the lint until it is added to `EXEMPT` with a reason.

| What | Why |
|---|---|
| `resources/views/filament/**`, all Filament resources/pages/widgets | The admin panel stays Polish — decision of the product owner. Filament's own UI strings are vendor pl + en already. |
| `booking-wizard/**`, `components/booking-wizard/**`, `booking/**`, `appointments/**`, `BookingController` / `AppointmentController` messages, `resources/js/booking-wizard.js`, `resources/js/serviceAreaMap.js` | The legacy appointment flow. The product is `equipment_rental` only; the flow is not reachable for a rental tenant. |
| `statistics/**` | Admin statistics PDF. |
| `emails/user-registered-pl.blade.php` | The `-pl` suffix *is* the language selector; an English version is a separate file (`appointment-created-en` is the existing example). |
| `mail/sms-spending-alert.blade.php` | Goes to the platform owner, not to a customer. |
| Text that comes from the database, not from a view: navigation menu labels, CMS page / post / block content, service names and descriptions, the checkout consent texts (`checkout.terms_label`, `rodo_label`, `withdrawal_label`, `deposit_policy_note` — tenant-editable settings, seeded in Polish), `prelaunch` / `maintenance` copy configured in settings | Tenant content. The lint cannot see it and translating it is the job of the future admin "Translations" tab, not of a view. An English customer reading a tenant with Polish legal copy is a **content** problem of that tenant. |

If a file you skip contains a string a customer can see, say so in the PR instead of skipping silently.

Not translated even inside covered files (they are not text): number format (`1 234,50`), date format (`d.m.Y`), currency
position. The currency *word* is translated: `common.currency` is `zł` / `PLN`. English `MaintenanceType::label()` stays as it is.

| Also Polish, reached through a different path | Why |
|---|---|
| `OrderProtocolPdfService` `DomainException` messages (`Protokół wydania jest dostępny dopiero po…`, `Protokół zwrotu jest dostępny dopiero po…`) | They reach the customer through `OrderProtocolController`'s `abort(404, $e->getMessage())`, so an English customer clicking a protocol link too early reads Polish. Not done in this change; move them to `orders.php` and `__()` them when this is picked up. |

### Known gaps, written down on purpose

- **English-only ARIA labels** (hardcoded English, so a Polish screen-reader user hears English): `Breadcrumb` (`services/show`, `rentals/category`, `portfolio/category`, `posts/category`, `components/ios/breadcrumbs`), `Main navigation` (`components/nav/header`), `Go to homepage` (`ios/nav-logo`), `Toggle password visibility` (`ios/input`), `Mobile navigation menu` and `Close menu` (`ios/mobile-drawer`), `Primary navigation` (`ios/tab-bar`). The lint cannot see English; to be done as one pass.
- **The English protocol wording has NOT had legal review.** `orders/protocols/handover` and `return` (Lessor / Renter declarations) were translated by a developer; the product owner accepted that risk on 2026-10-04.
- **`?? 'Poznań'`** — `ServiceController` (JSON-LD `areaServed`) falls back to a fixed city when a service has no `area_served`. Wrong data for any other tenant, not a language problem. Ticket `123k99cx3ec`.
- **Number and date formats do not follow the locale** (`1 234,50`, `d.m.Y`, even on an English page). Ticket `123k99cx3ed`.
- **Consent texts are Polish-only** (`checkout.terms_label`, `rodo_label`, `withdrawal_label`, `deposit_policy_note`; seeded and defaulted in `CheckoutController` / `SettingSeeder`). Ticket `123k99cx3ee`.

## Where strings live

| What | Where | Key style |
|---|---|---|
| Laravel validation messages, `custom`, `attributes` | `lang/{pl,en}/validation.php` | `validation.*` |
| Messages of rule objects (`app/Rules/Valid*`) | `lang/{pl,en}/rules.php` | `rules.nip.length` |
| Login / password-reset / paginator | `lang/{pl,en}/{auth,passwords,pagination}.php` | framework keys |
| Feature groups | `lang/{pl,en}/{navigation,service_area}.php` | `navigation.groups.users` |
| **New** storefront text, flash messages, price / duration text | `lang/{pl,en}/<group>.php` — see "Key naming" | `cart.checkout`, `orders.status.paid` |
| Older customer-area text (profile tab pages, a few flash messages) | `lang/pl.json`, `lang/en.json` | the Polish sentence is the key |
| Filament's own UI | vendor (`filament-*::`) — already pl + en | not ours |

Default locale `pl` (`APP_LOCALE`), fallback `en` (`APP_FALLBACK_LOCALE` — must stay `en`, see
`deployment/environment-variables.md`).

## Key naming: dotted group files for new text, JSON left alone

New extractions go to `lang/{pl,en}/<group>.php` with a dotted key (`__('cart.checkout')`). The 172 existing JSON keys
(`__('Dane osobowe')`) are untouched and still work; a view may use both.

| Group file | Holds |
|---|---|
| `common.php` | words every page needs: `currency` (`zł`/`PLN`), `currency_per_day`, `per_day`, `pcs`, `from`, plural `days`, `positions` |
| `account.php` | login, register, forgot / reset / set-up password, token-expired, verify e-mail |
| `cart.php`, `checkout.php`, `orders.php` | the three purchase screens (orders incl. status labels and the two protocol PDFs) |
| `services.php`, `rentals.php` | product page, product list, rental shop and category pages, price / duration text from `Service` |
| `storefront.php` | everything shared or CMS-like: header, footer, layout, portfolio, posts, CMS blocks, home fallback |
| `profile.php` | the profile pages' text that was still hardcoded |
| `errors.php` | the standalone error / maintenance pages |
| `flash.php` | messages set in controllers / middleware (`->with('success', __('flash.cart.added'))`) |

**Why not the Polish sentence as the key for these too:** the planned admin "Translations" tab needs to list PL and EN
side by side *per group*. A dotted key gives that grouping for free (`orders.*`, `checkout.*`); a sentence-as-key has no
structure to group by, and editing one Polish word would silently change the key and orphan its English twin. Dotted keys
also survive a copy edit of the Polish text. The price: a missing dotted key renders the raw key (`cart.checkout`)
instead of readable Polish — which is why every key must exist in both languages (the parity test) and every key used in a
view must exist (the coverage test).

**Migration path for the JSON keys (not done, deliberately):** per JSON key — add a dotted entry to the right group file
in both languages, replace `__('Polish sentence')` in the code, delete the entry from `pl.json` and `en.json`. The Tab
can treat what is left in the JSON files as one group ("other"). The profile area is the first candidate
(`profile.php` already exists). Do it per file, with the suites green, not as one sweep.

## Rules

1. **`lang/pl.json` must contain every key `lang/en.json` has — including the ones whose Polish value equals the key.**
   Laravel looks a missing JSON key up in the fallback language: a key absent from `pl.json` but present in
   `en.json` shows **English to a Polish customer**. The identity entries are load-bearing, not boilerplate.
2. Keys written in English at the source (framework views: paginator, mail footer) need a *real* Polish value in
   `pl.json`. They are listed in `TranslationKeyCoverageTest::ENGLISH_SOURCE_KEYS`.
3. Placeholders (`:name`, `:max`) must be identical in both languages.
4. No Polish diacritics in `lang/en/**` or `en.json` values (catches a Polish sentence pasted as "translation").
5. Translation keys in code must be **literals** (`__('…')`). `__($variable)` cannot be verified; build the array with
   `__('…')` per entry instead (see `resources/views/profile/layout.blade.php`).
6. Do not hardcode user-facing text in `messages()` / `$fail('…')` / `withErrors([...])` — use `__()`.
   Per-field messages that are unique to a field name go in `validation.custom.<field>.<rule>` and need **no**
   `messages()` method at all (Laravel resolves them). **`custom` is keyed by field NAME, not by request** — it applies to
   every form with that field. Only add an entry whose wording is right for all of them, and take values from the rule
   (`:min`, `:date`) instead of writing "1" into the text. Where two forms share a field name but need different wording,
   keep `messages()` and point it at `__('…')`.
7. The validator does **not** pluralise messages (no `trans_choice`; `{1} … |[2,4] …` in `validation.php` is ignored), so
   Polish messages are worded plural-neutrally ("Liczba znaków w polu X nie może przekraczać 20.") instead of
   "… 1 znaków".
8. **Pluralise with `trans_choice`, written as bare forms** — `:count pozycja|:count pozycje|:count pozycji`. Laravel's
   `MessageSelector` has the Polish rule (1 / last digit 2-4 except 12-14 / the rest); explicit ranges (`{1} … |[2,4] …`) get
   22 wrong. English needs two forms. Never `$n === 1 ? 'x' : 'y'` in a view.
9. **Placeholders, not concatenation**: `__('cart.location_change.reduced', ['from' => $a, 'to' => $b])`, never
   `__('…').$n.__('…')`. A sentence with markup inside it (`<strong>`, a link) is **one** key with a placeholder that carries
   the HTML, rendered with `{!! … !!}` and `e()` on every user value — never a sentence cut into fragments.
10. **Strings in JavaScript** inside a view: `@js(__('group.key'))` (safe in a double-quoted attribute). A placeholder that only
    JS knows is replaced client-side: `@js(__('services.show.cell_partial')).replace(':count', qty)`.
11. **`@section('title', '…')`, `@props` defaults, `match()` arms in `@php`** are text too — `__()` them. The lint reads them.
12. Currency word: `{{ __('common.currency') }}`, never a literal `zł`.
13. `lang` attributes follow the locale: `str_replace('_', '-', app()->getLocale())`.

## How to add a key

1. Pick the group (table in "Key naming"). Add the key to **both** `lang/pl/<group>.php` and `lang/en/<group>.php`
   (same nesting, same placeholders), use it as `__('<group>.<key>')` / `trans_choice(…)`. Only add a JSON key
   (Polish sentence, in `pl.json` **and** `en.json`) when you are editing a file that already lives on those.
2. New form field a customer can submit: add it to `attributes` in **both** `validation.php` files, otherwise the error
   reads `Pole customer_xyz jest wymagane.`.
3. Run `php artisan test tests/Feature/Localisation` — the failure names the missing key, the language and the file:line;
   the lint names the file:line of any Polish you left in a view.
4. New view or directory: nothing to do, it is in the lint's scope by default. Only a file that must stay Polish needs an
   `EXEMPT` entry **with a reason**.

## What the tests pin

- `TranslationParityTest` — identical PHP-file set and recursive key set in `lang/pl` and `lang/en`; identical JSON keys;
  identical placeholders; no empty values; no Polish text in English; `validation.php`, `auth.php`, `passwords.php`,
  `pagination.php` contain every key the framework version in `vendor/` ships (a framework upgrade that adds a rule
  fails here).
- `TranslationKeyCoverageTest` — every literal key used in `app/`, `resources/views/`, `routes/` resolves in both
  languages (also with a leading backslash; `app/ resources/views/ routes/ config/ database/ bootstrap/`; dotted keys via `Translator::has(…, fallback: false)`, JSON keys by reading the file — `Lang::has()` is wrong
  for JSON identity entries); no computed keys.
- `ValidationLocaleHttpTest` — real routes (`/customer/register`, `/koszyk/zamowienie`, `/login`): the same invalid
  submission under `pl` and `en`, asserting the exact text and that no raw `validation.x` key or snake_case field name leaks.
- `NoHardcodedPolishInViewsTest` — the lint. Every `*.blade.php` under `resources/views` is in scope **unless** listed in
  `EXEMPT` with a reason. It blanks Blade / HTML / JS comments and the literal key of `__()` / `trans()` / `trans_choice()` /
  `@lang()`, then fails — with `file:line` and the line — on any Polish diacritic left (text, attribute, `@php`, inline
  `<script>`), on a literal second argument of `@section('title', '…')`, and on a list of diacritic-free Polish words (`POLISH_WORDS`: zamknij, anuluj, brak, razem, status, …) in a plain text
  node, in the value of a text-bearing attribute (`alt`, `title`, `placeholder`, `aria-label`, `label`, `value`, …) and in a
  quoted literal that reads as text (a phrase or a capitalised word) inside `@php`, `<script>` or a `{{ }}` expression.
  Lower-case single words used as identifiers (`$order['status']`, `session('status')`, `data-animate`) are not flagged.
  A second test fails per exempt file that no longer holds Polish; data-provider tests feed the detector every listed word
  in a text node, an attribute, an `@php` literal, an inline script and a Blade expression, plus the identifier cases above. **It cannot see**: English text,
  Polish without diacritics and outside `POLISH_WORDS`, text built in PHP or taken from the database.
- `StorefrontLocaleHttpTest` — login, cart (plural + currency), empty cart, product page, rental list, checkout, orders list,
  the rental-gate and add-to-cart flash messages, fetched through real routes under `pl` and `en`; asserts the language's text
  is present **and** the other language's text is absent. This is what catches a wrong key / placeholder that the lint
  (which only looks at source) accepts. Plus the Polish plural forms of `common.positions` (1 / 2 / 5 / 12 / 22 / 25).
- `TemplateLanguageParityTest` — every global email/SMS template exists in `pl` and `en` (fails if there are no rows at all).

## Known limits (deliberately not done)

- **No way for a user to choose English today.** There is no switcher, no `Accept-Language` handling, no
  `setLocale()` anywhere in `app/`, `routes/` or `bootstrap/`; the locale is the process-wide `APP_LOCALE`. The language
  of mail/SMS comes from `users.preferred_language` (default `pl`, no UI to change it) and is passed to
  `EmailService::sendFromTemplate()` per notification. Queued notifications render their `__()` calls in the worker's
  default locale.
- **No language fallback for templates.** `EmailTemplate::resolveActive()` / `SmsTemplate::resolveActive()` match
  `language` exactly; a missing row throws (`Email template '…' not found for language 'en'`). Global rows exist in both
  languages today (pinned by `TemplateLanguageParityTest`), but a *tenant override* in one language only has no
  fallback to the global row of the other.
- Hardcoded Polish outside the covered scope: see **Scope** above (what stays Polish, and why).
- Tenant-authored text is not translated by views: if a tenant fills a setting in Polish, the English storefront shows it in Polish.
- Filament validation uses the field label as the attribute name; the `attributes` array does not apply there.

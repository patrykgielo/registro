# Localisation contract (pl + en)

Owner requirement (ClickUp 86cbb2frk): **"translacja musi być dostarczona i obsługiwać polski i angielski."**

## Scope: what is covered, and what is NOT

**Covered in both languages and kept in step by `tests/Feature/Localisation/`:**
validation messages and field names (`validation.php`, incl. `custom` and `attributes`), login / password-reset
(`auth.php`, `passwords.php`), pagination, the Polish tax-ID rules (`rules.php`), the customer profile area
(`resources/views/profile/**` and its controllers/requests), the checkout / cart / rental-request validation messages,
the checkout, availability, pickup-branch and rental-extension flash/exception messages, the branch card
(`components/ios/location-card`), admin navigation groups, service-area strings, and every global email/SMS
template row.

**NOT covered — the storefront is still Polish-only.** Of 154 non-vendor Blade files, 79 contain Polish letters; **70 of them
have no `__()` / `@lang` at all** (login, register, cart, services/show, orders, layouts, CMS blocks, …) and only 9
(the profile area and the branch card) are translated. The count is a lower bound — it misses Polish text without
diacritics — and the 9 translated files may still hold stray literals.
Also hardcoded: flash messages in `CartController` (`Dodano do koszyka.`) and `OrderController`
(`Zamówienie zostało anulowane.`), the messages of the `CheckBookingEnabled` / `CheckRentalEnabled` /
`CheckRegistrationEnabled` middlewares, `$fail('Polish')` in `AssignableRole`, `ProtectedRoleName`, `StaffRoleRule`,
`ValidOrganizationSlug`, the legacy appointment-booking flow (`BookingController` `$messages`, `AppointmentController`,
`AppointmentObserver`, `ProfileService` / `UserAddressService` / `UserVehicleService` exceptions), and the whole
Filament admin (resources, forms, tables, notifications — only navigation groups are translated). The coverage test
cannot see any of this: it only checks keys that are *already* wrapped in `__()`. Until those are moved, an English
locale gives English validation/auth/profile/checkout errors on a mostly Polish page.

## Where strings live

| What | Where | Key style |
|---|---|---|
| Laravel validation messages, `custom`, `attributes` | `lang/{pl,en}/validation.php` | `validation.*` |
| Messages of rule objects (`app/Rules/Valid*`) | `lang/{pl,en}/rules.php` | `rules.nip.length` |
| Login / password-reset / paginator | `lang/{pl,en}/{auth,passwords,pagination}.php` | framework keys |
| Feature groups | `lang/{pl,en}/{navigation,service_area}.php` | `navigation.groups.users` |
| Customer-area UI text and flash messages | `lang/pl.json`, `lang/en.json` | **the Polish sentence is the key** |
| Filament's own UI | vendor (`filament-*::`) — already pl + en | not ours |

Default locale `pl` (`APP_LOCALE`), fallback `en` (`APP_FALLBACK_LOCALE` — must stay `en`, see
`deployment/environment-variables.md`).

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

## How to add a key

1. Use it in code: `__('Moja nowa wiadomość.')` (UI/flash) or `__('rules.foo.bar')` (rule/feature file).
2. JSON key: add it to **both** `lang/pl.json` (value = the same sentence) and `lang/en.json` (English). Dotted key: add it
   to the same file in `lang/pl/` **and** `lang/en/`.
3. New form field a customer can submit: add it to `attributes` in **both** `validation.php` files, otherwise the error
   reads `Pole customer_xyz jest wymagane.`.
4. Run `php artisan test tests/Feature/Localisation` — the failure message names the missing key, the language, and
   the file:line that uses it.

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
- Hardcoded Polish outside the covered scope: see **Scope** above.
- Filament validation uses the field label as the attribute name; the `attributes` array does not apply there.

---
paths:
  - "lang/**"
  - "app/Http/Requests/**"
  - "app/Rules/**"
  - "resources/views/**"
  - "tests/Feature/Localisation/**"
---

# Localisation: pl + en in step (owner requirement, ClickUp 86cbb2frk)

Full contract, group table and test map: `app/docs/guides/localisation.md`.

- Every key goes into **both** `lang/pl/**` and `lang/en/**` (or `pl.json` **and** `en.json`) in the same change.
  `tests/Feature/Localisation/` fails with the missing key, language and file:line.
- **New text = dotted key in a group file** (`__('cart.checkout')`, `lang/{pl,en}/cart.php`), not a new JSON key: the admin
  Translations tab groups by file. Existing JSON keys stay (Polish sentence = key; `pl.json` MUST list them too, value = key,
  else a Polish customer gets the `en.json` fallback). Don't use `Lang::has()` for JSON keys.
- No hardcoded Polish in a customer-facing view — `NoHardcodedPolishInViewsTest` fails with `file:line` (text, attributes,
  `@php`, `<script>`, `@section('title', '…')`). Literal keys only (`__('…')`, never `__($var)`); none in `messages()`, `$fail()`,
  `withErrors()`, `->with('success', …)` either.
- Counts: `trans_choice('common.positions', $n)` with bare forms `a|b|c` (Polish rule 1 / 2-4 / 5+), never `$n === 1 ? … : …`.
  Values: placeholders (`:count`), never concatenation. A sentence containing markup = ONE key with an HTML placeholder
  (`{!! !!}` + `e()`), never fragments. JS in a view: `@js(__('…'))`. Currency word: `__('common.currency')`.
- **Stays Polish on purpose — do not "fix":** `resources/views/filament/**` and every Filament resource/page (admin panel =
  product-owner decision); the legacy appointment flow (`booking-wizard/**`, `booking/**`, `appointments/**`,
  `BookingController`, `AppointmentController`, `resources/js/booking-wizard.js`, `serviceAreaMap.js`); `statistics/**`;
  `emails/user-registered-pl`; tenant-authored content (menus, CMS, consent texts in settings). Exemptions are **per file** in the
  lint's `EXEMPT`, each with a reason; a file that stops needing it fails the test. A new Polish view under those areas must be added
  there. English-only ARIA labels (`Breadcrumb`, `Main navigation`, …) are a listed gap in the guide, not a model to copy.
- Per-field messages: `validation.custom.<field>.<rule>`, no `messages()` method needed. New customer-submittable field =
  new entry in `attributes` in both `validation.php`.

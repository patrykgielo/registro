---
paths:
  - "lang/**"
  - "app/Http/Requests/**"
  - "app/Rules/**"
  - "resources/views/**"
  - "tests/Feature/Localisation/**"
---

# Localisation: pl + en in step (owner requirement, ClickUp 86cbb2frk)

Full contract and test map: `app/docs/guides/localisation.md`.

- Every key goes into **both** `lang/pl/**` and `lang/en/**` (or `pl.json` **and** `en.json`) in the same change.
  `tests/Feature/Localisation/` fails with the missing key, language and file:line.
- JSON keys are the Polish sentence. `pl.json` MUST list every key too (value = the key): a key missing from `pl.json`
  falls back to `en.json` and shows **English to Polish customers**.
- Literal keys only (`__('…')`, never `__($var)`); no hardcoded Polish in `messages()`, `$fail()`, `withErrors()`.
- Per-field messages: `validation.custom.<field>.<rule>`, no `messages()` method needed. New customer-submittable field =
  new entry in `attributes` in both `validation.php`.
- Do not use `Lang::has()` to check JSON keys (identity values read as "missing").

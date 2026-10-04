---
name: project-localisation-pl-en
description: pl+en localisation contract (2026-10-04, feature/tlumaczenia-pl-en) - lang layout, pl.json identity requirement, parity/coverage tests
metadata:
  type: project
---

`lang/{pl,en}/{validation,rules,auth,passwords,pagination,navigation,service_area}.php` + `lang/{pl,en}.json` (JSON key = Polish sentence). Guarded by `tests/Feature/Localisation/` (parity, key coverage, HTTP pl/en, template pl/en rows). Contract: `app/docs/guides/localisation.md`, rule `.claude/rules/localisation.md`.

**Why:** owner requirement "translacja musi byc dostarczona i obslugiwac polski i angielski" (ClickUp 86cbb2frk).

**How to apply:** `pl.json` MUST carry every key en.json has (identity values) - Laravel falls back pl->en for a missing JSON key, so a Polish user would see English. `Lang::has()` is wrong for JSON identity keys (value===key reads as missing). `FormRequest::messages()` for unique field names can be deleted in favour of `validation.custom.<field>.<rule>`. Pure-PHPUnit unit tests of rule objects now need `Tests\TestCase` because rules call `__()`. `app/vendor` (gitignored stray dir inside app/) must be skipped by any source scanner.

**Storefront extraction (2026-10-04, branch feature/i18n-widoki, uncommitted):** ~560 new keys in dotted group files (`account cart checkout common errors flash orders profile rentals services storefront`), NOT in the JSON; rationale = admin Translations tab groups by file. Lint `NoHardcodedPolishInViewsTest` (whole `resources/views` in scope minus an `EXEMPT` prefix map with reasons + stale-entry check) + `StorefrontLocaleHttpTest`. Admin/Filament, legacy booking flow, statistics stay Polish by owner decision (listed in the guide). Pitfalls hit: (1) the lint's naive `@lang` regex copied from the coverage test's `(?<![\w>:$])` lookbehind misses `<p>@lang(` because `>` precedes; (2) `__($cond ? 'a' : 'b')` is a computed key and fails the coverage test - use two literal `__()` calls; (3) a diacritic scan is blind to `@section('title','Moje Konto')` and short text like `Opublikowano:` - the lint has a separate literal-section check and a small `POLISH_WORDS` list; (4) `trans_choice` Polish needs bare `a|b|c` forms (MessageSelector has the pl rule), not `{1}|[2,4]` ranges; (5) HTTP assertions on "the other language is absent" can false-positive on English words in JS comments/JSON-LD (`Monday`, `PLN`) - assert on strings that only the view renders. `Service::formatted_rental_price/duration` (customer tile text) lived in the model, not the view.

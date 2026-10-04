---
name: project-localisation-pl-en
description: pl+en localisation contract (2026-10-04, feature/tlumaczenia-pl-en) - lang layout, pl.json identity requirement, parity/coverage tests
metadata:
  type: project
---

`lang/{pl,en}/{validation,rules,auth,passwords,pagination,navigation,service_area}.php` + `lang/{pl,en}.json` (JSON key = Polish sentence). Guarded by `tests/Feature/Localisation/` (parity, key coverage, HTTP pl/en, template pl/en rows). Contract: `app/docs/guides/localisation.md`, rule `.claude/rules/localisation.md`.

**Why:** owner requirement "translacja musi byc dostarczona i obslugiwac polski i angielski" (ClickUp 86cbb2frk).

**How to apply:** `pl.json` MUST carry every key en.json has (identity values) - Laravel falls back pl->en for a missing JSON key, so a Polish user would see English. `Lang::has()` is wrong for JSON identity keys (value===key reads as missing). `FormRequest::messages()` for unique field names can be deleted in favour of `validation.custom.<field>.<rule>`. Pure-PHPUnit unit tests of rule objects now need `Tests\TestCase` because rules call `__()`. `app/vendor` (gitignored stray dir inside app/) must be skipped by any source scanner.

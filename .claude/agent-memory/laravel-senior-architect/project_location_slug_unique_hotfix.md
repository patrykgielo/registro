---
name: project-location-slug-unique-hotfix
description: 2026-08-27 hotfix — Filament ->unique() ignoring organization_id scoping blocked every tenant from saving their primary Location; also surfaced an app-wide raw "validation.unique" message bug
metadata:
  type: project
---

Branch `fix/location-slug-unique-per-tenant` (from `develop` at `f87238d`, PR #228's Faza 1 merge).
Reported as a browser-verified blocker: no tenant could save an edit to their primary Location —
the backfill migration (`2026_08_27_120001`) gave every tenant's primary branch the same slug
(`siedziba-glowna`), and `LocationForm.php`'s `->unique(ignoreRecord: true)` had no `organization_id`
scoping, so it was a global rule against a table whose real constraint is
`UNIQUE(organization_id, slug)`.

**Fix:** `modifyRuleUsing: fn (Unique $rule) => $rule->where('organization_id',
TenantFeature::currentTenant()?->id ?? -1)` — first use of `modifyRuleUsing` in the repo. Null-tenant
fallback is `-1` (never a real org id) so the rule becomes a no-op rather than throwing or silently
allowing same-tenant duplicates; the DB constraint is the backstop in that case. Full pattern +
the 7 OTHER resources with the identical bug (found, not fixed — team lead's call) are in
`.claude/rules/filament-resources.md` under "`->unique(ignoreRecord: true)` bez `organization_id`".

**Second, much bigger finding while chasing the raw `validation.unique` message:** `APP_FALLBACK_LOCALE=pl`
in `.env.testing`/`.env.production.example`/`.env.local.example` (confirmed via `git log -p`: wrong
since each file's very first commit, not a regression) with **no `lang/pl/validation.php` anywhere**
(app or vendor — Laravel core only ships `en`, Filament's own `pl/validation.php` only has 2 custom
keys) means Laravel's translator has nothing to fall back to and returns the raw key. This is
app-wide, not Locations-specific — every validation message not explicitly overridden by Filament
has been silently broken since day one, likely including real production if `.env.production` was
ever provisioned from the (wrong) example template. Fixed by flipping fallback to `en` in all three
files + real dev `.env` (`.env.example`/`.env.staging.example` already had `en`, untouched). Result
is readable ENGLISH validation text, not Polish — a real `lang/pl/validation.php` is the complete
fix but is separate, larger scope, recommended not implemented. **Production's live `.env` was not
touched — flagged for the team lead, needs manual SSH fix if provisioned before this date.**

Test: `tests/Feature/Filament/LocationSlugUniqueScopeTest.php` — proved red before either fix
(reverted each independently via `git stash` / a one-line `sed` flip, confirmed red, then restored)
and green after. Full suite 1539 passed / 5 skipped (baseline was 1536/5 on `develop`), Pint clean.

[[project_lokalizacje_faza1_kroki_1_1_1_2_1_6]]

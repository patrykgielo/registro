---
name: project-dead-primary-scale
description: Recurring bug — resources/views/**/*.blade.php reference a `primary-*` Tailwind scale that design-tokens.css never registers (only `brand`/`brand-hover`/`brand-subtle`); Tailwind v4 silently drops it, causing invisible text/icons. Status and remaining file list.
metadata:
  type: project
---

`design-tokens.css` registers exactly three brand tokens: `brand`, `brand-hover`, `brand-subtle`.
No `primary` scale exists (no `theme.extend.colors.primary` either). Tailwind v4 silently drops
any utility class it can't resolve — `bg-primary-600`, `text-primary`, `ring-primary-500`, etc.
compile to **nothing**, not an error. This has caused multiple independent "invisible element"
production bugs, all the same root cause, found piecemeal:

- `components/ios/service-card.blade.php`'s no-image icon badge (`bg-primary-500`) — fixed
  2026-08-16 ("Fifth pass", see `app/docs/features/tenant-branding.md`)
- `components/ios/auth-card.blade.php`'s page background (`bg-primary-600`) causing white-on-white
  login/register text, plus a second contrast bug on `text-white/90`/`text-white/70` once the
  background was fixed — fixed 2026-08-16, PR #210 ("Sixth pass", same doc)

- `components/cms/card.blade.php:33`'s "Zobacz szczegóły" link (`text-primary-600
  hover:text-primary-700`) — found 2026-08-27 while building the locations content-grid card
  (`app/docs/features/lokalizacje/plan-wdrozenia.md` step 1.7); left unfixed (out of that task's
  scope), but the new `components/ios/location-card.blade.php` deliberately used `text-brand
  hover:text-brand-hover` instead of copying this file as a template, and a regression test
  (`tests/Feature/LocationCardComponentTest.php::test_renders_with_every_optional_field_present`)
  pins that the new component never re-adds `text-primary-`. This card renders on EVERY
  posts/promotions/portfolio content-grid block today — the link is effectively invisible in the
  light variant on every one of them, not just a theoretical bug.

**Full remaining scope (~45 files, several hundred occurrences), listed and NOT fixed as of
PR #210:** `components/ios/{button,service-card,hero-banner,breadcrumbs}.blade.php`,
`components/cms/{card,partials/builder-blocks,partials/content-header,partials/sidebar}.blade.php`,
`components/interactive/{modal,drawer,toast,tooltip}.blade.php`,
`components/content-blocks/feature-list.blade.php`,
`components/booking-wizard/{calendar,time-grid}.blade.php`, `components/nav/header.blade.php`,
`{home-fallback,rentals/index,rentals/category,portfolio/category,posts/category}.blade.php`,
`{cart/show,checkout/show,checkout/return,orders/index,orders/show,booking/create}.blade.php`,
`booking-wizard/{layout,confirmation,steps/datetime,steps/contact,steps/vehicle-location}.blade.php`,
`profile/{index,layout,modals/change-email,partials/tab-*}.blade.php` — full list with exact
current file paths at time of a follow-up is authoritative via
`grep -rlP '(?<![\w-])(bg|text|border|from|to|via|ring)-primary(-[0-9]+)?(/[0-9]+)?\b' resources/views --include="*.blade.php"`.

**Why not fixed in one mechanical sweep:** those files use the full numbered scale
(`primary-50` through `primary-900`), and collapsing 10 shades into 3 registered tokens
(`brand`/`brand-hover`/`brand-subtle`) requires a per-file design decision (which shade → which
token, or a genuinely different treatment like an outline instead of a fill) — not a safe
find-and-replace. Several carrying files are business-critical checkout/cart/booking flows.

**Excluded, correctly:** `filament/pages/*`, `filament/components/*` — these compile through a
separate Vite entry (`resources/css/filament/admin.css`/`platform.css`) with Filament's own
`primary` color scale. `bg-primary-*` there is NOT this bug.

**When picking up the follow-up:** grep the command above first — the file list drifts as other
work touches these views. Fix per-file, with a contrast check (see
[[feedback_verify_wcag_contrast_numerically]]) for any text placed on a colored background, not
just a class-name swap.

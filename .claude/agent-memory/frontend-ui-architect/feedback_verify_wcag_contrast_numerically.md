---
name: feedback-verify-wcag-contrast-numerically
description: When text sits on a brand/OKLCH-token background (especially translucent text, e.g. text-white/70), compute the actual WCAG contrast ratio instead of eyeballing it — opacity that looks fine can fail AA, and this project's brand color is per-tenant overridable so today's default isn't a safe assumption.
metadata:
  type: feedback
---

Caught during the PR #210 auth-card fix (2026-08-16): `text-white/90` and `text-white/70` on
`bg-brand` (default `oklch(55% 0.2 250)`) look readable at a glance but measure 4.13:1 and 3.09:1
respectively — both below the 4.5:1 WCAG AA floor for normal-size text. Only `text-white` at full
opacity (4.74:1) passes, and even that has almost no margin.

**Why:** `--color-brand` in `design-tokens.css` is OKLCH, and OKLCH lightness doesn't map linearly
to WCAG relative luminance — you cannot eyeball "medium-lightness blue + white text" as safely
passing. Translucent white text compounds this by blending toward the background color, silently
lowering contrast further.

**How to apply:** any time a Blade/component change puts text directly on a `bg-brand` (or another
semantic color token) background, actually compute the contrast ratio before deciding an opacity
value is fine — convert OKLCH → linear sRGB → relative luminance → WCAG contrast ratio (small
Python script, not a guess; see PR #210's session for the working conversion code). Prefer solid
text color over translucent text on colored backgrounds in this design system generally — this
project's brand color is per-tenant-overridable (`design.brand_color`), so an opacity value tuned
to "passes against today's default" is fragile against a tenant picking a lighter brand color.
Differentiate visual hierarchy (title vs. subtitle vs. footer) via font-size/weight instead of
opacity when the underlying background isn't a fixed, known-safe value like pure white/black.

Related: [[project_dead_primary_scale]] — the bug that made this text visible again, surfacing the
opacity problem in the same pass.

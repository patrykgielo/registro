---
name: Alpine scope for multi-card state sharing
description: x-ui.button is static Blade — cannot accept Alpine bindings. When CTA and calendar are in separate cards but share state, lift x-data to a common ancestor wrapper.
type: feedback
---

When a sidebar has multiple cards that need to share Alpine state (e.g., a CTA button that reacts to calendar selection), the `x-data` wrapper must be placed on a common ancestor div that wraps ALL cards — not on one of the inner cards.

**Why:** `x-ui.button` compiles to a static Blade `<a>` or `<button>` tag with no Alpine binding support. Dynamic CTA states (`:href`, `x-text`, `x-show`, `:disabled`) require raw HTML elements styled to match the button component's utility classes, not the Blade component itself.

**How to apply:** When reactive CTA + calendar interact on the same page:
1. Place `x-data="factory()"` and `x-init="init()"` on the outermost `<div>` that wraps all affected cards (e.g., `<div class="sticky top-20">`)
2. Replace `<x-ui.button>` with a raw `<a>` or `<button>` element using the same Tailwind utility classes from `resources/views/components/ui/button.blade.php`
3. Multiple CTA states use `x-show` to swap between them — never `x-if` (causes DOM flicker)

**Relevant file:** `resources/views/services/show.blade.php` — rental sidebar pattern

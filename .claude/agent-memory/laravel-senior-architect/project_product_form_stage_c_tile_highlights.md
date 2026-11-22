---
name: product-form-stage-c-tile-highlights
description: Stage C of the ServiceResource form redesign (2026-11-22) - starred spec rows on the product tile, features moved by type, and the shared-statePath Repeater double-hydration trap
metadata:
  type: project
---

Spec row `highlight: true` = shown on the tile; un-starred row has NO key. Stripped in the MODEL (`NormalizesSpecsShape::normalizeSpecHighlights`, `saving`), not the form - the Toggle always submits `false`, and the model also covers importer/seeders/console. Cap 4 = `Service::TILE_POINTS_LIMIT`, enforced only by a `->rules([fn (): Closure => ...])` on the Repeater (disabled toggles are UI). Counter is a client-side `Text::make(js)->js()` in `Section::afterHeader()`.

**Trap worth remembering:** `features` now exists as TWO `Repeater::make('features')` (rental in "Specyfikacja", time_slot in "Tresc", mutually exclusive `visible()`). Hidden components still hydrate, and a Repeater's hydration hook wraps every stored string into an item row - so the second copy double-wrapped (`[['feature' => ['feature' => 'x']]]`) and an UNTOUCHED save wrote rows instead of strings. Fixed with a custom `afterStateHydrated` that skips `hydrateItems()` when state is already a non-list. Only a no-op-save-vs-DB test catches it; `fillForm()`-based tests pass.

**Why:** owner's hard requirement "nothing may be lost". **How to apply:** before putting one DB column behind two form components, run an unchanged save and diff the row.

Importer only creates (never updates an existing product), so a re-import cannot wipe panel stars.

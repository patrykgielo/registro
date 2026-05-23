---
paths:
  - "resources/views/**"
  - "resources/css/**"
---

# Animation Rules

## CRITICAL: Only GPU-Accelerated Properties

**ONLY animate `transform` and `opacity`** — these run at 60fps on the GPU.

**NEVER animate** (causes layout thrashing, <30fps):
`left/right/top/bottom`, `width/height`, `margin/padding`, `font-size`, `border-width`

## Timing Standards

| Type | Duration | Easing |
|------|----------|--------|
| Button/micro | 100–150ms | `ease-out` |
| Hover state | 150–200ms | `ease-out` |
| Modal open | 200–300ms | `ease-out` or spring |
| Modal close | 150–200ms | `ease-in` |
| Page transition | 300–500ms | `ease-in-out` |
| List stagger | 50ms delay each | `ease-out` |
| Skeleton loader | 1500ms loop | `linear` |

## Tailwind Patterns

```html
<!-- Hover lift -->
<div class="transition duration-200 ease-out hover:-translate-y-1 hover:shadow-xl">

<!-- Button feedback -->
<button class="transition-transform duration-150 ease-out hover:scale-102 active:scale-95">
```

## Alpine.js Transitions

```html
<!-- Fade -->
x-transition:enter="transition ease-out duration-200"
x-transition:enter-start="opacity-0"
x-transition:enter-end="opacity-100 translate-y-0"

<!-- Slide dropdown -->
x-transition:enter-start="opacity-0 -translate-y-2"
x-transition:enter-end="opacity-100 translate-y-0"

<!-- Scale modal -->
x-transition:enter-start="opacity-0 scale-95"
x-transition:enter-end="opacity-100 scale-100"
```

## Reduced Motion (ALWAYS include)

```css
@media (prefers-reduced-motion: reduce) {
  *, *::before, *::after {
    animation-duration: 0.01ms !important;
    transition-duration: 0.01ms !important;
  }
}
```

Tailwind: `<div class="animate-bounce motion-reduce:animate-none">`

## GPU Acceleration (use sparingly)

```css
.gpu { will-change: transform, opacity; }
/* Remove will-change after animation ends to free memory */
```

**Never use** `filter`, `backdrop-filter`, `clip-path` during animation — static elements only.

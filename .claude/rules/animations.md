---
paths:
  - "resources/views/**"
  - "resources/css/**"
---

# Animation Pattern Library

## Core Principle

**ONLY animate `transform` and `opacity`** - these are GPU-accelerated and run at 60fps.

---

## Micro-Interactions

### Button Click Feedback
```css
.btn {
  transition: transform 150ms ease-out, background-color 200ms ease-out;
}

.btn:hover {
  transform: scale(1.02);
}

.btn:active {
  transform: scale(0.95);  /* Instant tactile feedback */
}
```

**Tailwind:**
```html
<button class="transition-transform duration-150 ease-out hover:scale-102 active:scale-95">
```

### Card Hover Lift
```css
.card {
  transition: transform 200ms ease-out, box-shadow 200ms ease-out;
}

.card:hover {
  transform: translateY(-4px);
  box-shadow: 0 12px 24px rgba(0, 0, 0, 0.15);
}
```

**Tailwind:**
```html
<div class="transition duration-200 ease-out hover:-translate-y-1 hover:shadow-xl">
```

### Link Underline Reveal
```css
.link {
  position: relative;
}

.link::after {
  content: '';
  position: absolute;
  bottom: 0;
  left: 0;
  width: 100%;
  height: 2px;
  background: currentColor;
  transform: scaleX(0);
  transform-origin: right;
  transition: transform 200ms ease-out;
}

.link:hover::after {
  transform: scaleX(1);
  transform-origin: left;
}
```

---

## Loading States

### Skeleton Shimmer
```css
.skeleton {
  background: linear-gradient(
    90deg,
    #f0f0f0 25%,
    #e0e0e0 50%,
    #f0f0f0 75%
  );
  background-size: 200% 100%;
  animation: shimmer 1.5s infinite linear;
}

@keyframes shimmer {
  0% { background-position: 200% 0; }
  100% { background-position: -200% 0; }
}
```

**Tailwind:**
```html
<div class="animate-pulse bg-gray-200 rounded">
```

### Spinner
```css
.spinner {
  width: 24px;
  height: 24px;
  border: 3px solid #e5e7eb;
  border-top-color: #3b82f6;
  border-radius: 50%;
  animation: spin 0.8s linear infinite;
}

@keyframes spin {
  to { transform: rotate(360deg); }
}
```

**Tailwind:**
```html
<svg class="animate-spin h-5 w-5 text-primary-500" viewBox="0 0 24 24">
  <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" fill="none"/>
  <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"/>
</svg>
```

### Progress Bar
```css
.progress-bar {
  height: 4px;
  background: #e5e7eb;
  border-radius: 2px;
  overflow: hidden;
}

.progress-bar::after {
  content: '';
  display: block;
  height: 100%;
  width: var(--progress, 0%);
  background: linear-gradient(90deg, #3b82f6, #8b5cf6);
  transition: width 300ms ease-out;
}
```

---

## Enter/Exit Animations

### Fade In Up (Lists, Cards)
```css
.fade-in-up {
  animation: fadeInUp 0.3s ease-out backwards;
}

@keyframes fadeInUp {
  from {
    opacity: 0;
    transform: translateY(20px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}
```

**Stagger effect for lists:**
```css
.list-item { animation: fadeInUp 0.3s ease-out backwards; }
.list-item:nth-child(1) { animation-delay: 0ms; }
.list-item:nth-child(2) { animation-delay: 50ms; }
.list-item:nth-child(3) { animation-delay: 100ms; }
.list-item:nth-child(4) { animation-delay: 150ms; }
.list-item:nth-child(5) { animation-delay: 200ms; }
```

**Alpine.js implementation:**
```html
<template x-for="(item, index) in items" :key="item.id">
    <div
        x-show="true"
        x-transition:enter="transition ease-out duration-300"
        x-transition:enter-start="opacity-0 translate-y-4"
        x-transition:enter-end="opacity-100 translate-y-0"
        :style="{ transitionDelay: `${index * 50}ms` }">
        {{ item.name }}
    </div>
</template>
```

### Fade In Scale (Modals, Popovers)
```css
.fade-in-scale {
  animation: fadeInScale 0.2s ease-out;
}

@keyframes fadeInScale {
  from {
    opacity: 0;
    transform: scale(0.95);
  }
  to {
    opacity: 1;
    transform: scale(1);
  }
}
```

### Slide In (Sidebars, Drawers)
```css
/* From right */
.slide-in-right {
  animation: slideInRight 0.3s ease-out;
}

@keyframes slideInRight {
  from {
    opacity: 0;
    transform: translateX(100%);
  }
  to {
    opacity: 1;
    transform: translateX(0);
  }
}

/* From bottom (mobile sheets) */
.slide-in-bottom {
  animation: slideInBottom 0.3s ease-out;
}

@keyframes slideInBottom {
  from {
    transform: translateY(100%);
  }
  to {
    transform: translateY(0);
  }
}
```

---

## iOS-Style Animations

### Spring Effect
```css
.spring {
  animation: spring 0.4s cubic-bezier(0.68, -0.55, 0.265, 1.55);
}

@keyframes spring {
  0% {
    opacity: 0;
    transform: scale(0.8);
  }
  50% {
    transform: scale(1.05);
  }
  100% {
    opacity: 1;
    transform: scale(1);
  }
}
```

**Easing curve:** `cubic-bezier(0.68, -0.55, 0.265, 1.55)` - overshoots then settles

### Bounce In
```css
.bounce-in {
  animation: bounceIn 0.5s cubic-bezier(0.68, -0.55, 0.265, 1.55);
}

@keyframes bounceIn {
  0% {
    opacity: 0;
    transform: scale(0.3);
  }
  50% {
    transform: scale(1.1);
  }
  70% {
    transform: scale(0.9);
  }
  100% {
    opacity: 1;
    transform: scale(1);
  }
}
```

### iOS Tap Feedback
```css
.ios-tap {
  transition: transform 100ms ease-out, opacity 100ms ease-out;
}

.ios-tap:active {
  transform: scale(0.97);
  opacity: 0.8;
}
```

---

## Scroll-Driven Animations (Native CSS)

### Fade In On Scroll
```css
.reveal-on-scroll {
  animation: reveal linear;
  animation-timeline: view();
  animation-range: entry 0% entry 80%;
}

@keyframes reveal {
  from {
    opacity: 0;
    transform: translateY(40px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}
```

### Parallax Effect
```css
.parallax {
  animation: parallax linear;
  animation-timeline: view();
}

@keyframes parallax {
  from { transform: translateY(0); }
  to { transform: translateY(-100px); }
}
```

### Progress Bar (Page Scroll)
```css
.scroll-progress {
  position: fixed;
  top: 0;
  left: 0;
  width: 100%;
  height: 4px;
  background: linear-gradient(to right, #3b82f6, #8b5cf6);
  transform-origin: left;
  animation: scaleProgress linear;
  animation-timeline: scroll(root);
}

@keyframes scaleProgress {
  from { transform: scaleX(0); }
  to { transform: scaleX(1); }
}
```

---

## View Transitions API

### Basic Setup
```javascript
// Wrap DOM changes in startViewTransition
document.startViewTransition(() => {
    // Update DOM here
    element.classList.toggle('expanded');
});
```

### CSS Styling
```css
/* Auto-naming for list items */
.card {
  view-transition-name: match-element;
  view-transition-class: card;
}

/* Customize the transition */
::view-transition-group(*.card) {
  animation-duration: 0.4s;
  animation-timing-function: cubic-bezier(0.68, -0.55, 0.265, 1.55);
}

::view-transition-old(*.card) {
  animation: fade-out 0.2s ease-out;
}

::view-transition-new(*.card) {
  animation: fade-in 0.2s ease-in 0.1s;
}
```

---

## Success/Error Feedback

### Success Checkmark
```css
.success-check {
  stroke-dasharray: 100;
  stroke-dashoffset: 100;
  animation: drawCheck 0.5s ease-out forwards;
}

@keyframes drawCheck {
  to { stroke-dashoffset: 0; }
}
```

```html
<svg class="w-6 h-6 text-success" viewBox="0 0 24 24" fill="none" stroke="currentColor">
  <path class="success-check" stroke-width="2" d="M5 13l4 4L19 7"/>
</svg>
```

### Shake (Error)
```css
.shake {
  animation: shake 0.4s ease-out;
}

@keyframes shake {
  0%, 100% { transform: translateX(0); }
  20%, 60% { transform: translateX(-8px); }
  40%, 80% { transform: translateX(8px); }
}
```

### Pulse (Attention)
```css
.pulse-attention {
  animation: pulseAttention 2s ease-in-out infinite;
}

@keyframes pulseAttention {
  0%, 100% { transform: scale(1); opacity: 1; }
  50% { transform: scale(1.05); opacity: 0.8; }
}
```

---

## Reduced Motion Support

**ALWAYS include this in your CSS:**

```css
@media (prefers-reduced-motion: reduce) {
  *,
  *::before,
  *::after {
    animation-duration: 0.01ms !important;
    animation-iteration-count: 1 !important;
    transition-duration: 0.01ms !important;
    scroll-behavior: auto !important;
  }
}
```

**Tailwind variant:**
```html
<div class="animate-bounce motion-reduce:animate-none">
<div class="transition-transform motion-reduce:transition-none">
```

---

## Alpine.js Transitions

### Fade
```html
<div
    x-show="open"
    x-transition:enter="transition ease-out duration-200"
    x-transition:enter-start="opacity-0"
    x-transition:enter-end="opacity-100"
    x-transition:leave="transition ease-in duration-150"
    x-transition:leave-start="opacity-100"
    x-transition:leave-end="opacity-0">
```

### Scale + Fade (Modal)
```html
<div
    x-show="open"
    x-transition:enter="transition ease-out duration-200"
    x-transition:enter-start="opacity-0 scale-95"
    x-transition:enter-end="opacity-100 scale-100"
    x-transition:leave="transition ease-in duration-150"
    x-transition:leave-start="opacity-100 scale-100"
    x-transition:leave-end="opacity-0 scale-95">
```

### Slide (Dropdown)
```html
<div
    x-show="open"
    x-transition:enter="transition ease-out duration-200"
    x-transition:enter-start="opacity-0 -translate-y-2"
    x-transition:enter-end="opacity-100 translate-y-0"
    x-transition:leave="transition ease-in duration-150"
    x-transition:leave-start="opacity-100 translate-y-0"
    x-transition:leave-end="opacity-0 -translate-y-2">
```

---

## GPU Acceleration Tips

### Force GPU Layer (Use Sparingly)
```css
.gpu-accelerated {
  will-change: transform, opacity;
  /* Remove after animation ends to save memory */
}

/* Alternative: 3D transform hack */
.gpu-layer {
  transform: translateZ(0);
}
```

### Avoid During Animation
```css
/* These are expensive during animation */
filter: blur(), drop-shadow()
clip-path: (complex paths)
mask-image:
backdrop-filter:
```

Use `filter` and `backdrop-filter` for static elements only, or apply before/after animation.

---

## Timing Cheat Sheet

| Context | Duration | Easing |
|---------|----------|--------|
| Button click | 100-150ms | `ease-out` |
| Hover state | 150-200ms | `ease-out` |
| Modal open | 200-300ms | `ease-out` or spring |
| Modal close | 150-200ms | `ease-in` |
| Page transition | 300-500ms | `ease-in-out` |
| List item stagger | 50ms delay each | `ease-out` |
| Skeleton loader | 1500ms loop | `linear` |
| Success feedback | 400-500ms | spring |
| Error shake | 400ms | `ease-out` |

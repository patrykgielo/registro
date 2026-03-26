---
paths:
  - "resources/views/**"
  - "resources/css/**"
  - "resources/js/**"
---

# Frontend Code Quality Rules

## CRITICAL: Rebuild Assets After EVERY Frontend Change

**Po każdej zmianie w `resources/views/**`, `resources/css/**`, `resources/js/**` — OBOWIĄZKOWO:**

```bash
docker compose exec -T app npm run build
```

**Zasady:**
- ZAWSZE `npm run build` po modyfikacji Blade/CSS/JS — bez wyjątków
- NIGDY nie zostawiaj zmian bez przebudowania assetów
- `npm run dev` (HMR server) = TYLKO aktywne pisanie z live-reload, NIGDY jako "gotowe"
- Jeśli assety nie są przebudowane → użytkownik widzi stare style → "brak zmian w UI"

**Incident 2026-03-26:** Zmiany frontendowe commitowane bez `npm run build` → użytkownik widzi stare style.
**Incident 2026-03-26b:** `public/hot` plik pozostał po `npm run dev` → `@vite` directive serwowało dev URL zamiast build assets → brak stylów na subdomenie. Fix: `rm public/hot`.

---

## CRITICAL: Animation Performance

### MUST ONLY Animate These Properties (GPU-accelerated, 60fps):
```css
transform: translate(), rotate(), scale()
opacity: 0-1
```

### NEVER Animate (Causes Layout Thrashing, <30fps):
```css
/* These trigger expensive reflows */
left, right, top, bottom
width, height
margin, padding
font-size
border-width
```

### Timing Standards:
| Animation Type | Duration | Easing |
|----------------|----------|--------|
| Micro-interactions | 150-200ms | `ease-out` |
| State changes | 200-300ms | `ease-out` |
| Page transitions | 300-500ms | `ease-in-out` |
| Skeleton loaders | 1000-1500ms | `linear` (loop) |

### Code Pattern:
```css
.interactive-element {
  transition: transform 200ms ease-out, opacity 200ms ease-out;
}

.interactive-element:hover {
  transform: scale(1.02);
}

.interactive-element:active {
  transform: scale(0.95);  /* Tactile feedback */
}
```

---

## CRITICAL: Tailwind max-width Classes

**GOTCHA:** `max-w-*` classes have DIFFERENT values than screen breakpoints!

| Class | Actual Width | Screen Breakpoint |
|-------|-------------|-------------------|
| `max-w-sm` | 384px (24rem) | `sm:` = 640px |
| `max-w-md` | 448px (28rem) | `md:` = 768px |
| `max-w-lg` | 512px (32rem) | `lg:` = 1024px |
| `max-w-xl` | 576px (36rem) | `xl:` = 1280px |
| `max-w-2xl` | 672px (42rem) | `2xl:` = 1536px |
| `max-w-7xl` | 1280px (80rem) | - |

**Use `max-w-screen-*` for screen-width matching:**
```html
<!-- Container matching screen breakpoints -->
<div class="max-w-screen-xl mx-auto">  <!-- 1280px -->
<div class="max-w-screen-lg mx-auto">  <!-- 1024px -->
<div class="max-w-screen-2xl mx-auto"> <!-- 1536px -->

<!-- For custom widths, use arbitrary values -->
<div class="max-w-[1920px] mx-auto">   <!-- Custom 1920px -->
```

**Incident 2026-01-21:** CMS block container_max_width=xl rendered as 576px instead of 1280px because `max-w-xl` was used instead of `max-w-screen-xl`.

---

## CRITICAL: Tailwind vs CSS in Inline Styles

**GOTCHA:** Tailwind classes ≠ CSS values! When using form values in inline `style=""` attributes, you MUST convert Tailwind format to CSS format.

### Gradient Directions:
| Tailwind Class | CSS linear-gradient() |
|----------------|----------------------|
| `to-r` | `to right` |
| `to-l` | `to left` |
| `to-t` | `to top` |
| `to-b` | `to bottom` |
| `to-br` | `to bottom right` |
| `to-bl` | `to bottom left` |
| `to-tr` | `to top right` |
| `to-tl` | `to top left` |

### Example - WRONG vs RIGHT:
```php
// ❌ WRONG - Tailwind value in CSS
$direction = 'to-r';  // From form
style="background: linear-gradient({$direction}, #000, #fff);"
// Renders: linear-gradient(to-r, ...) - INVALID CSS!

// ✅ RIGHT - Map to CSS first
$cssDirection = match($direction) {
    'to-r' => 'to right',
    'to-l' => 'to left',
    // ... etc
};
style="background: linear-gradient({$cssDirection}, #000, #fff);"
// Renders: linear-gradient(to right, ...) - VALID CSS!
```

**Incident 2026-01-21:** CMS gradients broken because Tailwind `to-r` was used directly in CSS `linear-gradient()` instead of converting to `to right`.

---

## CRITICAL: Dynamic Tailwind Classes Need Safelist

**GOTCHA:** Classes generated dynamically in PHP `match()` or concatenation may NOT be included in the final CSS because Tailwind JIT scans for static class strings.

### Problem:
```php
// Tailwind may NOT detect these classes during build
$classes = match($size) {
    'md' => 'p-8 md:p-12',
    'lg' => 'p-10 md:p-16',
    'xl' => 'p-12 md:p-20',
};
```

### Solution: Add to safelist in `tailwind.config.js`:
```js
export default {
  safelist: [
    'p-8', 'p-10', 'p-12',
    'md:p-12', 'md:p-16', 'md:p-20',
    'rounded-lg', 'rounded-xl', 'rounded-2xl', 'rounded-3xl',
  ],
  // ...
}
```

### When to Safelist:
- Classes in PHP `match()` or `switch` statements
- Classes built via string concatenation
- Classes in database/CMS content
- Any class not written as a complete literal string in templates

**Incident 2026-01-21:** CTA container padding not working because dynamic classes `p-10 md:p-16` weren't detected by Tailwind JIT.

---

## Tailwind CSS 4.0 Features (USE THESE!)

### Container Queries (No Plugin Needed):
```html
<!-- Component responds to its container, not viewport -->
<div class="@container">
  <div class="grid grid-cols-1 @sm:grid-cols-2 @lg:grid-cols-4">
    <!-- Adapts based on container width -->
  </div>
</div>

<!-- With max breakpoints -->
<div class="@container">
  <div class="flex @max-md:flex-col">
</div>
```

**Rule:** Use container queries for components, media queries for page layout.

### Dynamic Values (No Arbitrary Syntax):
```html
<!-- Any number works natively -->
<div class="grid-cols-15 mt-29 w-17 gap-7">
```

### @starting-style (Enter/Exit Animations):
```html
<!-- Animates from hidden to visible state -->
<div popover class="transition-discrete starting:open:opacity-0 starting:open:scale-95">
```

### 3D Transforms:
```html
<div class="perspective-distant">
  <div class="rotate-x-45 rotate-z-30 transform-3d backface-hidden">
</div>
```

### Expanded Gradients:
```html
<!-- Conic gradients -->
<div class="bg-conic from-blue-500 via-purple-500 to-pink-500">

<!-- Radial with position -->
<div class="bg-radial-[at_25%_25%] from-white to-zinc-900">

<!-- Linear with angle -->
<div class="bg-linear-45 from-indigo-500 to-purple-500">
```

---

## Accessibility Requirements (WCAG 2.2 AA)

### Mandatory Checklist:
1. **Semantic HTML** - Use `<button>` not `<div onclick>`
2. **ARIA Labels** - Every interactive element needs accessible name
3. **Keyboard Support** - Tab navigation, Enter/Space for buttons
4. **Focus Styles** - `:focus-visible` with visible outline
5. **Touch Targets** - Minimum 44x44px (iOS standard)
6. **Loading States** - `aria-busy="true"` and `aria-live="polite"`
7. **Alt Text** - Descriptive for content, empty for decorative
8. **Color Contrast** - 4.5:1 minimum for text
9. **Reduced Motion** - Always include `prefers-reduced-motion`

### Code Templates:

**Interactive Button:**
```html
<button
    type="button"
    class="min-h-11 min-w-11 px-4 py-2 rounded-lg
           focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-500
           transition-transform duration-200 ease-out
           active:scale-95"
    aria-label="Close dialog">
    <span aria-hidden="true">&times;</span>
</button>
```

**Form Input with Validation:**
```html
<div>
    <label for="email" class="block text-sm font-medium">
        Email <span class="text-error" aria-hidden="true">*</span>
    </label>
    <input
        type="email"
        id="email"
        name="email"
        required
        aria-required="true"
        aria-invalid="{{ $errors->has('email') ? 'true' : 'false' }}"
        aria-describedby="email-error email-hint"
        class="w-full min-h-11 px-4 py-2 border rounded-lg
               focus-visible:outline-2 focus-visible:outline-primary-500
               aria-invalid:border-error aria-invalid:bg-error/5">
    <p id="email-hint" class="text-sm text-gray-500">We'll never share your email.</p>
    @error('email')
        <p id="email-error" role="alert" class="text-sm text-error">{{ $message }}</p>
    @enderror
</div>
```

**Loading Button:**
```html
<button
    type="submit"
    class="min-h-11 px-6 py-2 rounded-lg"
    :aria-busy="loading"
    :disabled="loading"
    aria-live="polite">
    <span x-show="!loading">Submit</span>
    <span x-show="loading" x-cloak class="flex items-center gap-2">
        <svg class="animate-spin h-4 w-4" aria-hidden="true">...</svg>
        Loading...
    </span>
</button>
```

**Skip Link (Always at top of page):**
```html
<a href="#main-content"
   class="sr-only focus:not-sr-only focus:absolute focus:top-4 focus:left-4
          focus:z-50 focus:px-4 focus:py-2 focus:bg-white focus:rounded-lg focus:shadow-lg">
    Skip to main content
</a>
```

---

## Reduced Motion Support (ALWAYS INCLUDE)

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
```

---

## Component Architecture

### Prefer Compound Components:
```blade
{{-- Good: Flexible, composable --}}
<x-tabs.wrapper default="0">
    <x-tabs.list>
        <x-tabs.trigger :index="0">Profile</x-tabs.trigger>
        <x-tabs.trigger :index="1">Settings</x-tabs.trigger>
    </x-tabs.list>
    <x-tabs.content :index="0">Profile content</x-tabs.content>
    <x-tabs.content :index="1">Settings content</x-tabs.content>
</x-tabs.wrapper>

{{-- Avoid: Prop-heavy, rigid --}}
<x-tabs
    :tabs="[['label' => 'Profile', 'content' => '...'], ...]"
    default="0" />
```

### Use Headless Logic:
```blade
{{-- Separate state from presentation --}}
<div x-data="{
    open: false,
    toggle() { this.open = !this.open },
    close() { this.open = false }
}"
@keydown.escape.window="close">
    <button @click="toggle" :aria-expanded="open">Menu</button>
    <div x-show="open" x-transition>
        {{ $slot }}
    </div>
</div>
```

---

## CSS Organization with @layer

```css
@layer base {
  /* Reset, defaults, typography */
  body {
    @apply font-sans text-gray-900 antialiased;
  }

  h1, h2, h3, h4 {
    @apply font-bold tracking-tight;
  }
}

@layer components {
  /* Reusable component classes */
  .btn {
    @apply min-h-11 px-4 py-2 rounded-lg font-medium
           transition-transform duration-200 ease-out
           focus-visible:outline-2 focus-visible:outline-offset-2
           active:scale-95;
  }

  .card {
    @apply bg-white rounded-xl shadow-sm border border-gray-100;
  }
}

@layer utilities {
  /* Custom utilities */
  .scrollbar-hide {
    -ms-overflow-style: none;
    scrollbar-width: none;
  }

  .scrollbar-hide::-webkit-scrollbar {
    display: none;
  }
}
```

---

## Performance Rules

### Critical CSS:
- Inline above-the-fold styles
- Defer non-critical CSS with `media="print"` trick

### Images:
```html
<!-- Below fold: lazy load -->
<img src="product.jpg" loading="lazy" decoding="async" alt="Product">

<!-- Above fold: eager load with dimensions -->
<img src="hero.jpg" loading="eager" width="1200" height="600" alt="Hero">

<!-- Modern formats with fallback -->
<picture>
    <source srcset="image.avif" type="image/avif">
    <source srcset="image.webp" type="image/webp">
    <img src="image.jpg" alt="Description">
</picture>
```

### Long Lists:
```css
/* Defer rendering of offscreen content */
.list-item {
  content-visibility: auto;
  contain-intrinsic-size: 0 80px;  /* Estimate height */
}
```

### Resource Hints:
```html
<link rel="preload" href="/fonts/inter.woff2" as="font" crossorigin>
<link rel="preconnect" href="https://api.example.com">
<link rel="dns-prefetch" href="https://cdn.example.com">
```

---

## Prompt Strategy for AI

### Keep prompts under 50 words when possible.
Research shows >150 word prompts increase error rate by 64%.

### Good prompt structure:
```
"Create a [component type] with [specific features].
Use transform/opacity animations only.
Include ARIA labels and 44px touch targets."
```

### If stuck after 3 attempts → STOP
Reformulate the entire approach instead of repeating.

---

## Verification Checklist

Before submitting frontend code, verify:

- [ ] Animations use ONLY transform/opacity
- [ ] Touch targets >= 44x44px
- [ ] ARIA labels on interactive elements
- [ ] Keyboard navigation works (Tab, Enter, Space, Escape)
- [ ] `:focus-visible` styles present
- [ ] `prefers-reduced-motion` supported
- [ ] Images have alt text (empty for decorative)
- [ ] Color contrast >= 4.5:1
- [ ] Loading states have `aria-busy`
- [ ] Forms have proper `aria-invalid` and `aria-describedby`

---

## CRITICAL: Alpine.js Form Validation Pattern

### Problem: HTML5 vs JavaScript Validation
Browser native validation (HTML5 `required`) runs BEFORE JavaScript → always shows generic browser tooltip first.

### Solution: Use `novalidate` + Alpine.js Validation

**Form tag MUST have `novalidate`:**
```html
<form id="my-form" novalidate x-data="myFormData()">
```

### Standard Validation Pattern (from Booking Wizard):

```javascript
function myFormData() {
    return {
        // Field values
        firstName: '',
        email: '',

        // Validation state
        validFields: {
            firstName: false,
            email: false,
        },
        errors: {},

        // Validate single field on blur
        validateField(fieldName) {
            delete this.errors[fieldName];
            this.validFields[fieldName] = false;

            switch (fieldName) {
                case 'firstName':
                    if (!this.firstName?.trim()) {
                        this.errors[fieldName] = 'Podaj imię.';
                    } else if (this.firstName.trim().length < 2) {
                        this.errors[fieldName] = 'Imię musi mieć co najmniej 2 znaki.';
                    } else {
                        this.validFields[fieldName] = true;
                    }
                    break;

                case 'email':
                    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                    if (!this.email?.trim()) {
                        this.errors[fieldName] = 'Podaj adres e-mail.';
                    } else if (!emailRegex.test(this.email)) {
                        this.errors[fieldName] = 'Podaj prawidłowy adres e-mail.';
                    } else {
                        this.validFields[fieldName] = true;
                    }
                    break;
            }
        },

        // Validate all fields (called by layout before submission)
        triggerFullValidation() {
            this.validateField('firstName');
            this.validateField('email');

            if (Object.keys(this.errors).length > 0) {
                this.$el.dataset.validationFailed = 'true';
                this.scrollToFirstError();
            } else {
                this.$el.dataset.validationFailed = '';
            }
        },

        // Scroll to and focus first error field
        scrollToFirstError() {
            this.$nextTick(() => {
                const fieldOrder = [
                    { check: this.errors.firstName, selector: '#first-name' },
                    { check: this.errors.email, selector: '#email' },
                ];

                for (const field of fieldOrder) {
                    if (field.check) {
                        const el = document.querySelector(field.selector);
                        if (el) {
                            el.scrollIntoView({ behavior: 'smooth', block: 'center' });
                            el.focus();
                            break;
                        }
                    }
                }
            });
        }
    }
}
```

### Input HTML Pattern:
```html
<input
    type="text"
    id="first-name"
    name="first_name"
    x-model="firstName"
    @blur="validateField('firstName')"
    :class="{
        'border-green-400': validFields.firstName,
        'border-red-400': errors.firstName,
        'border-gray-300': !validFields.firstName && !errors.firstName
    }"
    class="w-full px-4 py-3 border-2 rounded-xl"
>
<p x-show="errors.firstName" x-text="errors.firstName" class="mt-2 text-sm text-red-600"></p>
```

### Layout Integration (Custom Event):
```javascript
// In layout.blade.php - before AJAX submission
if (currentStep === 4) {
    window.dispatchEvent(new CustomEvent('validate-step4'));

    if (wizardForm.dataset.validationFailed === 'true') {
        wizardForm.dataset.validationFailed = '';
        return; // Block submission
    }
}

// In form view - listen for event
<form @validate-step4.window="triggerFullValidation()">
```

### Server-Side: Custom Messages in Controller
```php
$validated = $request->validate([
    'first_name' => 'required|string|min:2|max:100',
    'email' => 'required|email|max:255',
], [
    'first_name.required' => 'Podaj imię.',
    'first_name.min' => 'Imię musi mieć co najmniej 2 znaki.',
    'email.required' => 'Podaj adres e-mail.',
    'email.email' => 'Podaj prawidłowy adres e-mail.',
]);
```

**Incident 2026-02-04:** Booking wizard showed `validation.required_if` raw keys because:
1. No `lang/pl/validation.php` file
2. No custom messages in controller

**Solution:** Always use custom messages as second argument to `$request->validate()`

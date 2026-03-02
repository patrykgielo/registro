# iOS Component System

Complete iOS-style design system for authentication pages and UI components with DaisyUI foundation.

**Version:** v0.6.2
**Status:** ✅ Production-Ready
**Last Updated:** 2025-12-10
**Quality Standard:** Premium iOS-style design (matches homepage/service pages quality)

---

## Overview

The iOS Component System provides reusable Blade components with iOS-inspired aesthetics for creating consistent, high-quality user interfaces. All components follow Apple's Human Interface Guidelines for touch targets, animations, and visual design.

**Key Features:**
- ✅ iOS-style spring animations (`cubic-bezier(0.36, 0.66, 0.04, 1)`)
- ✅ Glassmorphism effects (backdrop-blur, translucency)
- ✅ Animated gradient orbs (iOS 17 style)
- ✅ Touch-friendly targets (≥44x44px minimum)
- ✅ Mobile-first responsive design
- ✅ WCAG AA accessibility compliance
- ✅ Alpine.js integration for interactivity
- ✅ Heroicons integration
- ✅ Reduced motion support

---

## Available Components

### 1. ios-auth-card

Full-page authentication layout with gradient background, glassmorphic card, and animated orbs.

**Location:** `resources/views/components/ios/auth-card.blade.php`

**Props:**
```php
@props([
    'title' => '',              // Card title (required)
    'subtitle' => null,         // Optional subtitle
    'gradient' => 'from-blue-600 via-purple-600 to-indigo-700', // Tailwind gradient
    'showLogo' => true,         // Show logo section
])
```

**Usage:**
```blade
<x-ios.auth-card
    title="Witaj ponownie"
    subtitle="Zaloguj się do swojego konta"
    gradient="from-blue-600 via-purple-600 to-indigo-700"
>
    {{-- Form content here --}}
</x-ios.auth-card>
```

**Features:**
- Noise texture overlay (App Store style, 5% opacity)
- 3 animated gradient orbs (pink, purple, blue)
- Glassmorphic card (bg-white/95, backdrop-blur-xl)
- Fade-in-up animations with staggered delays (0ms, 200ms, 400ms, 600ms)
- Responsive logo section
- Optional footer slot

**Gradient Themes:**
| Theme | Gradient | Use Case |
|-------|----------|----------|
| Primary (Login) | `from-blue-600 via-purple-600 to-indigo-700` | Default auth |
| Success (Register) | `from-green-600 via-teal-600 to-blue-600` | Sign up |
| Help (Forgot Password) | `from-indigo-600 via-blue-600 to-sky-600` | Password recovery |
| Secure (Reset) | `from-purple-600 via-indigo-600 to-blue-700` | Sensitive actions |
| Warning (Confirm) | `from-yellow-600 via-orange-600 to-red-600` | Confirmation required |
| New Start (Setup) | `from-green-600 via-emerald-600 to-teal-600` | First-time setup |
| Error (Expired) | `from-red-600 via-orange-600 to-yellow-600` | Error states |
| Trust (Verify) | `from-teal-600 via-cyan-600 to-blue-600` | Email verification |

---

### 2. ios-button

Reusable button component with iOS-style animations and multiple variants.

**Location:** `resources/views/components/ios/button.blade.php`

**Props:**
```php
@props([
    'variant' => 'primary',      // primary|secondary|ghost|danger
    'type' => 'button',          // submit|button|reset
    'label' => '',               // Button text (or use slot)
    'icon' => null,              // Heroicon name (e.g., 'arrow-right')
    'iconPosition' => 'left',    // left|right
    'loading' => false,          // Show spinner state
    'fullWidth' => false,        // Apply w-full
    'href' => null,              // Convert to <a> tag
    'disabled' => false,         // Disabled state
])
```

**Usage:**
```blade
{{-- Primary submit button --}}
<x-ios.button
    type="submit"
    variant="primary"
    label="Wyślij link resetujący"
    icon="paper-airplane"
    iconPosition="right"
    fullWidth
/>

{{-- Secondary link button --}}
<x-ios.button
    variant="secondary"
    href="{{ route('login') }}"
    label="Powrót do logowania"
    icon="arrow-left"
    iconPosition="left"
/>

{{-- Ghost text button --}}
<x-ios.button
    variant="ghost"
    href="{{ route('password.request') }}"
    label="Zapomniałeś hasła?"
/>

{{-- Loading state --}}
<x-ios.button
    type="submit"
    variant="primary"
    label="Wysyłanie..."
    :loading="true"
/>
```

**Variants:**
- **primary**: Blue-purple gradient, white text, shadow-lg
- **secondary**: Gray background, dark text
- **ghost**: Transparent, blue text, underline on hover
- **danger**: Red-pink gradient, white text, shadow-lg

**Features:**
- Touch target: `min-h-[44px]` (iOS HIG compliance)
- iOS spring animation: `cubic-bezier(0.36, 0.66, 0.04, 1)`
- Active state: `scale-95`
- Hover state: `scale-[1.02]`, `shadow-xl`
- Loading spinner with opacity-50
- Heroicon integration (auto-spacing with label)
- Converts to `<a>` tag if `href` provided
- Disabled state styling

---

### 3. ios-alert

Flash message component with 4 types, optional title, and dismissible functionality.

**Location:** `resources/views/components/ios/alert.blade.php`

**Props:**
```php
@props([
    'type' => 'info',           // success|error|warning|info
    'message' => '',            // Alert text (or use slot)
    'title' => null,            // Optional bold heading
    'dismissible' => false,     // Show X close button
    'icon' => true,             // Show Heroicon icon
])
```

**Usage:**
```blade
{{-- Success alert (session flash) --}}
@if (session('status'))
    <x-ios.alert
        type="success"
        :message="session('status')"
        dismissible
        class="mb-6"
    />
@endif

{{-- Error alert with title --}}
@error('token')
    <x-ios.alert
        type="error"
        title="Błąd"
        :message="$message"
        dismissible
        class="mb-6"
    />
@enderror

{{-- Warning alert with slot content --}}
<x-ios.alert
    type="warning"
    class="mb-6"
>
    <p class="mb-3">
        Link, którego użyłeś, wygasł lub jest nieprawidłowy.
    </p>
    <hr class="my-3 border-orange-200">
    <p class="mb-0">
        <strong>Co teraz?</strong><br>
        Skontaktuj się z administratorem.
    </p>
</x-ios.alert>

{{-- Info alert (static) --}}
<x-ios.alert
    type="info"
    message="Administrator utworzył dla Ciebie konto. Aby się zalogować, ustaw swoje hasło."
    class="mb-6"
/>
```

**Types:**
| Type | Background | Border | Icon | Use Case |
|------|------------|--------|------|----------|
| **success** | `bg-green-50` | `border-green-500` | `check-circle` | Success messages |
| **error** | `bg-red-50` | `border-red-500` | `x-circle` | Error messages |
| **warning** | `bg-orange-50` | `border-orange-500` | `exclamation-triangle` | Warnings |
| **info** | `bg-blue-50` | `border-blue-500` | `information-circle` | Informational |

**Features:**
- Border-left-4 iOS pattern
- Heroicon integration (w-5 h-5)
- Optional title + message
- Alpine.js dismiss button (`x-data`, `x-show`, `x-transition`)
- Slide-down animation (300ms enter, 200ms leave)
- iOS spring timing function
- ARIA `role="alert"` for accessibility

---

### 4. ios-input

Text/email/password input component with validation, icons, and password toggle.

**Location:** `resources/views/components/ios/input.blade.php`

**Props:**
```php
@props([
    'type' => 'text',           // text|email|password|tel|number
    'name' => '',               // Input name (required)
    'label' => '',              // Label text
    'placeholder' => '',        // Placeholder text
    'value' => '',              // Initial value
    'icon' => null,             // Heroicon name (e.g., 'envelope')
    'helpText' => null,         // Help text below input
    'required' => false,        // Required attribute
    'disabled' => false,        // Disabled attribute
    'readonly' => false,        // Readonly attribute
    'autofocus' => false,       // Autofocus attribute
    'autocomplete' => null,     // Autocomplete value
])
```

**Usage:**
```blade
{{-- Email input --}}
<x-ios.input
    type="email"
    name="email"
    label="Adres e-mail"
    placeholder="twoj@email.pl"
    :value="old('email')"
    icon="envelope"
    helpText="Wprowadź adres e-mail powiązany z Twoim kontem"
    required
    autofocus
    autocomplete="email"
/>

{{-- Password input with toggle --}}
<x-ios.input
    type="password"
    name="password"
    label="Nowe hasło"
    placeholder="Minimum 8 znaków"
    icon="lock-closed"
    helpText="Minimum 8 znaków"
    required
    autocomplete="new-password"
/>

{{-- Disabled email (pre-filled) --}}
<x-ios.input
    type="email"
    name="email"
    label="Adres e-mail"
    :value="$email"
    icon="envelope"
    helpText="Twój adres e-mail"
    disabled
    readonly
/>
```

**Features:**
- Heroicon integration (left-aligned icon)
- Password show/hide toggle button (eye icon)
- Validation error display (red border + error message)
- Label with required asterisk
- Help text (gray, sm text)
- iOS-style focus ring
- 16px font size (prevents iOS zoom on focus)
- `-webkit-tap-highlight-color: transparent`

---

### 5. ios-checkbox

Checkbox/toggle component with iOS-style animations.

**Location:** `resources/views/components/ios/checkbox.blade.php`

**Props:**
```php
@props([
    'name' => '',               // Checkbox name (required)
    'label' => '',              // Label text
    'checked' => false,         // Initial checked state
    'style' => 'checkbox',      // checkbox|toggle
])
```

**Usage:**
```blade
{{-- Standard checkbox --}}
<x-ios.checkbox
    name="remember"
    label="Zapamiętaj mnie"
    style="checkbox"
    :checked="old('remember')"
/>

{{-- iOS toggle switch --}}
<x-ios.checkbox
    name="notifications"
    label="Włącz powiadomienia"
    style="toggle"
/>
```

**Features:**
- iOS spring animation on toggle
- Touch target: ≥44x44px
- Custom checkbox/toggle styling
- Label integration
- Checked state management

---

## Animation System

All iOS components use a consistent animation system defined in `resources/css/app.css`.

### Spring Animation (Core Timing)

```css
.ios-spring {
    transition-timing-function: cubic-bezier(0.36, 0.66, 0.04, 1);
}
```

**Used in:**
- Button hover/active states
- Input focus transitions
- Checkbox/toggle animations
- Alert slide-in/out

### Fade-in-up Animation

```css
@keyframes fadeInUp {
    from {
        opacity: 0;
        transform: translateY(30px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.animate-fade-in-up {
    animation: fadeInUp 0.8s cubic-bezier(0.36, 0.66, 0.04, 1) both;
}
```

**Staggered Delays:**
- `.animation-delay-200` - 0.2s
- `.animation-delay-400` - 0.4s
- `.animation-delay-600` - 0.6s

**Used in:** auth-card logo, title, form container

### Blob Animation (Gradient Orbs)

```css
@keyframes blob {
    0%, 100% {
        transform: translate(0px, 0px) scale(1);
    }
    33% {
        transform: translate(30px, -50px) scale(1.1);
    }
    66% {
        transform: translate(-20px, 20px) scale(0.9);
    }
}

.animate-blob {
    animation: blob 10s infinite;
}
```

**Delays:**
- `.animation-delay-2000` - 2s (blob 2)
- `.animation-delay-4000` - 4s (blob 3)

**Used in:** auth-card background orbs

---

## Accessibility (WCAG AA)

All components are designed for accessibility:

### ✅ Keyboard Navigation
- All buttons, inputs, links keyboard accessible
- Proper focus management (autofocus on primary input)
- Visible focus indicators (ring-2, ring-offset-2)

### ✅ Screen Readers
- Semantic HTML (`<form>`, `<button>`, `<label>`)
- ARIA attributes (`role="alert"` on alerts)
- Descriptive labels and help text

### ✅ Color Contrast
- White text on dark gradients (high contrast)
- Error states: red border + red text (sufficient contrast)
- Alert backgrounds: 50 opacity (sufficient contrast)

### ✅ Touch Targets
- All interactive elements ≥44x44px (iOS HIG minimum)
- `min-h-[44px]` on buttons
- Proper padding on inputs

### ✅ Reduced Motion
```css
@media (prefers-reduced-motion: reduce) {
    .animate-fade-in-up,
    .animate-blob,
    .ios-spring {
        animation: none !important;
        transition: none !important;
        transform: none !important;
        opacity: 1 !important;
    }
}
```

**Respects user preference:** All animations disabled when user enables "Reduce Motion" in OS settings.

---

## Implemented Pages

### Authentication Pages (All Redesigned in v0.6.2)

| Page | File | Gradient Theme | Status |
|------|------|----------------|--------|
| **Login** | `auth/login.blade.php` | Blue-Purple-Indigo | ✅ iOS-styled |
| **Register** | `auth/register.blade.php` | Green-Teal-Blue | ✅ iOS-styled |
| **Forgot Password** | `auth/passwords/email.blade.php` | Indigo-Blue-Sky | ✅ Redesigned v0.6.2 |
| **Reset Password** | `auth/passwords/reset.blade.php` | Purple-Indigo-Blue | ✅ Redesigned v0.6.2 |
| **Confirm Password** | `auth/passwords/confirm.blade.php` | Yellow-Orange-Red | ✅ Redesigned v0.6.2 |
| **Password Setup** | `auth/passwords/setup.blade.php` | Green-Emerald-Teal | ✅ Redesigned v0.6.2 |
| **Token Expired** | `auth/passwords/token-expired.blade.php` | Red-Orange-Yellow | ✅ Redesigned v0.6.2 |
| **Email Verification** | `auth/verify.blade.php` | Teal-Cyan-Blue | ✅ Redesigned v0.6.2 |

**Translation:** All pages use natural Polish translations (EN → PL in v0.6.2).

---

## Integration with Existing Systems

### Livewire Compatibility

All components are Livewire-compatible:

```blade
{{-- wire:model support --}}
<x-ios.input
    type="email"
    name="email"
    label="Email"
    wire:model="email"
/>

{{-- wire:click on buttons --}}
<x-ios.button
    variant="primary"
    label="Submit"
    wire:click="submit"
/>

{{-- wire:loading state --}}
<x-ios.button
    type="submit"
    variant="primary"
    label="Processing..."
    :loading="$isSubmitting"
/>
```

### Form Validation

ios-input component automatically displays Laravel validation errors:

```blade
<x-ios.input
    type="email"
    name="email"
    label="Email"
    :value="old('email')"
/>

{{-- Validation error displayed automatically:
     Red border + error message below input --}}
```

**Backend:**
```php
$request->validate([
    'email' => 'required|email',
    'password' => 'required|min:8',
]);
```

---

## Component Development Guidelines

### When to Create a New Component

✅ **Create** if:
- Element is reused across 3+ pages
- Complex styling/behavior (icons, animations, states)
- Needs props API for customization

❌ **Don't create** if:
- One-time use (inline Tailwind is fine)
- Simple wrapper (unnecessary abstraction)

### Component Naming Convention

**Format:** `ios-{component-name}.blade.php`

**Examples:**
- `ios-button.blade.php` - Button component
- `ios-input.blade.php` - Input component
- `ios-alert.blade.php` - Alert component
- `ios-auth-card.blade.php` - Auth layout card

### Props API Design

**Required props** (no default):
```php
'name' => '',               // Input name
'label' => '',              // Component label
```

**Optional props** (with sensible defaults):
```php
'variant' => 'primary',     // Default variant
'icon' => null,             // Optional icon
'disabled' => false,        // Default enabled
```

**Boolean props** (default false):
```php
'required' => false,
'autofocus' => false,
'dismissible' => false,
```

### Testing Components

**Manual testing checklist:**
- ✅ Desktop (Chrome, Firefox, Safari, Edge)
- ✅ Mobile (iOS Safari 14+, Chrome Android)
- ✅ Keyboard navigation (Tab, Enter, Space)
- ✅ Screen reader (VoiceOver, NVDA)
- ✅ Validation states (error, success)
- ✅ Loading states (if applicable)
- ✅ Disabled states
- ✅ Responsive breakpoints (375px, 768px, 1024px)
- ✅ Reduced motion preference

---

## Future Enhancements

**Planned components (not yet implemented):**
- `ios-modal` - Modal dialog with backdrop
- `ios-dropdown` - Dropdown menu with animations
- `ios-card` - Content card with elevation
- `ios-badge` - Status badge component
- `ios-toast` - Toast notification system
- `ios-tabs` - Tab navigation component

**Planned improvements:**
- Design system tokens in `design-system.json`
- Storybook documentation for components
- PHPUnit tests for component rendering
- Dark mode support

---

## References

**Design Research:**
- [Apple Human Interface Guidelines](https://developer.apple.com/design/human-interface-guidelines/ios)
- [iOS 17 Design Language](https://www.apple.com/ios/ios-17/)
- [DaisyUI Components](https://daisyui.com/components/)

**Project Files:**
- Components: `resources/views/components/ios/`
- Animations: `resources/css/app.css` (lines 14-227)
- Tailwind config: `tailwind.config.js`

**Related Documentation:**
- [Frontend UI Architecture](../frontend-ui-architecture/README.md)
- [Design System](../design-system/README.md)
- [Accessibility Guidelines](../../architecture/accessibility.md)

---

**Last Updated:** 2025-12-10
**Authors:** Claude Code (with claude-senior-architect, daisyui-ios-component-architect, design-system-guardian agents)
**Quality Standard:** Premium iOS-style design 🚀

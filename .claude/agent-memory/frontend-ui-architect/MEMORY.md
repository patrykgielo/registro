# Frontend UI Architect — Project Memory

## Stack
- Tailwind CSS 4.0 with @theme (OKLCH semantic tokens)
- Vite 7+ (ALWAYS npm run build, NEVER npm run dev)
- Blade templates + Alpine.js (no React/Vue)
- Filament v4 for admin panel

## Design System v5.0
- Source of truth: resources/css/design-tokens.css
- OKLCH color tokens via @theme
- Dark sections: #00323B bg, #000000 tiles, #0AB1EA CTA, #FFFFFF text
- NOT systemwide dark mode — only dark-background sections

## Filament v4 Gotchas
- form(Schema $schema): Schema
- Filament\Actions\EditAction (not Tables\Actions)
- Admin form changes → VERIFY frontend views (null/empty crash risk)

## Component Patterns
- iOS-inspired service cards with variant="dark"
- prose-registro + prose-invert for dark section typography
- Luminance-based auto-detection for dark backgrounds

## Accessibility
- WCAG 2.2 AA target
- 44px minimum touch targets
- reduced-motion support required
- GPU-only animations: transform/opacity (never width/height/margin)

## Alpine Patterns
- x-ui.button is static Blade — NO Alpine bindings on it
- Multi-card shared state: lift x-data to common ancestor, use raw HTML styled with button utility classes
- See: feedback_alpine_scope.md

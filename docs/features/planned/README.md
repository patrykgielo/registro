# Planned Features - Registro

This directory contains detailed implementation plans for future features that have been designed but not yet implemented.

## Purpose

These plans serve as:
- **Roadmap items** - Features ready for implementation when priorities align
- **Design documentation** - Research-backed decisions already made
- **Implementation guides** - Step-by-step execution plans with technical details
- **Knowledge preservation** - Valuable planning work saved for future use

## Status: Planned (Not Started)

### Visual Redesign v4.0 - Monochrome Color System

**Plan File:** [visual-redesign-v4.0-monochrome.md](./visual-redesign-v4.0-monochrome.md)

**Created:** 2025-12-11
**Status:** 🟡 **Planned** - Ready for implementation
**Priority:** Medium
**Estimated Effort:** 8-9 hours (45 templates, 3 config files)

**Summary:**
Complete visual redesign implementing professional monochrome color palette with single turquoise accent. Addresses 6 critical improvements:

1. **Hero Section Mobile Fix** - Reduce height from 100vh to 50vh on mobile
2. **Gradient Removal** - Replace all purple/pink gradients with solid colors
3. **Border-Radius Standardization** - Unify to 10px across all UI elements
4. **Logo Preservation** - Keep sharp geometric edges as brand element
5. **Monochrome System** - 88% neutrals, 12% turquoise accent (#4AA5B0)
6. **Professional Quality** - Research-backed luxury brand aesthetic

**Key Decisions Made:**
- ✅ Color palette: "Medical Precision" monochrome (9 neutral shades + turquoise)
- ✅ Border-radius: 10px standard (0.625rem)
- ✅ Hero responsive: 50vh mobile → 70vh desktop
- ✅ Typography: Fluid with clamp() for optimal scaling
- ✅ No multi-color gradients (brand consistency)

**Technical Approach:**
- Phase 1: Design system foundation (30 min)
- Phase 2: Hero component fixes (45 min)
- Phase 3: Border-radius updates (2 hours)
- Phase 4: Page templates (2 hours)
- Phase 5: Gradient icon replacements (1 hour)
- Phase 6: CSS utilities (15 min)
- Phase 7: Testing & validation (1 hour)
- Phase 8: Documentation (30 min)

**Files to Modify:**
- `design-system.json` - Color palette v4.0.0
- `tailwind.config.js` - DaisyUI theme update
- `resources/css/app.css` - Fluid typography utilities
- 45+ Blade templates - Border-radius + gradient removal
- 10 core components - Hero, buttons, inputs, cards, etc.

**Dependencies:**
- None - can be implemented anytime
- Will require visual regression testing
- Should coordinate with any ongoing UI work

**When to Implement:**
Consider implementing when:
- Scheduling UI/UX improvements sprint
- Need to refresh brand identity
- Want to improve mobile experience
- Budget available for design polish (~1-2 dev days)

**References:**
- Research: BMW, Mercedes, Stripe, Linear design systems
- WCAG AA compliance maintained
- Mobile-first responsive approach
- Design tokens: Single source of truth

---

## How to Use This Directory

### For Developers

When ready to implement a planned feature:

1. **Review the plan file** - Understand the full scope and decisions
2. **Check dependencies** - Verify all prerequisites are met
3. **Create feature branch** - `git checkout -b feature/[plan-name]`
4. **Follow the phases** - Use plan as implementation guide
5. **Update status** - Move from "Planned" to "In Progress" in this README

### For Product/Project Managers

When prioritizing roadmap:

1. **Estimated effort** is already calculated
2. **Technical decisions** are documented
3. **Research** is completed and referenced
4. **Risks** are identified with mitigation strategies

### Adding New Plans

When creating a new plan (via Claude Code plan mode or manually):

1. Save detailed plan to this directory: `docs/features/planned/[feature-name].md`
2. Add entry to this README with status, summary, and metadata
3. Include: Status, Priority, Estimated Effort, Key Decisions, Dependencies
4. Commit to repository for team visibility

---

## Plan Status Legend

- 🟡 **Planned** - Designed, ready for implementation when prioritized
- 🟢 **In Progress** - Currently being implemented
- ✅ **Completed** - Implemented and deployed (move to `docs/features/`)
- 🔴 **Blocked** - Cannot proceed due to dependencies or external factors
- ⏸️ **Paused** - Started but temporarily suspended
- ❌ **Cancelled** - No longer relevant or superseded by other work

---

## Archive Policy

When a planned feature is:
- **Completed** - Move plan to `docs/features/[feature-name]/` with implementation notes
- **Cancelled** - Move to `docs/features/planned/archive/` with cancellation reason
- **Superseded** - Update README with reference to new plan that replaces it

---

**Last Updated:** 2025-12-14
**Maintained By:** Development Team

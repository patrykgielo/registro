# Filament v4 Onboarding Checklist

**Version:** Filament v4.2.3
**Last Updated:** 2025-12-17
**Purpose:** Complete onboarding guide for new developers working with Filament admin panel

---

## 👋 Welcome!

This checklist ensures you understand Filament v4 architecture, best practices, and common pitfalls **before** starting development. Following this guide prevents bugs and ensures consistent, high-quality code.

**Estimated Time:** 2-3 hours (reading + hands-on exercises)

---

## Phase 1: Required Reading (1-2 hours)

### ✅ Core Documentation

#### 1. Component Architecture (30-45 minutes)

**Read:** [Component Architecture Guide](filament-v4-component-architecture.md)

**Focus on these critical sections:**
- [ ] Component Type Reference (understand 6 main types: Panel, Resource, Page, Widget, Form, Table)
- [ ] Component Hierarchy (know the nesting order)
- [ ] **Nesting Rules Matrix** ⚠️ CRITICAL - what can contain what
- [ ] Widget Architecture (dashboard vs resource vs page-level widgets)
- [ ] Common Mistakes to Avoid (prevent widget/section nesting bug)

**Key Takeaways:**
- Widgets are **top-level components** with built-in layout
- Layout components (Section, Grid, Tabs) use `Filament\Schemas\Components` namespace
- Form inputs (TextInput, Select) use `Filament\Forms\Components` namespace

**Self-Check Questions:**
1. Can you nest `<x-filament::section>` inside `<x-filament-widgets::widget>`?
   - **Answer:** ❌ NO - causes layout conflicts
2. What namespace do Section and Grid use in v4?
   - **Answer:** `Filament\Schemas\Components\Section` and `Grid`
3. What are the 3 widget scopes?
   - **Answer:** Dashboard (global), Resource (record), Page (page-state)

---

#### 2. Best Practices (30-45 minutes)

**Read:** [Best Practices Guide](filament-v4-best-practices.md)

**Focus on these critical sections:**
- [ ] Widget Implementation Patterns (✅ CORRECT vs ❌ WRONG examples)
- [ ] **Performance Optimization** ⚠️ CRITICAL
  - [ ] Widget lazy loading (`$isLazy = true`)
  - [ ] Query memoization with `once()`
  - [ ] Cache tagging for granular invalidation
  - [ ] Form debouncing (`live(debounce: 500)`)
- [ ] Security Best Practices (authorization patterns)
- [ ] Common Mistakes to Avoid

**Key Takeaways:**
- Always use `$isLazy = true` for heavy widgets
- Add `debounce` to all `->live()` form fields
- Cache expensive queries with 5-15 minute TTL
- Use `once()` to prevent duplicate queries within request

**Self-Check Questions:**
1. How do you prevent form re-render storms?
   - **Answer:** Use `->live(debounce: 500)` instead of `->live()`
2. When should you use `$isLazy = true`?
   - **Answer:** For widgets with expensive queries or slow rendering
3. How do you prevent N+1 queries in table widgets?
   - **Answer:** Use `->with(['relation'])` eager loading in query

---

#### 3. Widgets Guide (30-45 minutes - skim/reference)

**Skim:** [Widgets Guide](filament-v4-widgets-guide.md)

**Familiarize yourself with:**
- [ ] Stats Widgets (basic metrics display)
- [ ] Chart Widgets (line, bar, pie charts)
- [ ] Table Widgets (recent records lists)
- [ ] Custom Widgets (any custom content)
- [ ] Widget polling patterns
- [ ] Deferred loading with `#[Defer]`

**You don't need to memorize:** Use this as reference when implementing widgets

---

#### 4. Migration Guide (15-20 minutes - awareness)

**Skim:** [Migration Guide](filament-v4-migration-guide.md)

**Be aware of:**
- [ ] Critical namespace changes (Schemas, Forms, Infolists)
- [ ] v3 → v4 breaking changes

**Why this matters:** You may encounter old v3 examples in blog posts or Stack Overflow. Knowing the differences helps you adapt code correctly.

---

### ✅ Quick Reference (Keep Open)

**Bookmark:** [Quick Reference Card](filament-v4-quick-reference.md)

**Use this while coding** - one-page cheat sheet with:
- Component nesting rules
- v4 namespace reference table
- Common task templates
- Performance checklist
- Troubleshooting guide

---

## Phase 2: Hands-On Exercises (30-60 minutes)

### Exercise 1: Create a Simple Stats Widget (10 minutes)

**Goal:** Practice basic widget creation

**Task:**
1. Create a stats widget showing total users count
2. Add description and icon
3. Register on dashboard
4. Test in browser

**Steps:**
```bash
# Create widget
php artisan make:filament-widget UserStatsWidget --stats
```

**Edit widget:**
```php
protected function getStats(): array
{
    return [
        Stat::make('Total Users', User::count())
            ->description('Registered accounts')
            ->descriptionIcon('heroicon-o-users')
            ->color('success'),
    ];
}
```

**Register in AdminPanelProvider:**
```php
public function panel(Panel $panel): Panel
{
    return $panel->widgets([
        \App\Filament\Widgets\UserStatsWidget::class,
    ]);
}
```

**✅ Success Criteria:**
- Widget displays on dashboard
- Shows correct user count
- Has icon and description

---

### Exercise 2: Add Lazy Loading (5 minutes)

**Goal:** Practice performance optimization

**Task:** Modify widget from Exercise 1 to use lazy loading

**Steps:**
```php
class UserStatsWidget extends BaseWidget
{
    protected static bool $isLazy = true;  // ← Add this

    protected function getStats(): array
    {
        // Widget loads after page renders
        return [
            Stat::make('Total Users', User::count())
                ->description('Registered accounts')
                ->descriptionIcon('heroicon-o-users'),
        ];
    }
}
```

**✅ Success Criteria:**
- Page loads faster (widget loads after)
- Widget shows loading skeleton initially

---

### Exercise 3: Review Existing Widget Code (10 minutes)

**Goal:** Identify correct and incorrect patterns

**Task:** Review `app/Filament/Widgets/CacheClearWidget.php`

**Check for:**
- [ ] Correct namespace (`Filament\Widgets\Widget`)
- [ ] Proper authorization (`canView()` method)
- [ ] Column span defined
- [ ] Sort order defined

**Review Blade template:** `resources/views/filament/widgets/cache-clear.blade.php`

**Verify:**
- [ ] Uses `<x-filament-widgets::widget>` wrapper
- [ ] No nested `<x-filament::section>`
- [ ] Uses named slots for heading/description
- [ ] Direct content (no layout components)

**✅ Success Criteria:**
- Can identify correct widget structure
- Can explain why Section is NOT nested

---

### Exercise 4: Audit One Resource File (10 minutes)

**Goal:** Practice namespace verification

**Task:** Pick any file from `app/Filament/Resources/` and verify namespaces

**Check for:**
```bash
# Find a resource file
ls app/Filament/Resources/

# Open any Resource file
# e.g., app/Filament/Resources/UserResource.php
```

**Verify:**
- [ ] Layout components use `Filament\Schemas\Components\` (Section, Grid, Tabs)
- [ ] Form inputs use `Filament\Forms\Components\` (TextInput, Select)
- [ ] Table columns use `Filament\Tables\Columns\` (TextColumn, BadgeColumn)
- [ ] No v3 deprecated namespaces

**✅ Success Criteria:**
- Can identify namespace errors
- Understand when to use Schemas vs Forms

---

## Phase 3: Knowledge Check (15 minutes)

### Core Concepts

Before starting development, answer these questions **without looking at docs**:

#### 1. Widget Architecture

**Q:** Can you nest `<x-filament::section>` inside `<x-filament-widgets::widget>`?

<details>
<summary>Show Answer</summary>

**A:** ❌ **NO**

**Why:** Widgets are top-level components with built-in layout. Nesting Section causes layout conflicts.

**Correct pattern:**
```php
<x-filament-widgets::widget>
    <x-slot name="heading">Title</x-slot>
    <div>Content</div>
</x-filament-widgets::widget>
```
</details>

---

#### 2. Namespace Changes

**Q:** What's the v4 namespace for Section component?

<details>
<summary>Show Answer</summary>

**A:** `Filament\Schemas\Components\Section`

**Wrong (v3):** `Filament\Forms\Components\Section`

**Remember:** Layout components = Schemas, Form inputs = Forms
</details>

---

#### 3. Performance

**Q:** How do you prevent form re-render storms when using `live()` fields?

<details>
<summary>Show Answer</summary>

**A:** Use `debounce` parameter:

```php
TextInput::make('search')
    ->live(debounce: 500)  // ← Waits 500ms after typing stops
    ->afterStateUpdated(fn ($state) => $this->search($state))
```

**Without debounce:** Re-renders on every keystroke (performance issue)
</details>

---

#### 4. Widget Placement

**Q:** Where do heading and description go in a widget?

<details>
<summary>Show Answer</summary>

**A:** Widget's named slots:

```php
<x-filament-widgets::widget>
    <x-slot name="heading">Title Here</x-slot>
    <x-slot name="description">Subtitle Here</x-slot>
    <div>Content</div>
</x-filament-widgets::widget>
```

**NOT in Section component inside widget**
</details>

---

#### 5. Documentation

**Q:** What file documents widget nesting rules?

<details>
<summary>Show Answer</summary>

**A:** `docs/guides/filament-v4-component-architecture.md`

**Section:** "Nesting Rules Matrix" + "Common Mistakes to Avoid"
</details>

---

#### 6. Lazy Loading

**Q:** When should you use `$isLazy = true` on widgets?

<details>
<summary>Show Answer</summary>

**A:** For widgets with:
- Expensive database queries
- External API calls
- Heavy computations
- Slow rendering

**Why:** Widget loads after initial page render, improving perceived performance

**Example:**
```php
class HeavyStatsWidget extends BaseWidget
{
    protected static bool $isLazy = true;

    protected function getStats(): array
    {
        return Cache::remember('expensive.stats', 600, fn () =>
            HeavyModel::complexQuery()->get()
        );
    }
}
```
</details>

---

#### 7. Authorization

**Q:** How do you restrict widget access to admins only?

<details>
<summary>Show Answer</summary>

**A:** Use `canView()` method:

```php
public static function canView(): bool
{
    return auth()->user()?->hasRole('admin') ?? false;
}
```

**Or permission-based:**
```php
public static function canView(): bool
{
    return auth()->user()?->can('view_widgets') ?? false;
}
```
</details>

---

#### 8. Caching

**Q:** What's the recommended cache TTL for dashboard stats?

<details>
<summary>Show Answer</summary>

**A:** 5-15 minutes (300-900 seconds)

**Example:**
```php
$count = Cache::remember('dashboard.users', 600, fn () =>
    User::count()
);
```

**Why:** Balance between freshness and performance
**Remember:** Clear cache on model changes
</details>

---

## Phase 4: Development Readiness Checklist

### Before Writing Any Filament Code

- [ ] I've read Component Architecture Guide (focus on nesting rules)
- [ ] I've read Best Practices Guide (focus on performance)
- [ ] I've bookmarked Quick Reference Card
- [ ] I completed all hands-on exercises
- [ ] I can answer all Knowledge Check questions
- [ ] I know where to find documentation when stuck

### Critical Rules I Will Follow

- [ ] **NEVER nest Section in Widget** (causes layout bugs)
- [ ] **ALWAYS use correct v4 namespaces** (Schemas for layout, Forms for inputs)
- [ ] **ALWAYS add debounce to live() fields** (prevent re-render storms)
- [ ] **ALWAYS use lazy loading for heavy widgets** (`$isLazy = true`)
- [ ] **ALWAYS cache expensive queries** (5-15 min TTL)
- [ ] **ALWAYS clear cache on model changes** (prevent stale data)
- [ ] **ALWAYS add authorization to widgets** (`canView()` method)

### My Go-To Resources

- [ ] **Quick tasks:** [Quick Reference Card](filament-v4-quick-reference.md)
- [ ] **Architecture questions:** [Component Architecture](filament-v4-component-architecture.md)
- [ ] **Performance issues:** [Best Practices - Performance](filament-v4-best-practices.md#performance-optimization)
- [ ] **Widget patterns:** [Widgets Guide](filament-v4-widgets-guide.md)
- [ ] **Troubleshooting:** [Quick Reference - Troubleshooting](filament-v4-quick-reference.md#troubleshooting)

---

## Common Beginner Mistakes (Learn from Others!)

### ❌ Mistake 1: Nesting Section in Widget

```php
// ❌ WRONG
<x-filament-widgets::widget>
    <x-filament::section heading="Title">
        Content
    </x-filament::section>
</x-filament-widgets::widget>
```

**Result:** Layout conflicts, heading displays incorrectly

**Fix:** Use widget slots
```php
// ✅ CORRECT
<x-filament-widgets::widget>
    <x-slot name="heading">Title</x-slot>
    <div>Content</div>
</x-filament-widgets::widget>
```

---

### ❌ Mistake 2: Using v3 Namespaces

```php
// ❌ WRONG (v3)
use Filament\Forms\Components\Section;
```

**Result:** `Class not found` error after Filament upgrade

**Fix:** Use v4 namespace
```php
// ✅ CORRECT (v4)
use Filament\Schemas\Components\Section;
```

---

### ❌ Mistake 3: No Debounce on Live Fields

```php
// ❌ WRONG - Re-renders on every keystroke
TextInput::make('search')->live()
```

**Result:** Performance issues, slow UI, high server load

**Fix:** Add debounce
```php
// ✅ CORRECT - Waits 500ms after typing stops
TextInput::make('search')->live(debounce: 500)
```

---

### ❌ Mistake 4: No Lazy Loading on Heavy Widgets

```php
// ❌ WRONG - Blocks page load
class HeavyStatsWidget extends BaseWidget
{
    protected function getStats(): array
    {
        return [Stat::make('Total', ExpensiveModel::count())];
    }
}
```

**Result:** Slow dashboard load times

**Fix:** Add lazy loading
```php
// ✅ CORRECT - Loads after page renders
class HeavyStatsWidget extends BaseWidget
{
    protected static bool $isLazy = true;

    protected function getStats(): array
    {
        return [Stat::make('Total', ExpensiveModel::count())];
    }
}
```

---

### ❌ Mistake 5: No Caching for Repeated Queries

```php
// ❌ WRONG - Queries on every page load
protected function getStats(): array
{
    return [Stat::make('Total', User::count())];
}
```

**Result:** Unnecessary database load

**Fix:** Add caching
```php
// ✅ CORRECT - Cached for 5 minutes
protected function getStats(): array
{
    $count = Cache::remember('users.count', 300, fn () =>
        User::count()
    );
    return [Stat::make('Total', $count)];
}
```

---

## Getting Help

### When Stuck

**1. Check Quick Reference First**
- [Quick Reference Card](filament-v4-quick-reference.md) - Common tasks

**2. Search Documentation**
- [Component Architecture](filament-v4-component-architecture.md) - Structure questions
- [Best Practices](filament-v4-best-practices.md) - Performance/security
- [Widgets Guide](filament-v4-widgets-guide.md) - Widget patterns

**3. Review Audit Report**
- [Code Audit](../audits/filament-v4-code-audit.md) - See what's correct in codebase

**4. Ask for Code Review**
- Ping senior developer before pushing major changes
- Reference documentation in PR description

---

## Next Steps After Onboarding

### Your First Task

**Recommended:** Start with a simple stats widget

**Why:**
- Low complexity
- Immediate visual feedback
- Covers core concepts (namespace, registration, rendering)

**Example Task:** "Create a widget showing today's appointment count"

### Progressive Complexity

1. **Week 1:** Simple stats widgets
2. **Week 2:** Chart widgets (line/bar charts)
3. **Week 3:** Table widgets (recent records)
4. **Week 4:** Custom widgets with interactions

---

## Onboarding Completion

### Sign-Off

When you've completed this onboarding:

- [ ] I've completed all required reading
- [ ] I've finished all hands-on exercises
- [ ] I've answered all knowledge check questions correctly
- [ ] I understand the critical rules
- [ ] I'm ready to start Filament development

**Name:** ___________________________

**Date:** ___________________________

**Mentor Approval:** ___________________________

---

## Feedback

**Help us improve this onboarding!**

What was:
- **Most helpful:** ___________________________
- **Confusing:** ___________________________
- **Missing:** ___________________________

**Suggestions:** ___________________________

---

## Additional Resources

- **[Official Filament Docs](https://filamentphp.com/docs/4.x)** - Official documentation
- **[Project README](../../README.md)** - Project overview
- **[CLAUDE.md](../../../CLAUDE.md)** - Quick reference for development

---

**Welcome to the team! Happy coding! 🚀**

**Last Updated:** 2025-12-17
**Version:** 1.0
**Maintained By:** Development Team

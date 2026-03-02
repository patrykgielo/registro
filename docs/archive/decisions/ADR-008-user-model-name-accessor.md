# ADR-008: User Model Name Accessor Pattern

**Date**: 2025-11-09
**Status**: Accepted
**Deciders**: Project Team
**Related**: Email System Implementation, User Authentication

## Context and Problem Statement

The User model stores names in two separate database fields (`first_name` and `last_name`) for flexibility and data normalization. However, many parts of the Laravel application ecosystem expect a single `name` property:

1. **Email Notifications**: Use `$user->name` in toMail() methods
2. **Email Templates**: Reference `{{ $customer_name }}` expecting single name field
3. **Blade Templates**: Display `$user->name` in UI
4. **Third-party Packages**: Many Laravel packages assume `$user->name` exists
5. **API Responses**: Frontend may expect consistent `name` property

**Example Error Encountered** (November 2025):
```
Undefined constant 'customer_name'
(View: /var/www/storage/framework/views/154eed9d5d7668a5ce95401f179d1ff9.blade.php)

Failed to render template: Property [name] does not exist on this collection instance.
```

**Root Cause**:
- Notifications called `$user->name` which didn't exist
- Email templates used `{{ $customer_name }}` which failed rendering
- Would require updating 10+ notification files, 18+ email templates, and countless Blade views

## Decision Drivers

- **DRY Principle**: Change in one place (User model) vs. 50+ consuming files
- **Backward Compatibility**: Existing code using `$user->name` should work
- **Laravel Conventions**: Most Laravel applications use `$user->name`
- **Developer Experience**: New developers expect `->name` to work
- **Maintainability**: Centralized logic easier to test and document
- **Error Prevention**: Documented pattern prevents future regressions

## Considered Options

### Option 1: Update All Consuming Code (REJECTED)

Change every file that uses `$user->name` to use `$user->full_name` or concatenate `$user->first_name . ' ' . $user->last_name`.

**Files Requiring Changes**:
- 8 Notification classes (App\Notifications\*)
- 18 Email templates (database records)
- Multiple Blade views (auth, booking, profile)
- Filament resources and pages
- API transformers and responses

**Pros**:
- No "magic" accessors
- Explicit data access

**Cons**:
- 50+ files to update (high risk of missing some)
- Violates DRY principle
- Future code will still try to use `->name` (habit)
- Difficult to enforce in code reviews
- Breaking change for existing integrations

### Option 2: Add Eloquent Accessor to User Model (CHOSEN)

Add a single `getNameAttribute()` accessor method to User model that returns `getFullNameAttribute()` result.

**Pros**:
- **One change** fixes entire application
- Backward compatible with existing code
- Follows Laravel conventions and best practices
- Eloquent accessors are standard Laravel pattern
- Easy to document and understand
- Future-proof: new code automatically works

**Cons**:
- Adds "magic" attribute (mitigated by PHPDoc and documentation)
- Developers must know about accessor (addressed via comprehensive docs)

### Option 3: Database View or Virtual Column (REJECTED)

Create database-level computed column or view that concatenates names.

**Pros**:
- Database-level consistency
- Works across all database queries

**Cons**:
- Database-specific syntax (MySQL vs PostgreSQL)
- Harder to test and maintain
- Overkill for simple concatenation
- Doesn't solve Eloquent model access issue

## Decision Outcome

**Chosen Option**: Option 2 - Add Eloquent Accessor to User Model

### Implementation

**File**: `app/Models/User.php` (Lines 73-100)

```php
/**
 * Get the user's full name via the 'name' attribute.
 *
 * This accessor provides a 'name' attribute by combining first_name and last_name.
 * Used throughout the application by notifications, emails, Blade templates, and API responses.
 *
 * Background: The User model stores names in two separate fields (first_name, last_name)
 * for flexibility, but many parts of the application expect a single 'name' property
 * (notifications, email templates, legacy code). This accessor bridges that gap without
 * requiring changes to all consuming code.
 *
 * @return string Full name (first_name + last_name)
 *
 * @example
 * $user->name         // Returns "Jan Kowalski" (via this accessor)
 * $user->full_name    // Returns "Jan Kowalski" (via getFullNameAttribute)
 * $user->first_name   // Returns "Jan"
 * $user->last_name    // Returns "Kowalski"
 *
 * @see getFullNameAttribute() - Canonical implementation of name concatenation
 * @see getFilamentName() - Used by Filament admin panel (also calls getFullNameAttribute)
 *
 * @since November 2025 - Added to support email notifications and templates
 */
public function getNameAttribute(): string
{
    return $this->getFullNameAttribute();
}
```

### Usage Examples

**In Notifications**:
```php
// Before (would fail):
// $this->user->name  // Property [name] does not exist

// After (works automatically):
public function toMail($notifiable)
{
    return (new MailMessage)
        ->greeting('Hello ' . $notifiable->name)  // ✅ Works via accessor
        ->line('Welcome to ' . config('app.name'));
}
```

**In Email Templates**:
```blade
{{-- Before (would fail): --}}
{{-- {{ $customer_name }}  // Undefined constant --}}

{{-- After (works automatically): --}}
Hello {{ $customer_name }},  {{-- ✅ Works if $customer_name = $user->name --}}

Your appointment is confirmed.
```

**In Blade Views**:
```blade
{{-- Before (manual concatenation): --}}
<p>Welcome, {{ $user->first_name }} {{ $user->last_name }}!</p>

{{-- After (clean accessor usage): --}}
<p>Welcome, {{ $user->name }}!</p>  {{-- ✅ Cleaner, more maintainable --}}
```

**In API Resources**:
```php
// Before (custom transformation):
public function toArray($request)
{
    return [
        'name' => $this->first_name . ' ' . $this->last_name,
        // ...
    ];
}

// After (automatic via accessor):
public function toArray($request)
{
    return [
        'name' => $this->name,  // ✅ Accessor handles concatenation
        // ...
    ];
}
```

### Positive Consequences

1. **Zero Breaking Changes**: Existing code continues to work
2. **DRY Compliance**: Single source of truth for name formatting
3. **Error Prevention**: Future code automatically gets `->name` support
4. **Laravel Conventions**: Follows standard Eloquent accessor pattern
5. **Documentation**: Comprehensive PHPDoc prevents confusion
6. **Testing**: Easy to unit test accessor in isolation
7. **IDE Support**: PHPDoc enables autocomplete and type checking

### Negative Consequences

**None Identified**

The accessor adds no runtime overhead, maintains backward compatibility, and follows Laravel best practices. The only "cost" is developer awareness, which is addressed through:
- Comprehensive PHPDoc in User model
- Updated CLAUDE.md documentation
- This ADR for architectural context
- Troubleshooting guide entries

## Compliance and Best Practices

### Laravel Conventions

This implementation follows official Laravel documentation:
- [Eloquent Accessors & Mutators](https://laravel.com/docs/11.x/eloquent-mutators#defining-an-accessor)
- Accessor naming: `get{AttributeName}Attribute()`
- PHPDoc for IDE support and developer guidance

### Database Schema

User model database structure remains unchanged:
```sql
users (
  id BIGINT PRIMARY KEY,
  first_name VARCHAR(255),  -- Still separate fields
  last_name VARCHAR(255),   -- For flexibility
  email VARCHAR(255) UNIQUE,
  -- ...
)
```

**Why Not Merge Fields?**
- Allows separate first/last name validation
- Enables localization (some cultures: last name first)
- Supports forms with separate input fields
- Maintains data normalization

### Testing Strategy

**Unit Test** (recommended):
```php
// tests/Unit/UserTest.php
public function test_name_accessor_returns_full_name()
{
    $user = User::factory()->make([
        'first_name' => 'Jan',
        'last_name' => 'Kowalski',
    ]);

    $this->assertEquals('Jan Kowalski', $user->name);
    $this->assertEquals('Jan Kowalski', $user->full_name);
}
```

## References

- **Implementation**: `app/Models/User.php` (lines 73-100)
- **Related Error**: Email System troubleshooting (November 2025)
- **Laravel Docs**: [Eloquent Accessors](https://laravel.com/docs/11.x/eloquent-mutators)
- **Consuming Code**:
  - `app/Notifications/*.php` (8 notification classes)
  - Email templates in database (`email_templates` table)
  - Blade views: `resources/views/**/*.blade.php`
  - Filament resources: `app/Filament/**/*.php`

## Historical Context

### Timeline of Issues

**October 2025**: User model refactored to use `first_name` + `last_name`
- Registration form updated
- Database migration completed
- But no accessor added at the time

**November 2025**: Email system implementation revealed the gap
- Notifications failed: "Property [name] does not exist"
- Email templates failed: "Undefined constant customer_name"
- Multiple files would need updates (10+ notifications, 18+ templates)

**November 9, 2025**: Decision made to add accessor
- One-line change in User model
- Comprehensive PHPDoc added
- This ADR created to prevent regression
- Documentation updated across project

### Key Lesson

**When refactoring data models, consider downstream dependencies:**
1. Search codebase for `$model->old_property` usage
2. Add accessors/mutators for backward compatibility
3. Document breaking changes in ADRs
4. Update all consuming code OR provide compatibility layer

## Review and Updates

This decision should be reviewed if:
- User model undergoes major refactoring (e.g., moving to separate Profile model)
- Name formatting requirements change (e.g., middle names, titles, honorifics)
- Internationalization requires different name ordering (e.g., "Kowalski Jan" for some locales)
- Performance profiling shows accessor creates bottleneck (unlikely with simple concatenation)

**Current Status**: Production-ready, actively used throughout application.

# ADR-003: Role-Based Access Control (RBAC)

**Status:** Accepted (Existing Implementation)
**Date:** 2025-10-12
**Deciders:** Backend Architect (Analysis)

## Context

The Paradocks booking system requires different access levels for:
- **Customers:** Book appointments, view their own appointments, manage their profile
- **Staff:** View assigned appointments, update appointment status, manage availability
- **Admins:** Full appointment management, user management, service configuration
- **Super Admins:** System configuration, role management, complete system access

The application needed a flexible, scalable authorization system that could handle:
- Multiple roles per user
- Role-based route protection
- Fine-grained permissions
- Admin panel access control
- Future permission expansions

## Decision

Implement **Role-Based Access Control (RBAC)** using **Spatie Laravel Permission** package (v6.21).

**Key Implementation:**
- Four primary roles: super-admin, admin, staff, customer
- User model uses `HasRoles` trait
- Helper methods for common role checks
- Filament panel access controlled via `canAccessPanel()` method
- Role-based middleware and gates for route protection

**Roles Defined:**
```php
// Super Admin - Full system access
'super-admin'

// Admin - Administrative access (service config, appointment management)
'admin'

// Staff - Service provider access (view assigned appointments, update status)
'staff'

// Customer - Booking and self-service access
'customer'
```

## Consequences

### Positive

1. **Package Maturity**
   - Spatie Laravel Permission is industry-standard (19k+ stars on GitHub)
   - Well-maintained, documented, and tested
   - Large community for support and troubleshooting

2. **Flexibility**
   - Can assign multiple roles to a user (e.g., staff member who is also admin)
   - Permission system available for fine-grained control (not yet used)
   - Easy to add new roles without database changes

3. **Database Efficiency**
   - Roles cached automatically by package
   - Optimized queries for role checking
   - Minimal performance overhead

4. **Integration with Laravel**
   - Works seamlessly with Laravel's Gate and Policy system
   - Middleware available out of the box
   - Blade directives for view-level access control

5. **Filament Compatibility**
   - Easy integration with Filament admin panel
   - `canAccessPanel()` method cleanly checks roles
   - Resource-level authorization possible

6. **Code Readability**
   - Helper methods make code self-documenting
   - `$user->isAdmin()` is clear and concise
   - Consistent pattern throughout application

### Negative

1. **Additional Database Tables**
   - Adds 5 tables to database (roles, permissions, pivots)
   - Increases database complexity
   - More data to seed and maintain

2. **Package Dependency**
   - Application coupled to Spatie's implementation
   - Must follow package upgrade path
   - Potential breaking changes in major versions

3. **Permissions Not Utilized**
   - Package supports fine-grained permissions
   - Currently only using roles (simpler, but less granular)
   - May need refactoring if permission-level control needed

4. **Role Assignment Logic Not Centralized**
   - Roles can be assigned anywhere in code
   - No formal "role assignment service"
   - Risk of inconsistent role management

### Risks

**Risk 1: Over-Permission**
- Currently, all users with same role have identical permissions
- No way to restrict individual staff members from certain actions
- Example: All staff can view all appointments, not just their own

**Risk 2: Default Role Not Enforced**
- New user registration doesn't automatically assign 'customer' role
- Manual role assignment required
- Could lead to users with no role (undefined behavior)

**Risk 3: Admin Panel Access Too Broad**
```php
public function canAccessPanel(Panel $panel): bool
{
    return $this->hasAnyRole(['super-admin', 'admin', 'staff']);
}
```
- All staff can access admin panel
- May want to restrict certain staff from admin area
- Consider permission-based access instead

## Alternatives Considered

### Alternative 1: Laravel's Built-in Gates and Policies
Use Laravel's authorization features without additional package.

**Pros:**
- No external dependencies
- Full control over implementation
- Lighter weight

**Cons:**
- More boilerplate code
- No role management UI
- Must implement own caching
- Reinventing the wheel

**Decision:** Rejected. Spatie package provides proven solution with minimal overhead.

### Alternative 2: Custom RBAC Implementation
Build custom role and permission system.

**Pros:**
- Complete control
- Exactly tailored to needs
- No package dependencies

**Cons:**
- Development time
- Must maintain and test
- Likely to have bugs
- Reinventing well-solved problem

**Decision:** Rejected. Not worth the development effort for standard functionality.

### Alternative 3: Attribute-Based Access Control (ABAC)
Use user attributes (department, level, location) for access control.

**Pros:**
- More flexible than roles
- Can express complex rules
- No explicit role assignment needed

**Cons:**
- Much more complex
- Harder to understand and debug
- Overkill for current requirements

**Decision:** Rejected. RBAC is sufficient for current and foreseeable needs.

## Current Implementation Analysis

### User Model Integration
```php
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements FilamentUser
{
    use HasRoles;

    // Helper methods
    public function isCustomer(): bool
    {
        return $this->hasRole('customer');
    }

    public function isStaff(): bool
    {
        return $this->hasRole('staff');
    }

    public function isAdmin(): bool
    {
        return $this->hasAnyRole(['admin', 'super-admin']);
    }

    // Filament access control
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->hasAnyRole(['super-admin', 'admin', 'staff']);
    }
}
```

**Strengths:**
- Clean helper methods improve code readability
- Consistent naming convention
- Filament integration is straightforward

**Weaknesses:**
- Helper methods could be a trait for reusability
- No default role assignment logic
- `isAdmin()` includes super-admin (may or may not be desired)

### Filament Resource Usage
```php
// AppointmentResource - Filter customers by role
Forms\Components\Select::make('customer_id')
    ->relationship('customer', 'name', fn (Builder $query) =>
        $query->whereHas('roles', fn ($q) => $q->where('name', 'customer'))
    )

// Filter staff by role
Forms\Components\Select::make('staff_id')
    ->relationship('staff', 'name', fn (Builder $query) =>
        $query->whereHas('roles', fn ($q) => $q->whereIn('name', ['staff', 'admin', 'super-admin']))
    )
```

**Strengths:**
- Proper role-based filtering in dropdowns
- Prevents selecting wrong user types

**Weaknesses:**
- Role names hardcoded in multiple places
- Should use constants or config values
- Repeated query logic (could be scopes)

## Recommendations

### High Priority

1. **Enforce Default Role on Registration**
```php
// In RegisterController or User model observer
protected function create(array $data)
{
    $user = User::create([
        'name' => $data['name'],
        'email' => $data['email'],
        'password' => Hash::make($data['password']),
    ]);

    // Assign customer role by default
    $user->assignRole('customer');

    return $user;
}
```

2. **Define Role Constants**
```php
// app/Enums/UserRole.php
enum UserRole: string
{
    case SUPER_ADMIN = 'super-admin';
    case ADMIN = 'admin';
    case STAFF = 'staff';
    case CUSTOMER = 'customer';

    public static function staff(): array
    {
        return [self::STAFF->value, self::ADMIN->value, self::SUPER_ADMIN->value];
    }
}

// Usage in code
$user->hasRole(UserRole::CUSTOMER->value)
$query->whereHas('roles', fn($q) =>
    $q->whereIn('name', UserRole::staff())
)
```

3. **Add Model Scopes for Role Filtering**
```php
// In User model
public function scopeCustomers($query)
{
    return $query->whereHas('roles', fn($q) => $q->where('name', 'customer'));
}

public function scopeStaffMembers($query)
{
    return $query->whereHas('roles', fn($q) =>
        $q->whereIn('name', ['staff', 'admin', 'super-admin'])
    );
}

// Usage
User::customers()->get();
User::staffMembers()->where('id', $id)->first();
```

### Medium Priority

4. **Implement Fine-Grained Permissions**

Consider adding permissions for specific actions:
```php
// Define permissions
'view-all-appointments'       // See all appointments (admin/super-admin)
'view-own-appointments'       // See only assigned appointments (staff)
'manage-services'             // Create/edit services (admin/super-admin)
'manage-users'                // User management (super-admin only)
'manage-availability'         // Edit own availability (staff)
'manage-all-availability'     // Edit any staff availability (admin)

// Usage in Policy
public function viewAny(User $user)
{
    return $user->hasPermissionTo('view-all-appointments');
}

public function update(User $user, Appointment $appointment)
{
    return $user->hasPermissionTo('view-all-appointments')
        || ($user->hasPermissionTo('view-own-appointments')
            && $appointment->staff_id === $user->id);
}
```

5. **Create Policies for Models**
```php
// app/Policies/AppointmentPolicy.php
class AppointmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin() || $user->isStaff();
    }

    public function view(User $user, Appointment $appointment): bool
    {
        return $user->isAdmin()
            || $appointment->customer_id === $user->id
            || $appointment->staff_id === $user->id;
    }

    public function update(User $user, Appointment $appointment): bool
    {
        return $user->isAdmin() || $appointment->staff_id === $user->id;
    }

    public function cancel(User $user, Appointment $appointment): bool
    {
        return $appointment->customer_id === $user->id
            && $appointment->can_be_cancelled;
    }
}

// Register in AuthServiceProvider
protected $policies = [
    Appointment::class => AppointmentPolicy::class,
];
```

6. **Refine Filament Access Control**
```php
// More granular panel access
public function canAccessPanel(Panel $panel): bool
{
    // Only admin and super-admin access main panel
    if ($panel->getId() === 'admin') {
        return $this->hasAnyRole(['super-admin', 'admin']);
    }

    // Staff have their own limited panel
    if ($panel->getId() === 'staff') {
        return $this->hasRole('staff');
    }

    return false;
}
```

### Low Priority

7. **Role Hierarchy**

Consider implementing role hierarchy if business rules require it:
```php
// config/permission.php
return [
    'role_hierarchy' => [
        'super-admin' => ['admin', 'staff', 'customer'],
        'admin' => ['staff', 'customer'],
        'staff' => ['customer'],
    ],
];
```

8. **Audit Logging**

Track role assignments and permission changes:
```php
// User model observer
public function roleAssigned(Model $model, string $roleName)
{
    activity()
        ->performedOn($model)
        ->withProperties(['role' => $roleName])
        ->log('Role assigned');
}
```

## Security Considerations

### Current Security Posture
1. Authorization checks in controllers (good)
2. Role-based filtering in Filament (good)
3. Model relationships respect roles (good)

### Security Gaps
1. No automatic role assignment on registration
2. Direct database access could bypass role checks
3. No audit trail for role changes
4. Mass assignment of roles possible

### Security Recommendations
1. Always use policies for authorization, not just role checks
2. Implement middleware for critical routes
3. Add audit logging for sensitive actions
4. Consider two-factor authentication for admin/staff
5. Regular security audits of role assignments

## Testing Recommendations

1. **Unit Tests for Role Helpers**
```php
test('user can check if they are admin', function () {
    $user = User::factory()->create();
    $user->assignRole('admin');

    expect($user->isAdmin())->toBeTrue();
    expect($user->isStaff())->toBeFalse();
});
```

2. **Feature Tests for Authorization**
```php
test('staff cannot access customer appointments', function () {
    $staff = User::factory()->create();
    $staff->assignRole('staff');

    $customer = User::factory()->create();
    $customer->assignRole('customer');

    $appointment = Appointment::factory()->create([
        'customer_id' => $customer->id,
    ]);

    actingAs($staff)
        ->get(route('appointments.show', $appointment))
        ->assertForbidden();
});
```

3. **Integration Tests for Filament**
```php
test('customer cannot access admin panel', function () {
    $customer = User::factory()->create();
    $customer->assignRole('customer');

    actingAs($customer)
        ->get('/admin')
        ->assertRedirect();
});
```

## Migration Path

No breaking changes required. Improvements can be implemented incrementally:

**Phase 1: Foundation**
1. Add UserRole enum
2. Create model scopes
3. Implement default role assignment

**Phase 2: Policies**
4. Create model policies
5. Add policy checks to controllers
6. Update Filament resources to use policies

**Phase 3: Refinement**
7. Add fine-grained permissions if needed
8. Implement audit logging
9. Add comprehensive tests

## Related Decisions

- Future: ADR for customer data privacy and GDPR compliance
- Future: ADR for admin panel structure (single panel vs. multiple panels)
- Future: ADR for audit logging strategy

## References

- Spatie Laravel Permission: https://spatie.be/docs/laravel-permission/
- Laravel Authorization: https://laravel.com/docs/12.x/authorization
- Filament Access Control: https://filamentphp.com/docs/3.x/panels/users
- OWASP Access Control Cheat Sheet

# ADR-001: Service Layer Architecture

**Status:** Accepted (Existing Implementation)
**Date:** 2025-10-12
**Deciders:** Backend Architect (Analysis)

## Context

The Paradocks booking system requires complex business logic for:
- Appointment scheduling and availability checking
- Conflict detection across staff schedules
- Time slot generation with service duration considerations
- Multi-step validation for booking requests

The application needed to decide where to place this business logic:
1. Directly in controllers (simple but leads to fat controllers)
2. In model methods (couples business logic to data layer)
3. In dedicated service classes (separation of concerns)

## Decision

Implement a **Service Layer Architecture** with dedicated service classes that encapsulate complex business logic, keeping controllers thin and focused on HTTP concerns.

**Implementation:**
- Created `App\Services\AppointmentService` class
- Controllers inject the service via dependency injection
- Service methods handle all business logic and return simple data structures
- Controllers remain responsible only for HTTP request/response handling

## Consequences

### Positive

1. **Separation of Concerns**
   - Controllers handle HTTP (request validation, response formatting)
   - Services handle business rules (availability checking, conflict detection)
   - Models handle data relationships and persistence

2. **Testability**
   - Service methods can be unit tested independently
   - No need for HTTP layer testing for business logic
   - Easy to mock service dependencies in controller tests

3. **Reusability**
   - Same service methods used by web controllers and Filament resources
   - Business logic can be called from commands, jobs, or API endpoints
   - No code duplication across different entry points

4. **Maintainability**
   - Clear location for business logic (app/Services/)
   - Easy to find and modify business rules
   - Single Responsibility Principle maintained

5. **Type Safety**
   - Service methods use type hints and return types
   - PHP 8.2+ features (named arguments, strict types)
   - Clear contracts for what each method expects and returns

### Negative

1. **Additional Abstraction Layer**
   - More files to navigate (controller → service → model)
   - May feel like over-engineering for simple CRUD operations
   - Developers need to understand when to use services vs. direct model calls

2. **Boilerplate Code**
   - Service instantiation and dependency injection setup
   - Constructor injection in controllers
   - More classes overall

3. **Learning Curve**
   - New team members need to understand the architecture
   - Requires discipline to keep controllers thin
   - Risk of inconsistency if pattern not followed everywhere

### Mitigations

1. Only use service classes for complex business logic (not simple CRUD)
2. Document when to create a new service vs. adding to existing
3. Use clear naming conventions: `{Domain}Service`
4. Keep service methods focused and single-purpose

## Alternatives Considered

### Alternative 1: Fat Controllers
Place all logic directly in controller methods.

**Rejected because:**
- Controllers become difficult to test
- Code duplication across web/API/console
- Violates Single Responsibility Principle
- Hard to maintain as application grows

### Alternative 2: Model Methods
Place business logic in model methods.

**Rejected because:**
- Couples business logic to data layer
- Models become bloated "God objects"
- Difficult to test without database
- Breaks separation of concerns

### Alternative 3: Action Classes
Create single-purpose action classes for each operation (e.g., `CreateAppointmentAction`, `CheckAvailabilityAction`).

**Considered but not chosen because:**
- More granular than needed for current scope
- More files to manage (one per action)
- May be better suited for larger teams
- Can be adopted later if services become too large

**Note:** Action pattern may be appropriate as the application grows. Consider refactoring large services into focused action classes in the future.

## Implementation Notes

### Current Service Methods

**AppointmentService:**
- `checkStaffAvailability()` - Verifies staff availability and conflicts
- `getAvailableTimeSlots()` - Returns array of bookable time slots
- `validateAppointment()` - Comprehensive booking validation

### When to Create a New Service

Create a new service class when:
1. You have 3+ methods related to a specific domain concern
2. Logic is complex enough to warrant extraction from controller
3. Logic needs to be reused across multiple controllers
4. Business rules are likely to change frequently

Examples of future services to consider:
- `PricingCalculatorService` - Calculate totals with add-ons
- `NotificationService` - Multi-channel notification orchestration
- `PaymentService` - Payment gateway integration
- `VehicleService` - Vehicle management logic
- `BookingWorkflowService` - Multi-step booking coordination

### Service Best Practices

1. **Dependency Injection:** Inject dependencies (models, other services) via constructor
2. **Return Types:** Always specify return types
3. **Type Hints:** Use type hints for all parameters
4. **Named Arguments:** Leverage PHP 8+ named arguments for clarity
5. **Immutability:** Services should be stateless when possible
6. **Single Responsibility:** Each method should do one thing well

## Related Decisions

- Future: Consider ADR for Action classes if services grow too large
- Future: Consider ADR for Repository pattern if data access becomes complex

## References

- Laravel Service Container: https://laravel.com/docs/12.x/container
- SOLID Principles: Single Responsibility, Dependency Injection
- Martin Fowler's Service Layer: https://martinfowler.com/eaaCatalog/serviceLayer.html

# Appointment/Booking Integrity Fixes (2026-07)

Three findings from a security/architecture review of the appointment/booking subsystem, fixed on `fix/appointment-booking-integrity`.

## 1. Double-booking race condition

**Problem:** `AppointmentController::store()` and `BookingController::confirm()` both ran a SELECT-based conflict check (`AppointmentService::validateAppointment()` / staff+slot lookup) with no DB transaction, no row locking, and no unique constraint. Two concurrent requests for the same staff/slot could both pass the check and both successfully `Appointment::create()`.

**Fix:**
- New migration `2026_07_05_000001_add_double_booking_guard_to_appointments_table` adds a nullable `active_slot` column and a composite unique index `appointments_staff_slot_unique` on `(staff_id, appointment_date, start_time, active_slot)`.
- `Appointment::booted()` maintains `active_slot` automatically: `null` when `status = cancelled`, `true` otherwise. MySQL and SQLite both treat every `NULL` in a unique index as distinct from every other value, so cancelled appointments never block a slot, while two non-cancelled appointments for the same staff/date/start_time collide.
- Both controllers now wrap the check+create sequence in `DB::transaction()`, with a best-effort `AppointmentService::lockStaffAppointmentsForDate()` row lock acquired first (narrows the race window; not authoritative — SQLite ignores `FOR UPDATE` entirely, and MySQL can't lock rows that don't exist yet).
- The DB constraint is the **authoritative** guard. A `QueryException` from it is caught via `AppointmentService::isDoubleBookingViolation()` and translated into the same user-facing "slot no longer available" error both controllers already used for the SELECT-based rejection.

**Why not a plain `unique(staff_id, appointment_date, start_time)`:** MySQL has no partial/filtered unique index (unlike Postgres). Using the raw `status` column in the key doesn't work either — two *active* appointments with different statuses (e.g. one `pending`, one `confirmed`) would still be treated as distinct keys and slip through. The `active_slot` marker collapses all non-cancelled statuses to the same value while giving cancelled rows a NULL that never collides.

**Gotcha discovered while testing:** the `'datetime:H:i'` Eloquent cast on `start_time`/`end_time` only reformats on **read** — `Eloquent::setAttribute()` skips its `fromDateTime()` normalization entirely for `custom_datetime` casts (only plain `date`/`datetime` casts get that treatment). So the same wall-clock time could be stored as `'10:00:00'` (raw `Appointment::create()`, most factories) or `'10:00'` (validated `'H:i'` form input from the booking controllers), silently defeating the unique index's string comparison. `Appointment::booted()` now normalizes both columns to canonical `H:i:s` in a `saving` hook before the `active_slot` logic runs.

**Driver-specific error message:** `isDoubleBookingViolation()` checks for both `'appointments_staff_slot_unique'` (MySQL names the index) and `'appointments.active_slot'` (SQLite's `UNIQUE constraint failed` message lists column names instead, never the index name).

## 2. service_id/staff_id not tenant-scoped

**Problem:** `AppointmentController::store()`'s `'service_id' => 'exists:services,id'` rule queries the raw `services` table, bypassing `Service`'s `BelongsToOrganization` global scope entirely.

**Fix:** `Rule::exists('services', 'id')->where('organization_id', $tenant?->id)`, where `$tenant = $request->attributes->get('tenant')` (the request attribute set by `RequireTenant`/`ResolveTenant` — never `TenantFeature::currentTenant()`'s session-fallback branch, per the project's VULN-003 history).

**staff_id:** initially left unchanged (relying on `canPerformService()`'s tenant-scoped `Service` relation making a cross-tenant `staff_id` practically unreachable). Security review follow-up: that invariant, while correct, was implicit and fragile (depends on `canPerformService()` always running downstream, and `RequireTenant` staying on this route). For consistency with the explicit `service_id` check, `staff_id` now also gets an explicit `Rule::exists('organization_user', 'user_id')->where('organization_id', $tenant?->id)` check — `organization_user` is the pivot table backing `User::organizations()`. Documented in `App\Rules\StaffRoleRule`'s class docblock, which now reflects both the explicit check and the background invariant.

**Test fixture note:** this made `staff_id` validation fail for ANY test posting to `appointments.store` whose staff factory isn't attached to the tenant org via `$staff->organizations()->attach($org->id)` — required in `AppointmentDoubleBookingTest`, `AppointmentServiceTenantScopingTest`, and the pre-existing `ProfileSynchronizationTest`.

## 3. Day-level advance-booking check hid same-day slots

**Problem:** `BookingController::getAvailableSlots()` and `AppointmentService::calculateAvailableSlotsForDay()` both checked the 24h advance-booking rule against the day's business-hours-open instant (e.g. 09:00) and rejected the **entire day** if that single instant failed the check — before computing any per-slot availability. Browsing during business hours today for tomorrow hid legitimately bookable slots later that same day.

**Fix:** the check moved into the per-slot loop in both `AppointmentService::getAvailableSlotsAcrossAllStaff()` and `::calculateAvailableSlotsForDay()` — each candidate slot's own start time is compared against `now()->addHours($advanceHours)` individually.

## Migration safety (added after code-review + security-audit pass)

- **Chunked backfill:** the `active_slot` backfill uses `DB::table('appointments')->chunkById(500, ...)` instead of two unbounded `UPDATE ... WHERE` statements — safe against a production-sized table later, not just the current empty dev table.
- **Pre-flight duplicate check:** before adding the unique index, the migration groups existing non-cancelled appointments by `(staff_id, appointment_date, TIME(start_time))` and aborts with a `RuntimeException` listing the conflict count and details if any true duplicates are found — instead of letting `Schema::table()->unique()` fail with an opaque DB duplicate-key error. `TIME()` normalizes the comparison so legacy rows saved with inconsistent `start_time` precision (see the cast gotcha above) are still recognized as the same slot.
- **organization_id exclusion documented inline:** a comment next to the unique index explains why `organization_id` is NOT part of `appointments_staff_slot_unique`, unlike the general tenant-scoped-unique-constraint convention in `.claude/rules/migrations.md` — `staff_id` references the global `users` table, and a staff member shared across tenants genuinely cannot be double-booked at the same instant regardless of which org's calendar the appointment lives under.

## Filament admin UX (added after review)

`CreateAppointment`/`EditAppointment` now override `handleRecordCreation()`/`handleRecordUpdate()` to catch the `appointments_staff_slot_unique` `QueryException` (via `AppointmentService::isDoubleBookingViolation()`) and show a Filament danger notification instead of letting it bubble up as an uncaught exception. The underlying DB constraint prevented the double-booking either way — this is UX-only.

## Tests

- `tests/Feature/AppointmentDoubleBookingTest.php` — unique constraint + cancelled-appointment sanity checks (direct model), plus two controller-level tests reproducing a real single-threaded gap: a `completed` appointment is invisible to `validateAppointment()`'s conflict SELECT (only checks pending/confirmed), so the DB constraint is the only thing that catches it.
- `tests/Feature/AppointmentServiceTenantScopingTest.php` — cross-tenant `service_id` rejected, cross-tenant `staff_id` rejected, same-tenant accepted.
- `tests/Feature/BookingAdvanceBookingPerSlotTest.php` — tomorrow-afternoon slots returned when browsing during business hours today, for both the HTTP endpoint and the bulk/calendar path.

## Known follow-ups (not in scope for this fix)

- `AppointmentService::calculateAvailableSlotsForDay()`'s pre-existing TODO (Faza 5.7): appointments with `staff_id = null` (staff deleted) are invisible to the conflict check — orthogonal to this fix, not addressed here.
- Legacy `start_time`/`end_time` rows written before this fix may still have inconsistent raw precision (the migration's pre-flight check normalizes for detection purposes only — it doesn't rewrite existing data). Not a concern today since there's no populated staging/prod DB yet.

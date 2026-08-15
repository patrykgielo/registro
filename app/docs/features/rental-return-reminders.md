# Rental Return Reminders

**Implemented:** 2026-08-15 (`feature/rental-return-reminders`)

---

## Overview

Before this branch, a customer got no warning that a rental's return date
was approaching or had passed, and an admin got no automatic prompt before
overdue equipment became a support problem. `ProcessRemindersJob` exists,
is scheduled, and works — but it only ever queried `Appointment`
(`AppointmentStatus::Confirmed`/`Completed` over
`CONCAT(appointment_date,' ',start_time)`), with zero references to `Order`
or `OrderItem`. This closes that gap for the equipment-rental vertical with
two new reminders: **due soon** (day before `order_items.end_date`) and
**overdue** (after it).

---

## Design decision — parallel job, not an extension of `reminder_configs`

`ReminderConfig`/`ReminderLog` (appointment reminders) were the obvious
starting point to consider extending. Rejected, for reasons argued in full
below rather than assumed:

**What does `ReminderConfig` actually buy, and does a rental need it?**
It gives a tenant admin per-org control over timing, channel, and template
for a reminder. Appointments genuinely vary along all three axes across
industries. Rentals in this codebase are already fixed to email-only
("Email only, no SMS — rental orders are email-only per project spec", see
`order-notifications.md`), so the channel axis is moot from the start.
That leaves timing as the only knob extending `ReminderConfig` would
actually add — and the brief this feature was scoped against explicitly
excludes "operator-facing dashboards". `TemplateKey::reminderOptions()` is
already hardcoded to the three appointment reminder keys and used
exclusively by `ReminderConfigResource`; adding rental keys there means
either extending that admin UI (out of scope) or adding rows nobody can
ever see or edit, which is a fixed value with an admin-configurable
appearance — worse than a plain constant. Two fixed, code-defined
reminders correctly represents what was actually asked for.

**Migration cost, if extended anyway.** `reminder_logs.appointment_id` is
`bigint unsigned NOT NULL` with an index on
`(appointment_id, reminder_config_id)`. Making it nullable and adding a
sibling `order_item_id` column is a cheap migration. The real cost is
semantic, not mechanical: `ReminderConfig::getTimingDescription()`
hardcodes "przed wizytą"/"po wizycie" (Polish for "before/after the
appointment") into every row's human-readable description — every existing
call site and every future one would need a branch on which FK is set to
avoid describing a rental reminder as being about a "wizyta" (appointment).
That branch has to be threaded through the model, the Filament resource,
and anywhere else the description is displayed — for two reminders whose
timing is fixed anyway.

**Different source column shape.** `order_items.end_date` is a `date`
column, no time component. `ProcessRemindersJob`'s whole
hour/minute-offset + `window_buffer_minutes` mechanism exists to turn a
timestamp into a tolerant matching window; none of that applies to a
day-granularity value. Reusing the table would mean either padding
`trigger_hours`/`trigger_minutes` with meaningless values for rental rows,
or branching the query logic on which kind of config it is — both worse
than a second job with its own, much simpler day-based query.

**Decision: a parallel job** (`ProcessRentalReturnRemindersJob`), two new
`Notification` classes, no new tables. Dedup key, argued below, needed no
new `ReminderLog`-shaped table either.

### Dedup key — reusing `EmailService`, not a new `ReminderLog`

`message_key` on `email_sends` already carries a UNIQUE constraint, and
`EmailService::sendFromTemplate()` already implements exactly the "never
send the same reminder twice, including across a re-run after a partial
failure" semantics PR #141 built (`isRetryable()`: `sent`/`bounced` are
final, `failed` retries, `pending` > 15 min retries). Every other
Order-lifecycle notification in this codebase
(`OrderPaidNotification`/`OrderConfirmedNotification`/etc.) already relies
on this alone, with no `ReminderLog` involved — `ProcessRemindersJob`'s own
`ReminderLog` table predates that pattern and is specific to appointments'
SMS+email dual-channel bookkeeping. Adding a second `ReminderLog`-shaped
table for a single-channel, two-reminder-type feature would duplicate a
dedup mechanism that already exists and is already tested.

Concretely: `message_key = md5(template_key:recipient:metadata)`. Each
notification class puts its own dedup identity in `metadata`:

- **Due soon** — `order_item_id` + `end_date` (the item's own, at send
  time). Including `end_date` is deliberate: if `RentalExtensionService`
  moves the item's `end_date` out after a due-soon reminder already went
  out, the customer would otherwise never hear about the new date — a
  stale reminder would be the last thing they got. Keying on the date too
  means a changed date earns a fresh reminder for free, with no explicit
  "was this item extended" check anywhere.
- **Overdue** — `order_item_id` only, no date. See the "one overdue
  notice" decision below for why this is deliberately different from
  due-soon's key.

No `ShouldBeUnique` on either notification class — per
`.claude/rules/notifications.md`, it is inert on `Notification` subclasses
in this Laravel version; adding it would suggest protection that does not
exist.

### Reminder unit: order item, not order

A single order can carry items with different `end_date` values — cart
checkout lets each item pick its own rental period
(`CartService::addItem(Cart $cart, Service $service, Carbon $start, Carbon $end, ...)`),
and `RentalExtensionService::approve()` can move one item's `end_date`
independently of the rest of the order. `order_items.end_date` is the
event this feature reminds about, per item — not a single "order return
date" computed by taking the max across items. Consequence: a multi-item
order with items due on different days sends each item its own reminder
on its own day; two items due the same day on the same order each get
their own email rather than one combined one. This is a deliberate
simplification (matches the requested scope: two reminder *types*, not a
cap on emails per multi-item order), not an oversight — revisiting it
later to consolidate same-day items into one email is a UX improvement,
not a correctness fix.

### "One overdue notice, not a repeating one"

An overdue item is re-matched by the job's query on every run for as long
as the order stays `in_progress` (there is no upper bound and no
"completed" filter to stop it — that only happens via admin action marking
the order returned). If the overdue reminder repeated on every run, a
customer who ignores it would get a daily email forever, which is a
support/spam problem, not a useful feature — and this branch was
explicitly scoped to exclude late fees, automatic charges, and anything
resembling a dunning/escalation system. The dedup key (`order_item_id`
only, no date) makes "fires once, ever, per item" the natural behavior of
the existing `EmailService` mechanism, not a special case bolted onto it:
once `sent`, `isRetryable()` returns `false` and every subsequent run's
`sendFromTemplate()` call for that same item silently no-ops. Pinned by
`ProcessRentalReturnRemindersJobTest::test_overdue_reminder_does_not_repeat_on_subsequent_days()`,
which advances the clock across several simulated days and asserts the
email count never grows past 1.

The due-soon query is `end_date < today` (not `end_date = yesterday`)
deliberately: an item the job missed by a day (downtime, a failed run)
still gets exactly one overdue notice on the next run, rather than
silently never getting one.

---

## Files

**New:**
- `app/Notifications/RentalReturnDueSoonNotification.php`
- `app/Notifications/RentalReturnOverdueNotification.php`
- `app/Jobs/Reminder/ProcessRentalReturnRemindersJob.php`
- `database/migrations/2026_08_14_160000_seed_rental_return_reminder_email_templates.php`
  — production data migration, same reason and pattern as
  `2026_08_12_120000_seed_order_handover_return_email_templates.php`: an
  already-provisioned tenant never runs `EmailTemplateSeeder` again.
- `tests/Feature/Jobs/Reminder/ProcessRentalReturnRemindersJobTest.php`
- `tests/Feature/Database/RentalReturnReminderEmailTemplateMigrationTest.php`

**Modified:**
- `app/Enums/TemplateKey.php` — `RENTAL_RETURN_DUE_SOON`, `RENTAL_RETURN_OVERDUE`.
  Deliberately NOT added to `TemplateKey::reminderOptions()` — see the
  design decision above.
- `database/seeders/EmailTemplateSeeder.php` — 4 templates (2 keys × 2 languages),
  dev/test only (see "Existing-tenant provisioning" note below).
- `routes/console.php` — `Schedule::job(new ProcessRentalReturnRemindersJob)->dailyAt('09:00')`.
  Daily, not hourly: the source column has no time-of-day component, so
  hourly polling buys nothing appointments' timestamp-based reminders need it for.

**Not touched:** `app/Jobs/Reminder/ProcessRemindersJob.php`,
`app/Models/ReminderConfig.php`, `app/Models/ReminderLog.php`,
`app/Filament/Resources/ReminderConfigResource.php`. Appointment reminders'
behaviour is unchanged — confirmed by an empty `git diff` against those
files, not by a test suite, since none currently exists for
`ProcessRemindersJob` (searched at the start of this branch; there isn't
one to point to as "still green").

---

## Existing-tenant provisioning (why there's a data migration too)

Same mechanism as `order-notifications.md`'s "Existing-tenant provisioning"
section: `EmailTemplateSeeder` runs exactly once per stack, at first-tenant
provisioning (`ProvisionTenantCommand::runGlobalSeedersOnce()`, gated by
`TenantProvisioningState`). An already-provisioned stack — including UAT's
`budowlana` — never runs it again, so a key added only to the seeder means
that stack's first reminder attempt fails with "template not found"
straight into `failed_jobs`, unmonitored. The migration uses
`insertOrIgnore()` with explicit `organization_id => null`, so it only ever
inserts the two new global rows and never touches an existing row
(including a tenant's own override, should one already exist); `down()`
mirrors that, deleting only `key IN (...) AND organization_id IS NULL`.

---

## Template Variables

| Key | Variables |
|-----|-----------|
| `rental-return-due-soon` | `customer_name`, `order_number`, `service_name`, `return_date`, `orders_url`, `app_name` |
| `rental-return-overdue` | `customer_name`, `order_number`, `service_name`, `return_date`, `days_overdue`, `orders_url`, `app_name` |

`app_name` is read via `app(SettingsManager::class)->appName()` inline in
the notification's `toEmailService()` — same call, same queue-safety
profile, as the three existing Order notifications that already do this
(`OrderPaidNotification`, `OrderHandedOverNotification`,
`OrderReturnedNotification`). Not re-verified further here; if that call's
queue-safety is ever revisited, it should be revisited for all four
call sites together, not just these two.

---

## Known pre-existing gap, found and NOT fixed here (out of scope)

`ProcessRemindersJob`'s appointment path (`findAppointmentsForConfig()`)
has no explicit tenant filter at all — it matches every enabled
`ReminderConfig` (one org each) against every `Appointment` platform-wide,
because `BelongsToOrganization`'s global scope is a complete no-op in
console context
(`if (app()->runningInConsole() && ! app()->runningUnitTests()) return;`,
and a queued job's `handle()` runs in exactly that context). Concretely:
tenant A's SMS reminder config can, in principle, match and send to tenant
B's appointment customers. This job (`ProcessRentalReturnRemindersJob`)
does not have this problem — it has no per-tenant `ReminderConfig` row to
mismatch against in the first place; each `Order` carries its own
`organization_id` and the query never joins across tenants. Found while
verifying this branch's own tenant-scoping test
(`ProcessRentalReturnRemindersJobTest::test_tenant_scoping_never_crosses_reminders_between_organizations()`)
against the sibling job for comparison; reported here rather than fixed,
since it is a pre-existing bug in unrelated code (`ProcessRemindersJob`),
not something this branch introduced or touches.

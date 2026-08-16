---
name: project_stale_seeder_comments_2026-08-16
description: PR #192 (seed reference data once per process) left 8 test-file comments and 3 rules/docs references quoting the old TestCase::setUp()-seeds-every-test mechanism — fixed, plus what checking test validity actually found
metadata:
  type: project
---

Follow-up to [[project_seed_once_per_process_2026-08-16]]. After that PR landed, a grep for
`TestCase::setUp()` (and Polish variants) turned up 11 stale mechanism-description comments: the 8
in `tests/**` the user already knew about, plus 3 more found by widening the grep to
`.claude/rules/**` and `app/docs/**`: `.claude/rules/spatie-roles.md` (twice, inside the
`assignRole()` guard's own root-cause explanation), `app/docs/features/order-notifications.md`, and
`.claude/rules/tests.md`'s own "MySQL 8.0 gate" section (the `Mockery::close()`-before-
`parent::tearDown()` mechanism write-up, which used the old seeding behavior to explain why a leak
cascaded to "every RefreshDatabase test in the run"). Fixed all of them. Deliberately did NOT touch
`.claude/rules/ci-cd-troubleshooting.md`'s own copy of the same story — it's an explicitly dated
incident chronicle ("Incydent 2026-08-15", i.e. before the 2026-08-16 mechanism change) that already
defers full mechanism detail to `tests.md`, not a live "how it works today" reference.

**Why `tests.md`'s MySQL section needed more than a find-replace:** the old claim wasn't just "wrong
call site" — it was substantively true only under the old mechanism. Under per-test `setUp()`
seeding, EVERY RefreshDatabase test's transaction wrote to `permissions.name='settings.manage'`
(the first row `RolePermissionSeeder` touches), so a leaked transaction from the
`Mockery::close()`-first anti-pattern reliably collided with the next test that reached its own
seeding — that's why the incident cascaded across unrelated classes. Under once-per-process
seeding, most tests never touch that row at all, so a leak today only blocks tests that happen to
touch whatever rows the *leaking* test's own body touched — a narrower, less universal blast
radius. Kept both the historical fact (dated, in parentheses) and the corrected present-tense
claim; the underlying rule ("never call `Mockery::close()` before `parent::tearDown()`") didn't
change, only the specific "why it cascades everywhere" story did.

**Per-file check of "did the mechanism switch weaken what the test actually verifies"** (all 8, as
asked) — verdict: **no**, in all 8 cases. The reference-data row(s) each test depends on are
guaranteed present by the time any test body runs either way (seeded once at bootstrap, before the
first transaction, vs. re-seeded per test) — every RefreshDatabase test's own transaction starts
from that same already-seeded baseline and rolls back to it, so per-test isolation is unaffected.
Tests that explicitly delete/mutate rows first (`CreateOwnerCommandTest`'s role wipe,
`OrderPaidPickupHtmlSeparatorMigrationTest`'s `revertGlobalRowToGluedContent()`) do so inside their
own transaction and get the pristine baseline back next test regardless of seeding cadence. Tests
that wipe `email_templates` entirely and replay specific migration files directly
(`OrderHandoverReturnEmailTemplateMigrationTest`, `RentalReturnReminderEmailTemplateMigrationTest`)
never depended on seeding timing to begin with.

**One independent, pre-existing test-quality issue found while checking, unrelated to PR #192:**
`OrderPaidPickupHtmlSeparatorMigrationTest::test_up_is_a_no_op_when_the_row_already_has_the_fixed_content()`
does `migrate:rollback` then `migrate` and asserts content is unchanged — but `down()` actively
mutates the row back to glued content first, so the `up()` that follows is a real, content-changing
call, not a no-op. What the assertion actually proves is that the `down()`/`up()` pair round-trips
back to identical content — a legitimate, useful assertion, just mislabeled. The genuine no-op (this
migration's `up()` finding no matching row because `EmailTemplateSeeder` hasn't run yet at raw
migration time) already happens once, silently, as part of `RefreshDatabase`'s initial `migrate:fresh`,
and isn't independently re-observable from inside a test body. Fixed the comment to say this
honestly; did not rename the test method or otherwise change its behavior — this predates and is
orthogonal to the seeding-mechanism change, so it wasn't in scope to silently "fix" per
[[feedback_never_weaken_a_test]]-style caution (ask first if a test's own design looks wrong).

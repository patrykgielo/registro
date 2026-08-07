---
name: feedback-tinker-dev-db-safety
description: Empirical security testing via `docker compose exec app php artisan tinker` hits the real dev MySQL, not a sandbox — never query "first user" or mutate roles without verifying identity first
metadata:
  type: feedback
---

`php artisan tinker` in this repo's Docker setup runs against the live dev
MySQL database (same one referenced in `deployment.md`'s RefreshDatabase
incident) — it is NOT sandboxed like `php artisan test` (which is protected
by `.env.testing` → SQLite). Any `User::factory()->create()`, `assignRole()`,
or `forceDelete()` call in a tinker one-liner is a real, committed mutation
against dev data.

**Why:** During the `feature/user-role-escalation-guard` audit
(2026-08-07), a test harness did `User::where('id','>',0)->first()` to get
"an admin actor" and called `assignRole('admin')` on it without checking who
it was first. It was `admin@registro.local` (id 227), the real seeded
super-admin dev account — its role set became `super-admin,admin` instead of
just `super-admin`. This also produced a false test result: the guard
appeared to fail-open on a "mixed nested array" bypass attempt, when actually
the actor already held `super-admin` from the earlier mutation, so the guard
was correctly *allowing* it. Caught and reverted (`removeRole('admin')`)
before drawing conclusions, and the test was rerun with a properly isolated
`User::factory()->create()` actor to get the real (negative) result.

**How to apply:** When empirically testing auth/role logic against the dev
DB via tinker in this repo:
1. Always create a **fresh** `User::factory()->create()` for the test actor —
   never `User::first()` / `User::where(...)->first()` against the real
   table.
2. Print the actor's id/email/roles immediately after creating it, before
   trusting any assertion about its permissions.
3. `forceDelete()` (or role-revert) the actor at the end of the script, in
   the same tool call if possible — don't leave cleanup for "later."
4. If a script errors out mid-way (e.g. FK violation), verify with a
   follow-up query whether the mutation actually committed or rolled back —
   don't assume.
5. Before finishing an audit that touched the dev DB, do a final sweep:
   `User::where('created_at', '>=', now()->subHours(N))->get(['id','email'])`
   to catch any stray factory rows and delete them.

See also `[[project_registro_role_escalation_guard]]` for the audit this
lesson came from.

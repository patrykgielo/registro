---
name: feedback-pendingcommand-lazy-execution
description: Laravel's PendingCommand ($this->artisan(...)) only actually runs the command on __destruct() — assigning it to a variable defers execution past any DB assertions written right after
metadata:
  type: feedback
---

`$this->artisan('some:command', [...])` returns `Illuminate\Testing\PendingCommand`. Calling
`->assertSuccessful()`/`->assertExitCode()` on it does **not** execute the command — those methods
only record an expectation and return `$this`. The command actually runs inside `run()`, which is
called from `__destruct()` if it hasn't already fired.

**Why this matters:** `$this->runCommand()->assertSuccessful();` as a single unassigned statement
works, because the temporary object's refcount drops to zero at the end of the statement and PHP
destructs it immediately — the command runs right there. But:

```php
// BROKEN — command has not run yet at the point of the DB query below
$result = $this->runCommand();
$result->assertSuccessful();
$org = Organization::where('slug', 'acme')->firstOrFail();  // ModelNotFoundException
```

`$result` stays alive (still referenced by the variable) until the end of the test method, so
`__destruct()` — and the actual command execution — doesn't fire until then.

**How to apply:** never assign a `PendingCommand` to a variable if you need to inspect DB state or
side effects afterward in the same test. Either chain everything in one statement
(`$this->artisan(...)->assertSuccessful();`), or force execution explicitly with `->run()`
before doing anything that depends on the command having completed. Found while adding tests to
`ProvisionTenantCommandTest` (`app/Console/Commands/ProvisionTenantCommand.php`) — the first attempt
at a "mail failure doesn't fail the command" test failed with a misleading `ModelNotFoundException`
that had nothing to do with the actual bug being tested.

---
name: hardening-needs-stateful-volume
description: cap_drop/cap_add verification for any service with a named volume must be tested against a volume with real prior activity, not just a fresh one
metadata:
  type: feedback
---

When verifying `cap_drop: ALL` + selective `cap_add` for a containerized service that owns a named
Docker volume (redis, mysql, any future stateful service) — a fresh/never-used volume is NOT a
valid stand-in for "the real thing on a live host". Docker seeds a brand-new named volume from the
image's own filesystem content at that path, so it usually already has the right ownership baked
in from the image layer. A volume with real history can have directories the SERVICE ITSELF created
at runtime (e.g. redis's `appendonlydir/` under this project's own `umask 0077`, mode 0700) that a
privileged-but-capability-dropped root process can no longer traverse without the specific
capability (`CAP_DAC_OVERRIDE`) that bypasses DAC permission checks.

**Why:** `v0.13.0-rc12` shipped `cap_drop: ALL` + `cap_add: [SETUID, SETGID]` on redis, verified only
against a fresh volume — passed cleanly. Deployed to UAT (13 days of real data), redis crashlooped
on `find: ./appendonlydir: Permission denied`, taking `app`/`horizon`/`nginx`/`scheduler` down with
it (Compose `depends_on: condition: service_healthy`). Full incident: `ci-cd-troubleshooting.md` →
"Incydent 2026-08-15", fixed in PR #189.

**How to apply:** for any future hardening review of `docker-compose.prod.yml` (or its per-tenant
variant), build the test volume by actually running the service unhardened first, exercising it
(write real data, force it to create whatever runtime directories it creates), THEN re-run hardened
against that same volume. A directory made by `mkdir`+`echo` inherits the TEST SHELL's umask, not
the real entrypoint's — it will not reproduce a permission-bit bug even if one exists. Also worth
testing the SAME capability-removal exercise for the "obviously fine" services (nginx's `CHOWN`,
mysql's set) — don't assume a comment claiming "verified" without re-running it; twice in this
review the existing comment turned out correct, but only after independently reproducing it (see
[[stop-round-trip-copies]] pattern — same discipline: run the real path, not a description of it).

See also `docker-compose.prod.yml`'s own redis/app/nginx service comments and
`app/docs/deployment/tenant-compose-stack.md` → "The gap this table's own methodology had" for the
full technical writeup (kernel `chown_common()`/`CAP_DAC_OVERRIDE` semantics, why `CHOWN` was
deliberately NOT added for redis despite the entrypoint calling `chown`).

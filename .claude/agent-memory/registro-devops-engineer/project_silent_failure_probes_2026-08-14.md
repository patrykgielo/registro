---
name: project-silent-failure-probes
description: cert-expiry probe + backup dead-man's-switch built on feature/silent-failure-probes (2026-08-14) -- not yet merged, not yet run on a real server
metadata:
  type: project
---

Built at the team lead's request (branch `feature/silent-failure-probes`, cut from green develop,
2026-08-14): two mechanisms for failures this deployment used to swallow silently.

1. **`scripts/server/check-certificate-expiry.sh`** (new). Connects to the SERVED cert on
   `127.0.0.1:443` via `openssl s_client` (not the disk file -- deliberately, to also catch a renewed-
   but-never-reloaded cert, the same shape of bug as the nginx-inode incident). WARN ≤30 days, CRIT
   ≤14. Silent/exit-0 on clean, log+stdout on a finding (tenant-check.sh's own convention, not
   apply.sh's status-file one). Optional `REGISTRO_CERT_ALERT_URL` webhook, no vendor named, inert
   when unset, fires only on a finding. Installed automatically by `setup-production-server.sh`
   (daily cron, root, `/etc/cron.d/registro-certificate-expiry`) for any FUTURE run of that script --
   UAT already exists and was NOT touched, so this cron is not live on `srv1342834.hstgr.cloud` yet;
   operator steps for manual install are in `instalacja-tenanta-od-zera.md` step 0.4a.

2. **Dead-man's-switch in `scripts/server/tenant-backup.sh`**. Optional per-tenant
   `BACKUP_HEALTHCHECK_URL`, read from `.env.bak-manual` (see
   [[env-bak-manual-escape-hatch]]), pinged only after a FULLY successful backup (placement after the
   existing `FILES_FAILED` gate, not a new condition -- proved via revert-and-retest). Ping failure
   never fails the backup, bounded `--max-time 10`.

Both proven real, not just via fakes: cert probe run against a real `openssl s_server` + throwaway
self-signed certs for healthy/critical/unreachable before any test was written. 9 new cases in
`tests/shell/cases/` (20-28), suite now 28/28 (`bash tests/shell/run.sh`, ~6.3s).

**Nothing here was ever run against the live UAT server** -- no SSH this session. Unverified: whether
the probe's chosen user (root, matching sync-certificate.sh/tenant-check.sh) can actually read
`/var/www/registro/.env` on that specific machine's real permissions; real edge/legacy nginx behavior
under SNI vs `openssl s_server`; certbot's actual renewal-timer health on that machine (the exact
thing this probe exists to eventually catch).

# Domain migration: srv1342834.hstgr.cloud → registrolabs.com

Performed 2026-08-08 on the live installation. This is the record of what was done, what
bit us, and how to reverse it.

`srv1342834.hstgr.cloud` remains the VPS's own hostname and is still what `ssh` connects to.
It is no longer the application domain. The two are unrelated and change independently.

## Why the domain changed at all

The old hostname is a record inside Hostinger's `hstgr.cloud` zone. We could not publish
`_acme-challenge` TXT records there, so a wildcard certificate was unobtainable and every new
tenant subdomain had to be added to a multi-SAN certificate by HTTP-01. `registrolabs.com` is a
domain we own; the zone is ours. A wildcard is now technically obtainable — see
`ListTenantHostnamesCommand`'s docblock for why it is still not implemented.

## What was done, in order

1. **DNS** (Hostinger panel, TTL 60 during the switch):
   `A`/`AAAA` on the apex and on `*`, all four pointing at the VPS. The wildcard is what makes a
   new tenant's subdomain resolve without touching DNS per client.
2. **Verified both protocols before spending a validation attempt.** Let's Encrypt prefers IPv6
   when an `AAAA` record exists, so a broken v6 path fails issuance while the site looks perfect
   over v4. Confirmed nginx answers on `[::]:80` and that the new name resolves from the
   authoritative nameserver and from public resolvers.
3. **`certbot --dry-run` first.** Dry runs hit the staging environment and cost nothing; a failed
   real validation burns one of five attempts per hour.
4. **Issued** `registrolabs.com` + `www.registrolabs.com`, `--cert-name registrolabs.com` chosen
   to match `CERT_DIR` so `sync-certificate.sh` adopts the lineage with no script change.
5. **`.env`**: `APP_URL`, `APP_DOMAIN`, `CERT_DIR`, `MAIL_FROM_ADDRESS`. A timestamped backup was
   taken first.
6. **Re-rendered** `app.prod-tls.local.conf` from its template with the new `CERT_DOMAIN`, and
   checked that no unsubstituted placeholder survived.
7. **`nginx -t` before any reload.** The gate, not a formality.
8. Recreated the application containers, then the nginx container.
9. Verified HTTPS end to end on IPv4 and IPv6, and read back the certificate actually being
   served rather than assuming.

## The two traps, both of which cost real time

**Editing `.env` does not change a running container.** Compose interpolates `environment:` at
container *creation*, and in Laravel an OS-level environment variable outranks the `.env` file.
After changing `.env` and running `config:cache`, `php artisan tenants:hostnames` still printed
the old domain. The containers had to be recreated. This is the same precedence that destroyed
the dev database in the 2026-03-17 incident, arriving from the other direction.

**`nginx -s reload` served a week-old config.** The nginx config is bind-mounted as a *single
file*. The container holds that file's inode, so once the file was rewritten the container still
saw the previous version and dutifully reloaded it. Only `--force-recreate` on the nginx
container picked up the new content.

This one is worth internalising: `nginx -t` passed, the reload reported success, and the site
answered — while still presenting the **old certificate**. Nothing in the success path was false;
it was all describing the stale file. The only thing that caught it was reading back the
certificate actually being served, from outside, with `openssl s_client`.

**Rule of thumb from both:** after changing anything a container consumes, verify the *effect*
from outside the container, not the *command's* exit status.

## Rollback

The old certificate is untouched at `/etc/letsencrypt/live/srv1342834.hstgr.cloud/`. To revert:
restore the `.env` backup (or set `APP_DOMAIN`/`CERT_DIR` back), re-render
`app.prod-tls.local.conf` with the old `CERT_DOMAIN`, `nginx -t`, then recreate the nginx and app
containers. DNS for the old hostname was never touched, so it resolves throughout.

## Known gaps left open

- **`www` was nearly dropped on the next cron run.** `sync-certificate.sh` built its name list
  solely from `tenants:hostnames`, which by design emits tenants only and never `www`. It now adds
  `www.$APP_DOMAIN` — but only if the name resolves, because Let's Encrypt fails the whole request
  when any single name fails validation. Nobody noticed before because
  `www.srv1342834.hstgr.cloud` never existed.
- **Mail is not migrated.** `MAIL_FROM_ADDRESS` now says `noreply@registrolabs.com`, but the
  transport is still a personal Gmail account, which cannot legitimately send as a custom domain —
  DKIM for one's own domain requires Workspace. SPF, DKIM and DMARC are therefore not configured,
  and mail deliverability from the new domain is no better than before. Choosing a transactional
  provider is the open decision.
- **Tenant subdomains** are added to the certificate by the cron reconcile, so a newly provisioned
  tenant has up to 15 minutes without a valid certificate. Unchanged by this migration; the
  wildcard would close it.

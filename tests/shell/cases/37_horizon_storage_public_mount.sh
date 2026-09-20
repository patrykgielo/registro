#!/bin/bash
###############################################################################
# ClickUp 123k99ct3za: docker-compose.prod.yml mounted storage-app-public on
# `app` and `nginx` but NOT `horizon` or `scheduler` -- a comment on the
# volumes: block called this a deliberate, unresolved asymmetry. It stopped
# being harmless once EmailBrandedLayout::wrap() -> SettingsManager::
# emailBrandingFor() -> extractFilePath()/validateFilePath() started calling
# Storage::disk('public')->exists() from inside a queued notification:
# EmailService::sendFromTemplate() runs entirely inside `horizon`, never
# `app`. Without the mount, `exists()` is always false (the path simply is
# not there in that container's filesystem) -- no exception, no log line,
# just a silently text-only email header. `scheduler` was checked separately
# (routes/console.php grep, documented in the volumes: block comment) and
# genuinely does not need it: every Schedule::job(...) that renders branded
# mail actually executes inside `horizon` (queued), and every
# Schedule::command(...) that runs inline in `scheduler` was grepped for
# EmailService/notify() usage with zero hits.
#
# WHY A REAL VOLUME, NOT A GREP ON THE YAML: a text match on
# "storage-app-public" would pass the instant someone typed the volume name
# in a comment, without proving a container mounted at that exact path can
# actually SEE a file `app` wrote there. Same reasoning as case 19 (nginx),
# case 30 (MySQL 8 JSON operator) and case 31 (redis hardening) in this same
# suite -- decide by running a real container against a real volume, not by
# reading text.
#
# WHY read-only, not read-write like `app`: grepped every ShouldQueue job
# under app/Jobs/** for a Storage::disk('public') write (put/putFile/
# makeDirectory/delete) -- zero hits; every upload goes through the Filament
# panel in `app`, the volumes: block's documented sole writer. Read-only is
# strictly safer than matching `app`'s rw mode and is asserted here as a
# negative control: a write attempt through horizon's own mount must fail.
###############################################################################
set -uo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/../lib/harness.sh"
test_start "docker-compose.prod.yml: horizon can read (not write) storage-app-public; scheduler still mounts nothing"

COMPOSE_FILE="$REPO_ROOT/docker-compose.prod.yml"

# --- extract the real horizon/scheduler/app service blocks, never
# hardcoded -- same top-level-key-anchored awk pattern as case 31, needed
# here too: `depends_on:` under `app` lists `redis`/`mysql` at deeper
# indentation, and `scheduler`/`horizon` both appear as bare words inside
# other comments in this file. ------------------------------------------------
service_block() {
    local svc="$1"
    awk -v key="^  ${svc}:[ \t]*\$" '
        $0 ~ key { r = 1; next }
        r && /^  [a-zA-Z]/ { exit }
        r { print }
    ' "$COMPOSE_FILE"
}

HORIZON_BLOCK="$(service_block horizon)"
[ -n "$HORIZON_BLOCK" ] || fail "could not extract the horizon service block from $COMPOSE_FILE"

SCHEDULER_BLOCK="$(service_block scheduler)"
[ -n "$SCHEDULER_BLOCK" ] || fail "could not extract the scheduler service block from $COMPOSE_FILE"

# --- static assertions on the extracted text: exact volume/target/mode,
# and scheduler's continued, DELIBERATE absence of any storage-* mount. -----

HORIZON_MOUNT_LINE="$(printf '%s\n' "$HORIZON_BLOCK" | grep -F 'storage-app-public:' || true)"
[ -n "$HORIZON_MOUNT_LINE" ] || fail "horizon has no storage-app-public mount at all -- the exact bug this case pins"
assert_contains "$HORIZON_MOUNT_LINE" "storage-app-public:/var/www/storage/app/public:ro" \
    "horizon's storage-app-public mount (volume name, target path, and :ro mode all must match)"

if printf '%s\n' "$SCHEDULER_BLOCK" | grep -qF 'storage-'; then
    fail "scheduler now mounts a storage-* volume -- routes/console.php must be re-grepped for a Schedule::command() that renders branded mail inline before this is added; update the comment on the volumes: block accordingly, don't just silence this assertion"
fi

# --- real volume, real containers: prove the mount actually grants read
# access to a file `app` would have written, and does NOT grant write
# access through horizon's own (deliberately read-only) mount. --------------

VOL="cc37-storage-app-public-$$"
docker volume rm "$VOL" >/dev/null 2>&1 || true
docker volume create "$VOL" >/dev/null

cleanup() {
    docker rm -f cc37-seed cc37-horizon-ro cc37-no-mount >/dev/null 2>&1 || true
    docker volume rm "$VOL" >/dev/null 2>&1 || true
}
trap cleanup EXIT

# Seed the volume the way `app` actually would: a real file at the real
# sub-path a tenant logo upload lands on (branding/logos/... per
# SettingsManager's own appearance.header_logo convention).
docker run --rm --name cc37-seed -v "$VOL:/data" alpine:3 \
    sh -c 'mkdir -p /data/branding/logos && echo fake-png-bytes > /data/branding/logos/tenant1.png' >/dev/null

# (1) Negative control -- reproduce the ORIGINAL bug exactly: a container
# with NO mount at all (horizon's state before this fix) cannot see the
# file. This is the silent-failure symptom itself: Storage::disk('public')
# ->exists() would return false, not throw.
NO_MOUNT_OUT="$(docker run --rm --name cc37-no-mount alpine:3 \
    sh -c 'test -f /var/www/storage/app/public/branding/logos/tenant1.png && echo FOUND || echo MISSING' 2>&1)"
assert_eq "MISSING" "$NO_MOUNT_OUT" "container with no storage-app-public mount (horizon's state before this fix) must not see the logo -- this is the exact silent-failure symptom"

# (2) The fix: horizon's actual mount (extracted target path + :ro) can read
# the same file `app` wrote.
READ_OUT="$(docker run --rm --name cc37-horizon-ro \
    -v "$VOL:/var/www/storage/app/public:ro" alpine:3 \
    sh -c 'cat /var/www/storage/app/public/branding/logos/tenant1.png' 2>&1)"
assert_eq "fake-png-bytes" "$READ_OUT" "horizon's real mount (target path + mode extracted from docker-compose.prod.yml) must read the logo app wrote"

# (3) The mode: read-only actually blocks a write through horizon's own
# mount -- proves :ro isn't a no-op and horizon can never become a second
# writer to the volume nginx serves read-only.
WRITE_OUT="$(docker run --rm --name cc37-horizon-ro \
    -v "$VOL:/var/www/storage/app/public:ro" alpine:3 \
    sh -c 'echo nope > /var/www/storage/app/public/branding/logos/new.png' 2>&1)"
assert_contains "$WRITE_OUT" "Read-only file system" "horizon's mount must be read-only -- a write through it must fail"

test_finish

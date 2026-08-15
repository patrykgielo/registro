#!/bin/bash
###############################################################################
# Pins: .github/workflows/deploy-production.yml's test gate ran mariadb:10.11
# while docker-compose.prod.yml (production's real engine) runs mysql:8.0 --
# the gate was testing against an engine that was never, at any point,
# actually deployed to. `deploy-production.yml` had exactly one run in its
# entire history (dispatched 2026-08-15, first-ever real execution of a
# 300-line file nobody had run before) and it failed here:
# `2026_06_16_100002_add_analytics_virtual_columns` uses MySQL 8's `->>`
# JSON operator in a `GENERATED ALWAYS AS (...)` virtual column, which
# MariaDB 10.11 rejects with "You have an error in your SQL syntax ... near
# '>>'". The migration was correct for the engine actually deployed to; the
# test gate's engine choice was wrong.
#
# WHY A REAL CONTAINER, NOT A GREP ON THE YAML: a text match on `image:
# mysql` would pass the instant someone typed the right string, without ever
# proving MySQL 8 actually accepts the syntax the migration needs -- exactly
# the class of false confidence tests.md warns against ("a grep passes even
# when the behavior is broken"). This case instead (1) reads the ACTUAL image
# tags out of the real deploy-production.yml and docker-compose.prod.yml
# (never hardcoded, so a future edit to either file is picked up automatically
# without touching this test), and (2) runs the exact virtual-column SQL from
# the real migration file against a real container of whatever engine the
# workflow currently declares.
#
# NEGATIVE CONTROL: the same SQL is also run against a real mariadb:10.11
# (hardcoded on purpose -- this leg exists to prove the SQL is genuinely
# engine-discriminating, not to track the workflow file) and MUST fail. This
# is what makes red-then-green possible: point WORKFLOW_FILE at a copy with
# mariadb:10.11 substituted back in and the "runs against the workflow's
# declared engine" leg fails the same way the negative control does --
# proving this case would have caught the original bug, not just that it
# accepts today's fix.
#
# COST: two real DB containers (~10-20s including health-wait), matching the
# precedent already accepted in 19_nginx_no_hardcoded_proxy_upstream.sh for
# the same reason -- the property is not decidable from the file's text.
###############################################################################
set -uo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/../lib/harness.sh"
test_start "deploy-production.yml's DB test-gate engine actually accepts what production's engine accepts"
sandbox_init

WORKFLOW_FILE="$REPO_ROOT/.github/workflows/deploy-production.yml"
COMPOSE_FILE="$REPO_ROOT/docker-compose.prod.yml"

# --- extract the real values, never hardcoded -----------------------------

# Anchored on EXACT indentation of the top-level service key, not a bare
# `\s*redis:` -- both files also have a `redis:` line nested under a
# `depends_on:` block (docker-compose.prod.yml's `app` service depends on
# both `mysql:` and `redis:`), at a DEEPER indentation than the service
# definition itself. A loose match hits that first and then grabs the NEXT
# `image:` line in the file, which belongs to a different service --
# reproduced while writing this case (matched depends_on's `redis:` in
# docker-compose.prod.yml, then reported mysql's image as redis's).
# mawk (this environment's /usr/bin/awk) has no \s -- POSIX bracket classes
# ([ \t]) work on every awk implementation, \s is a GNU/perl-only extension.
WORKFLOW_DB_IMAGE="$(awk '
    /^      (mysql|mariadb):[ \t]*$/ { m = 1; next }
    m && /^[ \t]*image:/ { print $2; exit }
' "$WORKFLOW_FILE")"

WORKFLOW_REDIS_IMAGE="$(awk '
    /^      redis:[ \t]*$/ { r = 1; next }
    r && /^[ \t]*image:/ { print $2; exit }
' "$WORKFLOW_FILE")"

PROD_DB_IMAGE="$(awk '
    /^  mysql:[ \t]*$/ { m = 1; next }
    m && /^[ \t]*image:/ { print $2; exit }
' "$COMPOSE_FILE")"

PROD_REDIS_IMAGE="$(awk '
    /^  redis:[ \t]*$/ { r = 1; next }
    r && /^[ \t]*image:/ { print $2; exit }
' "$COMPOSE_FILE")"

[ -n "$WORKFLOW_DB_IMAGE" ] || fail "could not extract a DB service image from $WORKFLOW_FILE"
[ -n "$PROD_DB_IMAGE" ] || fail "could not extract mysql's image from $COMPOSE_FILE"
[ -n "$WORKFLOW_REDIS_IMAGE" ] || fail "could not extract redis's image from $WORKFLOW_FILE"
[ -n "$PROD_REDIS_IMAGE" ] || fail "could not extract redis's image from $COMPOSE_FILE"

case "$WORKFLOW_DB_IMAGE" in
    mysql:*) ;;
    *) fail "workflow's DB service image is '$WORKFLOW_DB_IMAGE', not mysql:* -- production (docker-compose.prod.yml) runs $PROD_DB_IMAGE" ;;
esac

assert_eq "$PROD_DB_IMAGE" "$WORKFLOW_DB_IMAGE" "DB engine: workflow vs production"
assert_eq "$PROD_REDIS_IMAGE" "$WORKFLOW_REDIS_IMAGE" "redis engine: workflow vs production"

# --- the real SQL, extracted from the real migration, not retyped ---------

MIGRATION="$REPO_ROOT/database/migrations/2026_06_16_100002_add_analytics_virtual_columns.php"
[ -f "$MIGRATION" ] || fail "expected migration missing: $MIGRATION"

# The virtual-column DDL lives inside a PHP heredoc-less string; pull the
# ALTER TABLE statement out verbatim rather than retyping it, so a future
# edit to the real migration is exercised by this case automatically.
DDL="$(awk '
    /ALTER TABLE analytics_events$/ { f = 1 }
    f { print }
    f && /VIRTUAL$/ { exit }
' "$MIGRATION")"
[ -n "$DDL" ] || fail "could not extract the virtual-column ALTER TABLE out of $MIGRATION"

# Start both containers up front so the two ~5-10s boot/health waits overlap
# instead of stacking serially.
docker rm -f cc30-workflow-db cc30-mariadb-control >/dev/null 2>&1 || true
docker run -d --name cc30-workflow-db -e MYSQL_ROOT_PASSWORD=secret -e MYSQL_DATABASE=registro_test "$WORKFLOW_DB_IMAGE" >/dev/null 2>&1
docker run -d --name cc30-mariadb-control -e MYSQL_ROOT_PASSWORD=secret -e MYSQL_DATABASE=registro_test mariadb:10.11 >/dev/null 2>&1

cleanup() { docker rm -f cc30-workflow-db cc30-mariadb-control >/dev/null 2>&1 || true; }
trap 'cleanup; rm -rf "$SANDBOX"' EXIT

wait_ready() {
    local name="$1" i
    for i in $(seq 1 30); do
        docker exec "$name" mysqladmin ping -h127.0.0.1 -psecret --silent >/dev/null 2>&1 && return 0
        sleep 1
    done
    return 1
}
wait_ready cc30-workflow-db || fail "container for workflow's declared DB image ($WORKFLOW_DB_IMAGE) never became ready"
wait_ready cc30-mariadb-control || fail "mariadb:10.11 negative-control container never became ready"

apply_ddl() {
    local name="$1"
    printf 'CREATE TABLE analytics_events (id INT PRIMARY KEY AUTO_INCREMENT, properties JSON NULL);\n%s\n' "$DDL" \
        | docker exec -i "$name" mysql -uroot -psecret registro_test >"$SANDBOX/${name}.out" 2>"$SANDBOX/${name}.err"
}

# --- (1) the engine the workflow ACTUALLY declares today must accept it ---
if ! apply_ddl cc30-workflow-db; then
    fail "the migration's virtual-column syntax was REJECTED by $WORKFLOW_DB_IMAGE (the image deploy-production.yml's test gate currently declares):
$(cat "$SANDBOX/cc30-workflow-db.err")"
fi

# --- (2) negative control: mariadb:10.11 must still reject it -------------
if apply_ddl cc30-mariadb-control; then
    fail "mariadb:10.11 unexpectedly ACCEPTED the virtual-column syntax -- negative control is not discriminating, this case cannot prove anything"
fi
assert_contains "$(cat "$SANDBOX/cc30-mariadb-control.err")" "42000" "mariadb negative control error"

test_finish

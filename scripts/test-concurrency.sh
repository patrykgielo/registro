#!/usr/bin/env bash
set -euo pipefail

# Runs tests/Concurrency (the two-connection oversell race harness,
# kontrakt-dostepnosci.md Zasada 6) against a throwaway mysql:8.0
# container — SQLite has no InnoDB row locking, so this suite is
# meaningless there and is excluded from phpunit.xml's defaultTestSuite
# (same precedent as tests/Browser).
#
# NEVER touches registro-mysql (the dev database, Incident 2026-03-17).
# The container name/database/user below are hardcoded to values that
# cannot collide with it, and are re-checked here AND independently in
# CartCheckoutRaceTest::setUp() / probe.php before either ever runs a
# query.
#
# Usage: bash scripts/test-concurrency.sh

CONTAINER_NAME="registro-mysql-concurrency-probe"
NETWORK="app_registro"
DB_NAME="concurrency_probe"
DB_USER="probe"
DB_PASSWORD="probe_$(openssl rand -hex 8)"
DB_ROOT_PASSWORD="root_$(openssl rand -hex 8)"

for forbidden in mysql registro-mysql 127.0.0.1 localhost; do
    if [ "${CONTAINER_NAME}" = "${forbidden}" ]; then
        echo "FATAL: CONTAINER_NAME collides with a forbidden value (${forbidden})." >&2
        exit 1
    fi
done
if [ "${DB_NAME}" = "registro" ]; then
    echo "FATAL: DB_NAME collides with the dev database name." >&2
    exit 1
fi

cleanup() {
    echo "[test-concurrency] removing ${CONTAINER_NAME}..."
    docker rm -f "${CONTAINER_NAME}" >/dev/null 2>&1 || true
}
trap cleanup EXIT

echo "[test-concurrency] starting throwaway ${CONTAINER_NAME} (mysql:8.0) on network ${NETWORK}..."
docker run -d \
    --name "${CONTAINER_NAME}" \
    --network "${NETWORK}" \
    -e MYSQL_ROOT_PASSWORD="${DB_ROOT_PASSWORD}" \
    -e MYSQL_DATABASE="${DB_NAME}" \
    -e MYSQL_USER="${DB_USER}" \
    -e MYSQL_PASSWORD="${DB_PASSWORD}" \
    mysql:8.0 >/dev/null

echo "[test-concurrency] waiting for MySQL to accept connections..."
ready=""
for _ in $(seq 1 60); do
    if docker exec "${CONTAINER_NAME}" mysqladmin ping -h 127.0.0.1 -u"${DB_USER}" -p"${DB_PASSWORD}" --silent >/dev/null 2>&1; then
        ready="1"
        break
    fi
    sleep 1
done
if [ -z "${ready}" ]; then
    echo "FATAL: MySQL did not become ready within 60s." >&2
    exit 1
fi

echo "[test-concurrency] resolved target: host=${CONTAINER_NAME} database=${DB_NAME} (NOT registro-mysql/registro)."

# DatabaseTruncation (see CartCheckoutRaceTest) runs migrate:fresh itself on
# the first test of the process — no separate migrate step needed here.
docker compose exec -T \
    -e APP_ENV=testing \
    -e DB_CONNECTION=mysql \
    -e DB_HOST="${CONTAINER_NAME}" \
    -e DB_PORT=3306 \
    -e DB_DATABASE="${DB_NAME}" \
    -e DB_USERNAME="${DB_USER}" \
    -e DB_PASSWORD="${DB_PASSWORD}" \
    app php artisan test --testsuite=Concurrency

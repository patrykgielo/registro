#!/bin/bash
###############################################################################
# Environment Variable Validation Script
#
# Purpose: Validate critical environment variables before deployment
# Usage: ./scripts/validate-env.sh [environment]
#   environment: local, staging, production (default: auto-detect from APP_ENV)
#
# Exit codes:
#   0 - All validations passed
#   1 - Validation failures found
#   2 - Script usage error
###############################################################################

# NOT -e. This script accumulates failures and reports them together, exiting on
# the ERRORS count at the very end. Under `set -e` the first failing check_var_*
# (they return 1 by design) killed the run, so you saw one problem per
# invocation instead of the list -- and every `pass` did too, because
# `((CHECKS++))` returns 1 when CHECKS is 0. The counters are plain arithmetic
# assignments now for the same reason.
set -uo pipefail

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Counters
ERRORS=0
WARNINGS=0
CHECKS=0

# Environment (auto-detect or from argument)
ENV="${1:-${APP_ENV:-local}}"

echo -e "${GREEN}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo -e "${GREEN}  Environment Variable Validation Script${NC}"
echo -e "${GREEN}  Environment: ${ENV}${NC}"
echo -e "${GREEN}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo ""

###############################################################################
# Helper Functions
###############################################################################

error() {
    echo -e "${RED}✗ ERROR:${NC} $1"
    ERRORS=$((ERRORS + 1))
    CHECKS=$((CHECKS + 1))
}

warn() {
    echo -e "${YELLOW}⚠ WARNING:${NC} $1"
    WARNINGS=$((WARNINGS + 1))
    CHECKS=$((CHECKS + 1))
}

pass() {
    echo -e "${GREEN}✓${NC} $1"
    CHECKS=$((CHECKS + 1))
}

check_var_set() {
    local var_name=$1
    local var_value="${!var_name:-}"

    if [ -z "$var_value" ]; then
        error "$var_name is not set or empty"
        return 1
    else
        pass "$var_name is set"
        return 0
    fi
}

check_var_equals() {
    local var_name=$1
    local expected=$2
    local var_value="${!var_name:-}"

    if [ "$var_value" != "$expected" ]; then
        error "$var_name = '$var_value' (expected: '$expected')"
        return 1
    else
        pass "$var_name = '$expected'"
        return 0
    fi
}

check_var_not_equals() {
    local var_name=$1
    local forbidden=$2
    local var_value="${!var_name:-}"

    if [ "$var_value" == "$forbidden" ]; then
        error "$var_name = '$forbidden' (FORBIDDEN VALUE!)"
        return 1
    else
        pass "$var_name != '$forbidden'"
        return 0
    fi
}

###############################################################################
# Load .env
#
# Every check below reads shell variables. Without this block the script
# validates an empty environment and reports every variable as missing, which
# is exactly backwards from what it is for. ENV_FILE can be overridden to
# validate a file that is not the one next to the script.
###############################################################################

ENV_FILE="${ENV_FILE:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/.env}"

if [ -f "$ENV_FILE" ]; then
    echo "Loading ${ENV_FILE}"
    set -a
    # shellcheck disable=SC1090
    source "$ENV_FILE"
    set +a
    echo ""
else
    echo -e "${RED}✗ ERROR:${NC} ${ENV_FILE} not found -- nothing to validate"
    exit 1
fi

# Re-resolve after loading: with no argument, ENV comes from the file we just read.
ENV="${1:-${APP_ENV:-local}}"

###############################################################################
# Core Application Checks
###############################################################################

echo "━━━ Core Application ━━━"
check_var_set "APP_NAME"
check_var_set "APP_KEY"

if [ "$ENV" == "production" ] || [ "$ENV" == "staging" ]; then
    check_var_equals "APP_ENV" "$ENV"
    check_var_equals "APP_DEBUG" "false"

    # Tenant subdomain routing is built on this. config/app.php has a default,
    # but env() only falls back when the variable is ABSENT -- an empty
    # APP_DOMAIN= line in .env is present and wins, and every tenant URL the app
    # generates comes out malformed.
    check_var_set "APP_URL"
    check_var_set "APP_DOMAIN"
else
    pass "APP_ENV = $APP_ENV (local/development)"
fi

echo ""

###############################################################################
# CRITICAL: File Storage Configuration
###############################################################################

echo "━━━ File Storage (CRITICAL) ━━━"

# Check FILESYSTEM_DISK
FILESYSTEM_DISK="${FILESYSTEM_DISK:-}"

if [ -z "$FILESYSTEM_DISK" ]; then
    error "FILESYSTEM_DISK is not set!"
elif [ "$FILESYSTEM_DISK" == "local" ]; then
    error "FILESYSTEM_DISK = 'local' (BREAKS file uploads in Filament!)"
    echo "  ⮕  FIX: Set FILESYSTEM_DISK=public in .env"
elif [ "$FILESYSTEM_DISK" == "public" ]; then
    pass "FILESYSTEM_DISK = 'public' (correct)"
else
    warn "FILESYSTEM_DISK = '$FILESYSTEM_DISK' (unusual value, expected: public or s3)"
fi

# Check if storage link exists (only relevant if FILESYSTEM_DISK=public)
if [ "$FILESYSTEM_DISK" == "public" ]; then
    if [ -L "public/storage" ]; then
        pass "storage symlink exists (public/storage → storage/app/public)"
    else
        warn "storage symlink missing - run: php artisan storage:link"
    fi
fi

echo ""

###############################################################################
# Database Configuration
###############################################################################

echo "━━━ Database ━━━"

if [ "$ENV" == "production" ] || [ "$ENV" == "staging" ]; then
    check_var_equals "DB_CONNECTION" "mysql"
else
    check_var_set "DB_CONNECTION"
fi

check_var_set "DB_HOST"
check_var_set "DB_DATABASE"
check_var_set "DB_USERNAME"
check_var_set "DB_PASSWORD"

echo ""

###############################################################################
# Queue & Cache Configuration
###############################################################################

echo "━━━ Queue & Cache ━━━"

if [ "$ENV" == "production" ] || [ "$ENV" == "staging" ]; then
    check_var_equals "QUEUE_CONNECTION" "redis"
    check_var_equals "CACHE_STORE" "redis" || check_var_equals "CACHE_DRIVER" "redis"
    check_var_equals "SESSION_DRIVER" "redis" || check_var_equals "SESSION_DRIVER" "database"
else
    check_var_set "QUEUE_CONNECTION"
fi

check_var_set "REDIS_HOST"

# Blank REDIS_PASSWORD is not a soft failure. docker-compose.prod.yml passes it
# straight through to `redis-server --requirepass ${REDIS_PASSWORD} --maxmemory
# 256mb`; an empty value drops the token, redis reads "--maxmemory" as the
# password argument, refuses the rest of the line and exits. Everything that
# depends on redis -- cache, sessions, queues -- goes down with it. Checked in
# every environment because the local stack authenticates too.
check_var_set "REDIS_PASSWORD"

echo ""

###############################################################################
# External Services
###############################################################################

echo "━━━ External Services ━━━"

# Google Maps (required for booking system)
if check_var_set "GOOGLE_MAPS_API_KEY"; then
    check_var_set "GOOGLE_MAPS_MAP_ID"
fi

# Przelewy24 (online settlement)
#
# All-or-nothing on purpose. A PARTIALLY filled gateway is worse than an empty
# one: it looks configured, the app keeps offering online payment, and the
# breakage only surfaces when a real customer submits a real order. That is
# exactly what shipped on 2026-08-16 -- `P24_POS_ID=` present-but-empty, as
# .env.production.example itself ships it -- and every online checkout returned
# a 500 (see .claude/rules/ci-cd-troubleshooting.md).
#
# Empty across the board is a LEGITIMATE configuration (a pay-at-pickup-only
# tenant), so that is a warning, not an error: SettingsManager stops offering
# the online method at all when Przelewy24Service::isConfigured() is false.
#
# P24_POS_ID is deliberately not checked -- the SDK falls back to the merchant
# id when it is absent (Przelewy24\Config::posId()), so empty is valid for it.
P24_SET_COUNT=0
P24_EMPTY_VARS=""
for p24_var in P24_MERCHANT_ID P24_CRC P24_REPORTS_KEY; do
    if [ -n "${!p24_var:-}" ]; then
        P24_SET_COUNT=$((P24_SET_COUNT + 1))
    else
        P24_EMPTY_VARS="${P24_EMPTY_VARS}${p24_var} "
    fi
done

if [ "$P24_SET_COUNT" -eq 3 ]; then
    pass "Przelewy24 fully configured (P24_MERCHANT_ID, P24_CRC, P24_REPORTS_KEY)"
elif [ "$P24_SET_COUNT" -eq 0 ]; then
    warn "Przelewy24 not configured - online payments will not be offered (pay-at-pickup only)"
else
    error "Przelewy24 partially configured - empty: ${P24_EMPTY_VARS% } (set all three or none; a partial gateway fails only at a real customer's checkout)"
fi

# Email (required for notifications)
if [ "$ENV" == "production" ] || [ "$ENV" == "staging" ]; then
    check_var_set "MAIL_MAILER"
    if [ "${MAIL_MAILER:-}" == "smtp" ]; then
        check_var_set "MAIL_HOST"
        check_var_set "MAIL_PORT"
        check_var_set "MAIL_USERNAME"
        check_var_set "MAIL_PASSWORD"
        check_var_set "MAIL_FROM_ADDRESS"
    fi
fi

echo ""

###############################################################################
# Security Checks
###############################################################################

echo "━━━ Security ━━━"

# Check APP_KEY format
APP_KEY="${APP_KEY:-}"
if [ -n "$APP_KEY" ] && [[ ! "$APP_KEY" =~ ^base64: ]]; then
    warn "APP_KEY doesn't start with 'base64:' - may be invalid format"
fi

# Production-specific security checks
if [ "$ENV" == "production" ]; then
    check_var_equals "APP_DEBUG" "false"

    # Session security
    SESSION_SECURE_COOKIE="${SESSION_SECURE_COOKIE:-}"
    if [ "$SESSION_SECURE_COOKIE" != "true" ]; then
        warn "SESSION_SECURE_COOKIE should be 'true' for HTTPS sites"
    fi
fi

echo ""

###############################################################################
# Logging Configuration
###############################################################################

echo "━━━ Logging ━━━"

if [ "$ENV" == "production" ]; then
    LOG_STACK="${LOG_STACK:-single}"
    if [ "$LOG_STACK" == "single" ]; then
        warn "LOG_STACK=single in production (use 'daily' for log rotation to prevent disk fill)"
    fi

    LOG_LEVEL="${LOG_LEVEL:-debug}"
    if [ "$LOG_LEVEL" != "error" ] && [ "$LOG_LEVEL" != "warning" ]; then
        warn "LOG_LEVEL=$LOG_LEVEL in production (use 'error' or 'warning' for performance)"
    fi
fi

echo ""

###############################################################################
# Results Summary
###############################################################################

echo -e "${GREEN}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo -e "  Validation Results"
echo -e "${GREEN}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo ""
echo -e "  Total checks: ${CHECKS}"
echo -e "  ${GREEN}Passed: $((CHECKS - ERRORS - WARNINGS))${NC}"
echo -e "  ${YELLOW}Warnings: ${WARNINGS}${NC}"
echo -e "  ${RED}Errors: ${ERRORS}${NC}"
echo ""

if [ $ERRORS -gt 0 ]; then
    echo -e "${RED}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
    echo -e "${RED}  VALIDATION FAILED${NC}"
    echo -e "${RED}  Fix the errors above before deploying!${NC}"
    echo -e "${RED}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
    exit 1
elif [ $WARNINGS -gt 0 ]; then
    echo -e "${YELLOW}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
    echo -e "${YELLOW}  VALIDATION PASSED WITH WARNINGS${NC}"
    echo -e "${YELLOW}  Review warnings above before deploying${NC}"
    echo -e "${YELLOW}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
    exit 0
else
    echo -e "${GREEN}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
    echo -e "${GREEN}  ✓ ALL VALIDATIONS PASSED${NC}"
    echo -e "${GREEN}  Environment is ready for deployment!${NC}"
    echo -e "${GREEN}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
    exit 0
fi

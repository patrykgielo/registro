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

set -euo pipefail

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
    ((ERRORS++))
    ((CHECKS++))
}

warn() {
    echo -e "${YELLOW}⚠ WARNING:${NC} $1"
    ((WARNINGS++))
    ((CHECKS++))
}

pass() {
    echo -e "${GREEN}✓${NC} $1"
    ((CHECKS++))
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
# Core Application Checks
###############################################################################

echo "━━━ Core Application ━━━"
check_var_set "APP_NAME"
check_var_set "APP_KEY"

if [ "$ENV" == "production" ] || [ "$ENV" == "staging" ]; then
    check_var_equals "APP_ENV" "$ENV"
    check_var_equals "APP_DEBUG" "false"
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

echo ""

###############################################################################
# External Services
###############################################################################

echo "━━━ External Services ━━━"

# Google Maps (required for booking system)
if check_var_set "GOOGLE_MAPS_API_KEY"; then
    check_var_set "GOOGLE_MAPS_MAP_ID"
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

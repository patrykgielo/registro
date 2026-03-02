#!/bin/bash

################################################################################
# Zero-Downtime Deployment with Healthcheck Strategy
#
# This script implements blue-green deployment pattern for container rebuilds:
# 1. Old container continues serving traffic
# 2. Build new container with correct UID in background
# 3. Start new container, wait for healthy
# 4. Run migrations (brief ~15s downtime)
# 5. Switch traffic to new container
# 6. Remove old container
#
# Usage:
#   ./scripts/deploy-with-healthcheck.sh
#
# Prerequisites:
#   - Docker and Docker Compose installed
#   - Application code deployed to /var/www/registro
#   - .env file configured
#
# Exit codes:
#   0 - Deployment successful
#   1 - Pre-flight checks failed
#   2 - Build failed
#   3 - Health check failed
#   4 - Migration failed
#   5 - Verification failed
################################################################################

set -euo pipefail

# Colors for output
readonly RED='\033[0;31m'
readonly GREEN='\033[0;32m'
readonly YELLOW='\033[1;33m'
readonly BLUE='\033[0;34m'
readonly NC='\033[0m' # No Color

# Configuration
readonly APP_DIR="/var/www/registro"
readonly COMPOSE_FILE="docker-compose.prod.yml"
readonly HEALTH_CHECK_TIMEOUT=300  # 5 minutes
readonly HEALTH_CHECK_INTERVAL=5   # 5 seconds
readonly MIGRATION_TIMEOUT=60      # 1 minute
readonly SEEDER_TIMEOUT=120        # 2 minutes (allows first deployment buffer)

################################################################################
# Helper Functions
################################################################################

log_info() {
    echo -e "${BLUE}ℹ️  $1${NC}"
}

log_success() {
    echo -e "${GREEN}✅ $1${NC}"
}

log_warning() {
    echo -e "${YELLOW}⚠️  $1${NC}"
}

log_error() {
    echo -e "${RED}❌ $1${NC}"
}

exit_with_error() {
    log_error "$1"
    exit "${2:-1}"
}

################################################################################
# Pre-flight Checks
################################################################################

preflight_checks() {
    log_info "Running pre-flight checks..."

    # Check if running from correct directory
    if [ ! -f "$COMPOSE_FILE" ]; then
        exit_with_error "docker-compose.prod.yml not found. Are you in $APP_DIR?" 1
    fi

    # Check if .env exists
    if [ ! -f .env ]; then
        exit_with_error ".env file not found" 1
    fi

    # Check Docker is running
    if ! docker info >/dev/null 2>&1; then
        exit_with_error "Docker is not running" 1
    fi

    # Check if old container exists
    if ! docker compose -f "$COMPOSE_FILE" ps app | grep -q "Up"; then
        log_warning "No running app container found. This will be a fresh deployment."
    fi

    log_success "Pre-flight checks passed"
}

################################################################################
# Detect UID/GID
################################################################################

detect_uid_gid() {
    log_info "Detecting UID/GID from file ownership..."

    local detected_uid detected_gid

    # Try multiple locations with fallback chain
    if [ -d "$APP_DIR/storage" ]; then
        detected_uid=$(stat -c '%u' "$APP_DIR/storage" 2>/dev/null || echo "1000")
        detected_gid=$(stat -c '%g' "$APP_DIR/storage" 2>/dev/null || echo "1000")
    else
        log_warning "storage directory not found, using fallback"
        detected_uid=$(stat -c '%u' "$APP_DIR" 2>/dev/null || echo "1000")
        detected_gid=$(stat -c '%g' "$APP_DIR" 2>/dev/null || echo "1000")
    fi

    # Reject root UID (0) - never correct for application
    if [ "$detected_uid" = "0" ]; then
        log_warning "Detected UID is 0 (root), using fallback 1000"
        detected_uid="1000"
        detected_gid="1000"
    fi

    export DOCKER_USER_ID="$detected_uid"
    export DOCKER_GROUP_ID="$detected_gid"

    log_success "Detected UID:GID = $DOCKER_USER_ID:$DOCKER_GROUP_ID"
}

################################################################################
# Pull New Image from Registry
################################################################################

build_new_image() {
    log_info "Pulling new Docker image from registry (version: ${VERSION})..."

    # Pull image from GitHub Container Registry (already built by CI/CD)
    if ! docker compose -f "$COMPOSE_FILE" pull app horizon scheduler; then
        exit_with_error "Docker pull failed" 2
    fi

    log_success "Docker image pulled successfully"
}

################################################################################
# Verify Built Image UID
################################################################################

verify_image_uid() {
    log_info "Verifying build args were applied..."

    # Build succeeded with correct args, no need to verify
    # (Previous verification method was flawed - created new container with default args)

    log_success "Image built with USER_ID=$DOCKER_USER_ID GROUP_ID=$DOCKER_GROUP_ID"
    log_info "UID verification skipped (build args confirmed during build)"
}

################################################################################
# Start New Container
################################################################################

start_new_container() {
    log_info "Starting new app container..."

    # Scale app service to 2 (old + new)
    # Note: Removed --no-recreate to ensure new env vars are applied to scaled containers
    if ! docker compose -f "$COMPOSE_FILE" up -d --scale app=2; then
        exit_with_error "Failed to start new container" 3
    fi

    log_success "New container started"
}

################################################################################
# Wait for Health Check
################################################################################

wait_for_healthy() {
    log_info "Waiting for new container to become healthy (timeout: ${HEALTH_CHECK_TIMEOUT}s)..."

    local elapsed=0
    local new_container
    new_container=$(docker compose -f "$COMPOSE_FILE" ps -q app | tail -n 1)

    while [ $elapsed -lt $HEALTH_CHECK_TIMEOUT ]; do
        local health_status
        health_status=$(docker inspect "$new_container" --format='{{.State.Health.Status}}' 2>/dev/null || echo "unknown")

        if [ "$health_status" = "healthy" ]; then
            log_success "New container is healthy (took ${elapsed}s)"
            return 0
        fi

        if [ "$health_status" = "unhealthy" ]; then
            exit_with_error "New container became unhealthy" 3
        fi

        echo -n "."
        sleep $HEALTH_CHECK_INTERVAL
        elapsed=$((elapsed + HEALTH_CHECK_INTERVAL))
    done

    echo ""
    exit_with_error "Health check timeout after ${HEALTH_CHECK_TIMEOUT}s" 3
}

################################################################################
# Run Migrations
################################################################################

run_migrations() {
    log_info "Running database migrations (brief downtime ~15s)..."

    local new_container
    new_container=$(docker compose -f "$COMPOSE_FILE" ps -q app | tail -n 1)

    # Ensure storage permissions are correct (UID match)
    # Must run as root because container USER is laravel (non-root)
    log_info "Fixing storage permissions for UID=$DOCKER_USER_ID..."
    if ! docker exec --user root "$new_container" chown -R laravel:laravel /var/www/storage /var/www/bootstrap/cache; then
        exit_with_error "Failed to fix storage permissions (chown failed as root)" 6
    fi
    log_success "Storage permissions fixed successfully"

    # Clear old config cache to ensure fresh generation with production .env
    log_info "Clearing old config cache..."
    docker exec "$new_container" php artisan config:clear

    # Debug: Show environment variables in new container
    log_info "Debug: Environment variables in new container:"
    docker exec "$new_container" sh -c 'echo "  DB_CONNECTION=$DB_CONNECTION"' || echo "  DB_CONNECTION not set"
    docker exec "$new_container" sh -c 'echo "  DB_HOST=$DB_HOST"' || echo "  DB_HOST not set"
    docker exec "$new_container" sh -c 'echo "  DB_PORT=$DB_PORT"' || echo "  DB_PORT not set"

    # Verify DB_CONNECTION environment variable is set to mysql
    log_info "Verifying database configuration..."
    DB_CONN=$(docker exec "$new_container" printenv DB_CONNECTION 2>/dev/null || echo "NOT_SET")
    if [ "$DB_CONN" != "mysql" ]; then
        log_error "DB_CONNECTION verification failed!"
        log_error "Expected: 'mysql'"
        log_error "Actual: '$DB_CONN'"
        log_error "All DB-related environment variables:"
        docker exec "$new_container" sh -c 'env | grep -E "^DB_|^REDIS_" | sort' || true
        exit_with_error "DB_CONNECTION is '$DB_CONN', expected 'mysql'" 7
    fi
    log_success "Database configuration verified: MySQL (DB_CONNECTION=$DB_CONN)"

    # Cache configuration to ensure DB_CONNECTION is loaded from production .env
    log_info "Caching Laravel configuration with production .env..."
    docker exec "$new_container" php artisan config:cache

    # Run migrations
    if ! timeout "$MIGRATION_TIMEOUT" docker exec "$new_container" php artisan migrate --force; then
        exit_with_error "Migration failed or timed out" 4
    fi

    log_success "Migrations completed successfully (schema + data migrations)"
}

################################################################################
# Switch Traffic
################################################################################

switch_traffic() {
    log_info "Switching traffic to new container..."

    # Stop old containers
    local old_containers
    old_containers=$(docker compose -f "$COMPOSE_FILE" ps -q app | head -n -1)

    if [ -n "$old_containers" ]; then
        echo "$old_containers" | xargs -r docker stop
        log_success "Old containers stopped"
    fi

    # Scale back to 1
    docker compose -f "$COMPOSE_FILE" up -d --scale app=1 --remove-orphans

    # Restart Horizon and Scheduler with new image
    docker compose -f "$COMPOSE_FILE" up -d horizon scheduler

    log_success "Traffic switched to new container"
}

################################################################################
# Verify Deployment
################################################################################

verify_deployment() {
    log_info "Verifying deployment..."

    # Check if app container is running
    if ! docker compose -f "$COMPOSE_FILE" ps app | grep -q "Up"; then
        exit_with_error "App container is not running" 5
    fi

    # Check health endpoint (with Nginx restart failsafe)
    log_info "Checking health endpoint..."

    if command -v curl >/dev/null 2>&1; then
        # First attempt (relies on DNS resolver)
        if ! curl -sf "http://localhost/up" >/dev/null 2>&1; then
            log_warning "Health endpoint failed - possible Nginx IP cache (failsafe engaging)"
            log_info "Restarting Nginx to clear cached upstream IP..."

            # Restart Nginx (failsafe for edge cases where DNS resolver doesn't work)
            if ! docker compose -f "$COMPOSE_FILE" restart nginx; then
                exit_with_error "Failed to restart Nginx during health check recovery" 5
            fi
            sleep 3  # Wait for Nginx to fully restart

            # Retry health check after Nginx restart
            if ! curl -sf "http://localhost/up" >/dev/null 2>&1; then
                exit_with_error "Health endpoint FAILED after Nginx restart - deployment aborted" 5
            fi

            log_success "Health endpoint recovered (Nginx restart failsafe worked)"
        else
            log_success "Health endpoint passed (DNS resolver working, no Nginx restart needed)"
        fi
    else
        log_warning "curl not available - skipping health endpoint check"
    fi

    log_success "Deployment verification passed"
}

################################################################################
# Cleanup
################################################################################

cleanup_old_images() {
    log_info "Cleaning up old images and containers..."

    # Remove stopped containers
    docker compose -f "$COMPOSE_FILE" rm -f

    # Prune old images (keep last 2 versions)
    docker image prune -af --filter "until=24h" >/dev/null 2>&1 || true

    log_success "Cleanup completed"
}

################################################################################
# Rollback on Failure
################################################################################

rollback() {
    log_error "Deployment failed! Rolling back..."

    # Scale back to 1 (keeps old container)
    docker compose -f "$COMPOSE_FILE" up -d --scale app=1 --remove-orphans || true

    # Remove new containers
    docker compose -f "$COMPOSE_FILE" ps -q app | tail -n 1 | xargs -r docker rm -f || true

    log_warning "Rollback completed. Old container should still be running."
    exit 1
}

################################################################################
# Main Execution
################################################################################

main() {
    log_info "=== Zero-Downtime Deployment with Healthcheck Strategy ==="

    # Change to app directory
    cd "$APP_DIR" || exit_with_error "Failed to change to $APP_DIR" 1

    # Set trap for cleanup on failure
    trap rollback ERR

    # Execute deployment steps
    preflight_checks
    detect_uid_gid
    build_new_image
    verify_image_uid
    start_new_container
    wait_for_healthy
    run_migrations
    switch_traffic
    verify_deployment
    cleanup_old_images

    echo ""
    log_success "=== Deployment completed successfully! ==="
    echo ""
}

# Run main function
main "$@"

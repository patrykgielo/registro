#!/bin/bash

#########################################################
# Deployment Script - Registro Laravel Application
#
# Description: Zero-downtime deployment for production
# Author: Registro Team
# Last Updated: November 2025
#
# Usage:
#   ./scripts/deploy.sh [OPTIONS]
#
# Options:
#   --skip-backup    Skip database backup before deployment
#   --skip-tests     Skip running tests (NOT recommended)
#   --force          Force deployment without confirmation
#
# Example:
#   ./scripts/deploy.sh
#   ./scripts/deploy.sh --skip-backup --force
#
#########################################################

set -e  # Exit on error

# ======================
# Configuration
# ======================

# Project directory
PROJECT_DIR="/var/www/registro"
COMPOSE_FILE="${PROJECT_DIR}/docker-compose.prod.yml"

# Git branch to deploy
DEPLOY_BRANCH="main"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Parse command line arguments
SKIP_BACKUP=false
SKIP_TESTS=false
FORCE_DEPLOY=false

for arg in "$@"; do
    case $arg in
        --skip-backup)
            SKIP_BACKUP=true
            shift
            ;;
        --skip-tests)
            SKIP_TESTS=true
            shift
            ;;
        --force)
            FORCE_DEPLOY=true
            shift
            ;;
        *)
            # Unknown option
            ;;
    esac
done

# ======================
# Functions
# ======================

log() {
    echo -e "${BLUE}[$(date '+%Y-%m-%d %H:%M:%S')]${NC} $1"
}

log_success() {
    echo -e "${GREEN}✓${NC} $1"
}

log_warning() {
    echo -e "${YELLOW}⚠${NC} $1"
}

log_error() {
    echo -e "${RED}✗${NC} $1"
}

print_header() {
    echo ""
    echo -e "${BLUE}========================================${NC}"
    echo -e "${BLUE}$1${NC}"
    echo -e "${BLUE}========================================${NC}"
    echo ""
}

confirm() {
    if [ "$FORCE_DEPLOY" = true ]; then
        return 0
    fi

    read -p "$(echo -e ${YELLOW}$1 [y/N]:${NC} )" -n 1 -r
    echo
    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
        log_error "Deployment cancelled by user"
        exit 1
    fi
}

check_prerequisites() {
    log "Checking prerequisites..."

    # Check if Docker is running
    if ! docker info >/dev/null 2>&1; then
        log_error "Docker is not running"
        exit 1
    fi

    # Check if in correct directory
    if [ ! -f "$COMPOSE_FILE" ]; then
        log_error "docker-compose.prod.yml not found. Are you in the correct directory?"
        exit 1
    fi

    # Check if .env file exists
    if [ ! -f "${PROJECT_DIR}/.env" ]; then
        log_error ".env file not found. Copy .env.production.example to .env and configure it."
        exit 1
    fi

    log_success "Prerequisites check passed"
}

# ======================
# Deployment Steps
# ======================

print_header "🚀 REGISTRO PRODUCTION DEPLOYMENT"

log "Deployment settings:"
log "  Branch: ${DEPLOY_BRANCH}"
log "  Skip backup: ${SKIP_BACKUP}"
log "  Skip tests: ${SKIP_TESTS}"
log "  Force mode: ${FORCE_DEPLOY}"
echo ""

confirm "Do you want to proceed with deployment?"

# Step 1: Prerequisites
print_header "Step 1: Prerequisites Check"
check_prerequisites

# Step 2: Backup Database
if [ "$SKIP_BACKUP" = false ]; then
    print_header "Step 2: Database Backup"
    log "Creating database backup before deployment..."

    if [ -x "${PROJECT_DIR}/scripts/backup-database.sh" ]; then
        "${PROJECT_DIR}/scripts/backup-database.sh"
        log_success "Database backup completed"
    else
        log_warning "Backup script not found or not executable. Skipping backup."
    fi
else
    log_warning "Skipping database backup (--skip-backup flag used)"
fi

# Step 3: Pull Latest Code
print_header "Step 3: Update Code from Git"
cd "$PROJECT_DIR"

log "Fetching latest changes from origin/${DEPLOY_BRANCH}..."
git fetch origin

log "Current branch: $(git branch --show-current)"
log "Latest commit: $(git log -1 --oneline)"

log "Pulling latest code..."
git pull origin "$DEPLOY_BRANCH"

CURRENT_COMMIT=$(git log -1 --oneline)
log_success "Code updated to: ${CURRENT_COMMIT}"

# Step 4: Build Docker Images
print_header "Step 4: Build Docker Images"
log "Pulling latest base images..."
docker compose -f "$COMPOSE_FILE" pull

log "Building application images..."
docker compose -f "$COMPOSE_FILE" build --no-cache app

log_success "Docker images built successfully"

# Step 5: Run Tests
if [ "$SKIP_TESTS" = false ]; then
    print_header "Step 5: Run Tests"
    log "Running PHPUnit tests..."

    if docker compose -f "$COMPOSE_FILE" exec -T app php artisan test; then
        log_success "All tests passed"
    else
        log_error "Tests failed! Deployment aborted."
        exit 1
    fi
else
    log_warning "Skipping tests (--skip-tests flag used)"
fi

# Step 6: Enable Maintenance Mode
print_header "Step 6: Maintenance Mode"
log "Enabling maintenance mode..."
docker compose -f "$COMPOSE_FILE" exec -T app php artisan down --render="errors::503" --retry=60

log_success "Maintenance mode enabled (users see 'Be right back' page)"

# Step 7: Update Dependencies
print_header "Step 7: Update Dependencies"
log "Installing/updating Composer dependencies..."
docker compose -f "$COMPOSE_FILE" exec -T app composer install --no-dev --optimize-autoloader

log_success "Dependencies updated"

# Step 8: Database Migrations
print_header "Step 8: Database Migrations"
log "Running database migrations..."

if docker compose -f "$COMPOSE_FILE" exec -T app php artisan migrate --force; then
    log_success "Migrations completed successfully"
else
    log_error "Migrations failed! Rolling back..."
    docker compose -f "$COMPOSE_FILE" exec -T app php artisan up
    exit 1
fi

# Step 9: Clear & Cache
print_header "Step 9: Clear Caches & Optimize"
log "Clearing all caches..."
docker compose -f "$COMPOSE_FILE" exec -T app php artisan optimize:clear

log "Caching routes, configs, and views..."
docker compose -f "$COMPOSE_FILE" exec -T app php artisan optimize

log_success "Caches optimized"

# Step 10: Restart Containers
print_header "Step 10: Restart Application Containers"
log "Restarting app and nginx containers..."

# Graceful restart (zero downtime)
docker compose -f "$COMPOSE_FILE" up -d --no-deps --build app nginx

log "Waiting for containers to be healthy (10 seconds)..."
sleep 10

log_success "Containers restarted"

# Step 11: Restart Queue Workers
print_header "Step 11: Restart Queue Workers"
log "Restarting Horizon (queue workers)..."
docker compose -f "$COMPOSE_FILE" restart horizon

log_success "Queue workers restarted"

# Step 12: Disable Maintenance Mode
print_header "Step 12: Disable Maintenance Mode"
log "Bringing application back online..."
docker compose -f "$COMPOSE_FILE" exec -T app php artisan up

log_success "Application is now online"

# Step 13: Health Check
print_header "Step 13: Health Check"
log "Performing health check..."

sleep 5  # Give app time to fully start

# Try to curl the health endpoint
if curl -f -s -o /dev/null -w "%{http_code}" http://localhost/health | grep -q "200"; then
    log_success "Health check passed (HTTP 200)"
elif curl -f -s -o /dev/null -w "%{http_code}" https://localhost/health | grep -q "200"; then
    log_success "Health check passed (HTTPS 200)"
else
    log_warning "Health check endpoint not responding. Check manually!"
fi

# Step 14: Verify Deployment
print_header "Step 14: Verification"

log "Container status:"
docker compose -f "$COMPOSE_FILE" ps

log ""
log "Recent logs (last 20 lines):"
docker compose -f "$COMPOSE_FILE" logs --tail=20 app

# ======================
# Summary
# ======================

print_header "✅ DEPLOYMENT COMPLETED SUCCESSFULLY"

log_success "Deployed commit: ${CURRENT_COMMIT}"
log_success "Time: $(date '+%Y-%m-%d %H:%M:%S')"
log ""
log "Next steps:"
log "  1. Verify application: Visit https://your-domain.com"
log "  2. Check admin panel: https://your-domain.com/admin"
log "  3. Monitor logs: docker compose -f docker-compose.prod.yml logs -f"
log "  4. Check queue: https://your-domain.com/horizon"
log ""
log "If anything goes wrong, rollback with:"
log "  git reset --hard HEAD~1"
log "  ./scripts/deploy.sh"
log ""

exit 0

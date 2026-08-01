#!/usr/bin/env bash

################################################################################
# deploy-update.sh - Zero-downtime deployment update script
#
# This script handles updates to an existing production deployment with
# minimal downtime using MaintenanceService and Docker images from GHCR.
#
# Usage: ./scripts/deploy-update.sh [VERSION] [OPTIONS]
#
# Arguments:
#   VERSION            Docker image version to deploy (e.g., v1.0.0, latest)
#
# Options:
#   --skip-backup      Skip database backup before update
#   --skip-migrations  Skip database migrations
#   --force            Skip all confirmations
#
# Prerequisites:
#   - Application already deployed via deploy-init.sh
#   - Docker containers running
#   - GHCR authentication configured
#
# What this script does:
#   1. Enable MaintenanceService (Deployment type)
#   2. Create database backup
#   3. Pull new Docker image from GHCR
#   4. Restart containers with new image
#   5. Run database migrations
#   6. Clear and rebuild caches
#   7. Disable MaintenanceService
#   8. Verify deployment
#
# Author: Registro Development Team
# Version: 2.0.0 (MaintenanceService + GHCR integration)
################################################################################

set -e  # Exit on error
set -u  # Exit on undefined variable
set -o pipefail  # Exit on pipe failure

# Color codes for output
readonly RED='\033[0;31m'
readonly GREEN='\033[0;32m'
readonly YELLOW='\033[1;33m'
readonly BLUE='\033[0;34m'
readonly NC='\033[0m' # No Color

# Script configuration
readonly SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
readonly PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"
readonly APP_DIR="$PROJECT_ROOT"
readonly DOCKER_COMPOSE_FILE="${PROJECT_ROOT}/docker-compose.prod.yml"
readonly BACKUP_SCRIPT="${SCRIPT_DIR}/backup-database.sh"

# Version to deploy (first argument or latest)
VERSION="${1:-latest}"

# Command-line options
SKIP_BACKUP=false
SKIP_MIGRATIONS=false
FORCE=false

################################################################################
# Helper Functions
################################################################################

log() {
    echo -e "${GREEN}[INFO]${NC} $*"
}

warn() {
    echo -e "${YELLOW}[WARN]${NC} $*"
}

error() {
    echo -e "${RED}[ERROR]${NC} $*" >&2
}

success() {
    echo -e "${GREEN}[SUCCESS]${NC} $*"
}

prompt() {
    echo -e "${BLUE}[PROMPT]${NC} $*"
}

################################################################################
# Parse Command-Line Arguments
################################################################################

parse_arguments() {
    # Skip first argument (VERSION already parsed)
    shift || true

    while [[ $# -gt 0 ]]; do
        case $1 in
            --skip-backup)
                SKIP_BACKUP=true
                shift
                ;;
            --skip-migrations)
                SKIP_MIGRATIONS=true
                shift
                ;;
            --force)
                FORCE=true
                shift
                ;;
            -h|--help)
                show_usage
                exit 0
                ;;
            *)
                error "Unknown option: $1"
                show_usage
                exit 1
                ;;
        esac
    done
}

show_usage() {
    cat << EOF
Usage: $0 [VERSION] [OPTIONS]

Zero-downtime deployment update script for production environment.
Uses MaintenanceService and Docker images from GHCR.

ARGUMENTS:
    VERSION             Docker image version (default: latest)
                        Examples: v1.0.0, v1.2.3, latest

OPTIONS:
    --skip-backup       Skip database backup before update
    --skip-migrations   Skip database migrations
    --force             Skip all confirmations
    -h, --help          Show this help message

EXAMPLES:
    $0 v1.2.3                       # Deploy version v1.2.3
    $0 latest                       # Deploy latest version
    $0 v1.0.0 --skip-backup         # Deploy without backup
    $0 v1.1.0 --force               # Deploy without prompts

EOF
}

################################################################################
# Validation Functions
################################################################################

check_prerequisites() {
    log "Checking prerequisites..."

    # Check if Docker Compose file exists
    if [[ ! -f "$DOCKER_COMPOSE_FILE" ]]; then
        error "Production docker-compose file not found: $DOCKER_COMPOSE_FILE"
        exit 1
    fi

    # Check if containers are running
    if ! docker compose -f "$DOCKER_COMPOSE_FILE" ps | grep -q "Up"; then
        error "No running containers found. Is the application deployed?"
        error "Use deploy-init.sh for initial deployment."
        exit 1
    fi

    # Check if Git repository
    if [[ ! -d "${PROJECT_ROOT}/.git" ]]; then
        warn "Not a Git repository. Code update will be skipped."
    fi

    success "Prerequisites met"
}

confirm_update() {
    if [[ "$FORCE" == true ]]; then
        return 0
    fi

    prompt "This will update the production application. Continue? (y/N): "
    read -r response
    if [[ ! "$response" =~ ^[Yy]$ ]]; then
        log "Update cancelled by user"
        exit 0
    fi
}

################################################################################
# Backup Functions
################################################################################

backup_database() {
    if [[ "$SKIP_BACKUP" == true ]]; then
        warn "Skipping database backup (--skip-backup flag)"
        return 0
    fi

    log "Creating database backup..."

    if [[ ! -f "$BACKUP_SCRIPT" ]]; then
        warn "Backup script not found: $BACKUP_SCRIPT"
        warn "Skipping backup. Consider creating backups manually."
        return 0
    fi

    if bash "$BACKUP_SCRIPT"; then
        success "Database backup created successfully"
    else
        error "Database backup failed"
        prompt "Continue without backup? (y/N): "
        read -r response
        if [[ ! "$response" =~ ^[Yy]$ ]]; then
            exit 1
        fi
    fi
}

################################################################################
# Update Functions
################################################################################

pull_docker_image() {
    log "Pulling Docker image from GHCR..."
    log "Version: $VERSION"

    cd "$PROJECT_ROOT"

    # Export VERSION for docker compose
    export VERSION

    # Pull new image
    if docker compose -f "$DOCKER_COMPOSE_FILE" pull app; then
        success "Docker image pulled: ghcr.io/patrykgielo/registro:$VERSION"
    else
        error "Failed to pull Docker image"
        error "Make sure:"
        error "  1. You're logged into GHCR: docker login ghcr.io"
        error "  2. The version exists: $VERSION"
        error "  3. You have access to the repository"
        exit 1
    fi
}

enable_maintenance_mode() {
    log "Enabling MaintenanceService (Deployment type)..."

    docker compose -f "$DOCKER_COMPOSE_FILE" exec -T app php artisan maintenance:enable \
        --type=deployment \
        --message="Deploying version $VERSION" \
        --estimated-duration="2 minutes"

    success "Maintenance mode enabled"
}

disable_maintenance_mode() {
    log "Disabling MaintenanceService..."

    docker compose -f "$DOCKER_COMPOSE_FILE" exec -T app php artisan maintenance:disable

    success "Maintenance mode disabled - site is now live"
}

run_migrations() {
    if [[ "$SKIP_MIGRATIONS" == true ]]; then
        warn "Skipping database migrations (--skip-migrations flag)"
        return 0
    fi

    log "Running database migrations..."

    # Run migrations (MaintenanceService already enabled)
    # Note: Migrations handle BOTH schema changes AND reference data (email/SMS templates)
    if docker compose -f "$DOCKER_COMPOSE_FILE" exec -T app php artisan migrate --force; then
        success "Migrations completed successfully (schema + data migrations)"
    else
        error "Migrations failed!"
        # Disable maintenance mode before exiting
        disable_maintenance_mode
        exit 1
    fi
}

clear_and_rebuild_caches() {
    log "Clearing and rebuilding caches..."

    # Clear all caches
    docker compose -f "$DOCKER_COMPOSE_FILE" exec -T app php artisan optimize:clear

    # Rebuild caches
    docker compose -f "$DOCKER_COMPOSE_FILE" exec -T app php artisan optimize
    docker compose -f "$DOCKER_COMPOSE_FILE" exec -T app php artisan config:cache
    docker compose -f "$DOCKER_COMPOSE_FILE" exec -T app php artisan route:cache
    docker compose -f "$DOCKER_COMPOSE_FILE" exec -T app php artisan view:cache
    docker compose -f "$DOCKER_COMPOSE_FILE" exec -T app php artisan filament:optimize

    success "Caches rebuilt"
}

restart_services() {
    log "Restarting services with new Docker image..."

    cd "$PROJECT_ROOT"

    # Export VERSION for docker compose
    export VERSION

    # Restart all app containers with new image
    log "Restarting app container..."
    docker compose -f "$DOCKER_COMPOSE_FILE" up -d app

    # Restart Horizon with new image
    log "Restarting Horizon..."
    docker compose -f "$DOCKER_COMPOSE_FILE" exec -T app php artisan horizon:terminate || true
    docker compose -f "$DOCKER_COMPOSE_FILE" up -d horizon

    # Restart scheduler with new image
    log "Restarting scheduler..."
    docker compose -f "$DOCKER_COMPOSE_FILE" up -d scheduler

    # Wait for services to be healthy
    log "Waiting for services to be healthy..."
    sleep 15

    success "Services restarted with version: $VERSION"
}

################################################################################
# Verification Functions
################################################################################

verify_deployment() {
    log "Verifying deployment..."

    # Check container status
    log "Container status:"
    docker compose -f "$DOCKER_COMPOSE_FILE" ps

    # Check if all services are running
    if docker compose -f "$DOCKER_COMPOSE_FILE" ps | grep -q "Exit"; then
        error "Some containers have exited!"
        docker compose -f "$DOCKER_COMPOSE_FILE" ps
        exit 1
    fi

    # Check application health
    local app_url
    app_url=$(grep "^APP_URL=" "${APP_DIR}/.env" | cut -d'=' -f2)

    if [[ -n "$app_url" ]]; then
        log "Checking application health at: $app_url"
        if curl -sSf "$app_url" &> /dev/null; then
            success "Application is accessible"
        else
            warn "Could not verify application accessibility"
        fi
    fi

    # Show recent logs
    log "Recent application logs:"
    docker compose -f "$DOCKER_COMPOSE_FILE" logs --tail=20 app

    success "Deployment verification completed"
}

################################################################################
# Rollback Function
################################################################################

rollback() {
    error "Deployment failed! Rolling back..."

    # Disable maintenance mode if enabled
    disable_maintenance_mode 2>/dev/null || true

    error "Rollback completed. Please check logs and try again."
    error "To rollback Docker image, deploy the previous working version:"
    error "  ./scripts/deploy-update.sh v[previous-version]"
    exit 1
}

################################################################################
# Main Function
################################################################################

main() {
    # Setup error handler
    trap rollback ERR

    log "==================================================================="
    log "          Registro VPS Deployment Update"
    log "==================================================================="
    echo ""

    # Parse arguments
    parse_arguments "$@"

    # Step 1: Check prerequisites
    check_prerequisites

    # Step 2: Confirm update
    confirm_update

    # Step 3: Enable maintenance mode
    enable_maintenance_mode

    # Step 4: Backup database
    backup_database

    # Step 5: Pull Docker image from GHCR
    pull_docker_image

    # Step 6: Restart services with new image
    restart_services

    # Step 7: Run migrations
    run_migrations

    # Step 8: Clear and rebuild caches
    clear_and_rebuild_caches

    # Step 9: Verify deployment
    verify_deployment

    # Step 10: Disable maintenance mode
    disable_maintenance_mode

    echo ""
    log "==================================================================="
    success "          Update completed successfully!"
    log "==================================================================="
    echo ""
    log "Deployment summary:"
    log "  - Version deployed: $VERSION"
    log "  - Docker image: ghcr.io/patrykgielo/registro:$VERSION"
    log "  - Database backup: $([ "$SKIP_BACKUP" == true ] && echo "Skipped" || echo "Created")"
    log "  - Migrations: $([ "$SKIP_MIGRATIONS" == true ] && echo "Skipped" || echo "Run")"
    echo ""
    log "Application is running at: $(grep "^APP_URL=" "${APP_DIR}/.env" | cut -d'=' -f2)"
    echo ""
}

# Run main function
main "$@"

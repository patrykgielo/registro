#!/usr/bin/env bash

################################################################################
# deploy-init.sh - First-time VPS deployment initialization script
#
# This script handles the complete initial deployment of the application
# on a fresh VPS server. Run this ONCE during initial setup.
#
# Usage: ./scripts/deploy-init.sh
#
# Prerequisites:
#   - Docker and Docker Compose installed
#   - Domain DNS configured and pointing to VPS
#   - Ports 80 and 443 open in firewall
#   - Repository cloned to /var/www/registro (or adjust paths)
#
# What this script does:
#   1. Validates prerequisites
#   2. Creates production .env file
#   3. Generates Let's Encrypt SSL certificates
#   4. Builds and starts Docker containers
#   5. Runs database migrations and seeds
#   6. Creates admin user
#   7. Optimizes Laravel caches
#   8. Verifies deployment
#
# Author: Registro Development Team
# Version: 1.0.0
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
readonly ENV_FILE="${APP_DIR}/.env"
readonly ENV_EXAMPLE="${APP_DIR}/.env.production.example"
readonly DOCKER_COMPOSE_FILE="${PROJECT_ROOT}/docker-compose.prod.yml"
# Generated from the tracked app.prod-tls.conf template with the real certificate
# domain substituted in. Gitignored on purpose -- see setup_ssl_certificates().
readonly TLS_CONF_NAME="app.prod-tls.local.conf"

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
# Validation Functions
################################################################################

check_prerequisites() {
    log "Checking prerequisites..."

    # Check if running as root or with sudo
    if [[ $EUID -ne 0 ]]; then
        error "This script must be run as root or with sudo"
        exit 1
    fi

    # Check Docker
    if ! command -v docker &> /dev/null; then
        error "Docker is not installed. Please install Docker first."
        exit 1
    fi

    # Check Docker Compose
    if ! docker compose version &> /dev/null; then
        error "Docker Compose is not installed or outdated (need compose v2)"
        exit 1
    fi

    # Check if project directory exists
    if [[ ! -d "$PROJECT_ROOT" ]]; then
        error "Project directory not found: $PROJECT_ROOT"
        exit 1
    fi

    # Check if docker-compose.prod.yml exists
    if [[ ! -f "$DOCKER_COMPOSE_FILE" ]]; then
        error "Production docker-compose file not found: $DOCKER_COMPOSE_FILE"
        exit 1
    fi

    success "All prerequisites met"
}

check_env_file() {
    if [[ -f "$ENV_FILE" ]]; then
        warn "Production .env file already exists at: $ENV_FILE"
        prompt "Do you want to overwrite it? (y/N): "
        read -r response
        if [[ ! "$response" =~ ^[Yy]$ ]]; then
            log "Using existing .env file"
            return 0
        fi
    fi
    return 1
}

################################################################################
# Setup Functions
################################################################################

create_env_file() {
    log "Creating production .env file..."

    if [[ ! -f "$ENV_EXAMPLE" ]]; then
        error ".env.production.example not found. Creating minimal .env..."
        cp "${APP_DIR}/.env.example" "$ENV_FILE"
        warn "Please edit $ENV_FILE manually before proceeding"
        exit 1
    fi

    cp "$ENV_EXAMPLE" "$ENV_FILE"

    # Prompt for critical configuration
    prompt "Enter your domain name (e.g., registro.com): "
    read -r domain
    sed -i "s/APP_URL=.*/APP_URL=https:\/\/${domain}/" "$ENV_FILE"

    # Generate APP_KEY
    log "Generating Laravel application key..."
    docker compose -f "$DOCKER_COMPOSE_FILE" run --rm app php artisan key:generate --force

    success "Production .env file created at: $ENV_FILE"
    warn "IMPORTANT: Edit $ENV_FILE and update database passwords, Redis password, and SMTP credentials"
    prompt "Press Enter to continue after editing .env file..."
    read -r
}

setup_ssl_certificates() {
    log "Setting up Let's Encrypt SSL certificates..."

    # Extract domain from .env
    local domain
    domain=$(grep "^APP_URL=" "$ENV_FILE" | cut -d'=' -f2 | sed 's|https\?://||' | sed 's|/.*||')

    if [[ -z "$domain" ]]; then
        error "Domain not found in .env file (APP_URL)"
        exit 1
    fi

    log "Domain: $domain"

    # Check if certificates already exist
    if [[ -d "/etc/letsencrypt/live/$domain" ]]; then
        warn "Certificates already exist for $domain"
        prompt "Do you want to renew them? (y/N): "
        read -r response
        if [[ ! "$response" =~ ^[Yy]$ ]]; then
            log "Skipping certificate generation"
            return 0
        fi
    fi

    # Install certbot if not present
    if ! command -v certbot &> /dev/null; then
        log "Installing certbot..."
        if command -v apt-get &> /dev/null; then
            apt-get update && apt-get install -y certbot python3-certbot-nginx
        elif command -v yum &> /dev/null; then
            yum install -y certbot python3-certbot-nginx
        else
            error "Unable to install certbot. Please install manually."
            exit 1
        fi
    fi

    # Webroot must be the directory nginx actually serves /.well-known from --
    # docker-compose.prod.yml bind-mounts the host's /var/www/letsencrypt into
    # the nginx container and both configs serve the challenge from there. The
    # previous /var/www/certbot was served by nothing.
    local webroot="/var/www/letsencrypt"
    mkdir -p "$webroot"

    # Only request www. if it actually resolves. Let's Encrypt fails the WHOLE
    # request when any single name fails validation, and technical hostnames
    # like srvNNNNN.hstgr.cloud have no www record.
    local domains=(-d "$domain")
    if host "www.$domain" >/dev/null 2>&1 || getent hosts "www.$domain" >/dev/null 2>&1; then
        domains+=(-d "www.$domain")
        log "www.$domain resolves -- including it in the certificate"
    else
        warn "www.$domain does not resolve -- requesting a single-name certificate"
    fi

    local temp_nginx_started=false
    # Not `docker ps | grep -qx`: under `set -o pipefail` a SIGPIPE'd docker ps
    # makes this read as "nginx is not running", and the branch below then binds
    # a temporary container to port 80 that the real nginx already holds --
    # turning a working stack into a failed certificate request.
    if [[ $'\n'"$(docker ps --format '{{.Names}}')"$'\n' != *$'\n'registro-nginx$'\n'* ]]; then
        log "Starting temporary Nginx container for ACME challenge..."
        docker run --rm -d --name temp-nginx -p 80:80 \
            -v "${webroot}:/usr/share/nginx/html:ro" nginx:alpine >/dev/null
        temp_nginx_started=true
    else
        log "Using the running registro-nginx to serve the ACME challenge"
    fi

    # Dry run FIRST, against the ACME staging server. Let's Encrypt allows five
    # failed validations per hour per account; without this, one typo in nginx
    # or DNS locks certificate issuance for the next 60 minutes.
    log "Certbot dry run (ACME staging)..."
    if ! certbot certonly --webroot -w "$webroot" "${domains[@]}" \
        --email "admin@$domain" --agree-tos --no-eff-email --non-interactive --dry-run; then
        [[ "$temp_nginx_started" == true ]] && docker stop temp-nginx >/dev/null 2>&1
        error "Certbot dry run failed -- NOT requesting a real certificate."
        error "Fix DNS or the HTTP challenge path first; the rate limit is intact."
        exit 1
    fi
    success "Dry run passed"

    log "Requesting the real certificate..."
    certbot certonly --webroot -w "$webroot" "${domains[@]}" \
        --email "admin@$domain" --agree-tos --no-eff-email --non-interactive

    [[ "$temp_nginx_started" == true ]] && docker stop temp-nginx >/dev/null 2>&1

    # The certificate path placeholder lives in the TLS config, not in
    # app.prod.conf -- that one deliberately contains no ssl_certificate at all,
    # so nginx can start before any certificate exists.
    #
    # Generate into a SEPARATE, gitignored file rather than editing the tracked
    # template in place. scripts/server/deploy.sh runs `git checkout --force` on
    # every deploy and rollback, which would silently revert an in-place edit
    # back to the CERT_DOMAIN placeholder. nginx keeps serving from its loaded
    # config until something recreates the container -- a reboot, an image
    # update -- and only then refuses to start, taking down HTTP and HTTPS
    # together, weeks after the change that caused it.
    local tls_template="${PROJECT_ROOT}/docker/nginx/production/app.prod-tls.conf"
    local tls_config="${PROJECT_ROOT}/docker/nginx/production/${TLS_CONF_NAME}"
    if [[ -f "$tls_template" ]]; then
        sed "s|/etc/letsencrypt/live/CERT_DOMAIN/|/etc/letsencrypt/live/${domain}/|g" \
            "$tls_template" >"$tls_config"
        success "${TLS_CONF_NAME} generated, pointing at /etc/letsencrypt/live/${domain}/"
    else
        error "$tls_template not found -- cannot wire up the certificate"
        exit 1
    fi

    # Activate TLS by switching which config nginx mounts. Reversible: set this
    # back to app.prod.conf and re-run `up -d nginx`.
    if grep -q '^NGINX_CONF=' "$ENV_FILE" 2>/dev/null; then
        sed -i "s|^NGINX_CONF=.*|NGINX_CONF=${TLS_CONF_NAME}|" "$ENV_FILE"
    else
        echo "NGINX_CONF=${TLS_CONF_NAME}" >> "$ENV_FILE"
    fi
    log "NGINX_CONF=${TLS_CONF_NAME} written to .env -- run: docker compose -f $DOCKER_COMPOSE_FILE up -d nginx"

    success "SSL certificates generated successfully"
}

################################################################################
# Deployment Functions
################################################################################

build_and_start_containers() {
    log "Pulling and starting Docker containers..."

    cd "$PROJECT_ROOT"

    # docker-compose.prod.yml declares no build context -- the application image
    # is built by CI and pulled from GHCR. `docker compose build` would fail here.
    log "Pulling Docker images..."
    docker compose -f "$DOCKER_COMPOSE_FILE" pull

    # Start containers
    log "Starting containers..."
    docker compose -f "$DOCKER_COMPOSE_FILE" up -d

    # Wait for services to be healthy
    log "Waiting for services to be healthy (timeout: 60s)..."
    local timeout=60
    local elapsed=0
    while [[ $elapsed -lt $timeout ]]; do
        # Not `ps | grep -q healthy`: under `set -o pipefail` grep -q exits on the
        # first match and the upstream takes SIGPIPE, so a healthy stack reads as
        # unhealthy and this loop burns its full timeout.
        if [[ "$(docker compose -f "$DOCKER_COMPOSE_FILE" ps)" == *healthy* ]]; then
            success "All services are healthy"
            return 0
        fi
        sleep 5
        elapsed=$((elapsed + 5))
        log "Still waiting... (${elapsed}s elapsed)"
    done

    warn "Timeout reached. Some services may not be fully healthy yet."
    docker compose -f "$DOCKER_COMPOSE_FILE" ps
}

run_migrations_and_seeds() {
    log "Running database migrations and seeds..."

    # Run migrations
    log "Executing migrations..."
    docker compose -f "$DOCKER_COMPOSE_FILE" exec -T app php artisan migrate --force

    # Seed bootstrap data only. This runs exactly once, on an empty database,
    # during first installation -- never from deploy-update.sh. VehicleTypeSeeder
    # is not seeded: it is a leftover from the automotive project this
    # infrastructure was inherited from and has no meaning in Registro.
    log "Seeding database..."
    docker compose -f "$DOCKER_COMPOSE_FILE" exec -T app php artisan db:seed --class=RolePermissionSeeder --force
    docker compose -f "$DOCKER_COMPOSE_FILE" exec -T app php artisan db:seed --class=SettingSeeder --force
    docker compose -f "$DOCKER_COMPOSE_FILE" exec -T app php artisan db:seed --class=EmailTemplateSeeder --force

    success "Database migrations and seeds completed"
}

create_admin_user() {
    log "Creating admin user..."

    prompt "Do you want to create an admin user now? (Y/n): "
    read -r response
    if [[ "$response" =~ ^[Nn]$ ]]; then
        warn "Skipping admin user creation. You can create one later with:"
        warn "  docker compose -f $DOCKER_COMPOSE_FILE exec app php artisan make:filament-user"
        return 0
    fi

    docker compose -f "$DOCKER_COMPOSE_FILE" exec app php artisan make:filament-user

    success "Admin user created successfully"
}

optimize_application() {
    log "Optimizing Laravel application..."

    docker compose -f "$DOCKER_COMPOSE_FILE" exec -T app php artisan optimize
    docker compose -f "$DOCKER_COMPOSE_FILE" exec -T app php artisan config:cache
    docker compose -f "$DOCKER_COMPOSE_FILE" exec -T app php artisan route:cache
    docker compose -f "$DOCKER_COMPOSE_FILE" exec -T app php artisan view:cache
    docker compose -f "$DOCKER_COMPOSE_FILE" exec -T app php artisan filament:optimize

    success "Application optimized"
}

################################################################################
# Verification Functions
################################################################################

verify_deployment() {
    log "Verifying deployment..."

    # Extract domain from .env
    local domain
    domain=$(grep "^APP_URL=" "$ENV_FILE" | cut -d'=' -f2 | sed 's|https\?://||' | sed 's|/.*||')

    # Check if application is accessible
    if curl -sSf "https://$domain" &> /dev/null; then
        success "Application is accessible at: https://$domain"
    else
        warn "Could not verify application accessibility. Please check manually."
    fi

    # Check if admin panel is accessible
    if curl -sSf "https://$domain/admin" &> /dev/null; then
        success "Admin panel is accessible at: https://$domain/admin"
    else
        warn "Could not verify admin panel accessibility. Please check manually."
    fi

    # Display container status
    log "Container status:"
    docker compose -f "$DOCKER_COMPOSE_FILE" ps

    # Display logs (last 20 lines)
    log "Recent logs:"
    docker compose -f "$DOCKER_COMPOSE_FILE" logs --tail=20
}

################################################################################
# Main Function
################################################################################

main() {
    log "==================================================================="
    log "          Registro VPS Deployment Initialization"
    log "==================================================================="
    echo ""

    # Step 1: Check prerequisites
    check_prerequisites

    # Step 2: Create .env file
    if ! check_env_file; then
        create_env_file
    fi

    # Step 3: Setup SSL certificates
    setup_ssl_certificates

    # Step 4: Build and start containers
    build_and_start_containers

    # Step 5: Run migrations and seeds
    run_migrations_and_seeds

    # Step 6: Create admin user
    create_admin_user

    # Step 7: Optimize application
    optimize_application

    # Step 8: Verify deployment
    verify_deployment

    echo ""
    log "==================================================================="
    success "          Deployment completed successfully!"
    log "==================================================================="
    echo ""
    log "Next steps:"
    log "  1. Visit your application at: https://$(grep "^APP_URL=" "$ENV_FILE" | cut -d'=' -f2 | sed 's|https\?://||')"
    log "  2. Configure email settings in Admin Panel → System Settings"
    log "  3. Setup automated backups with: scripts/backup-database.sh"
    log "  4. Configure automatic certificate renewal:"
    log "     crontab -e"
    log "     0 0 * * * certbot renew --quiet && docker compose -f $DOCKER_COMPOSE_FILE restart nginx"
    log ""
    log "For future deployments, use: scripts/deploy-update.sh"
    log ""
}

# Run main function
main "$@"

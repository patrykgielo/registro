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
#   6. Creates the Registro owner (platform super-admin)
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
        warn "Overwriting resets it to the example. Existing APP_KEY, DB_PASSWORD,"
        warn "DB_ROOT_PASSWORD and REDIS_PASSWORD are CARRIED OVER, not regenerated --"
        warn "rotating them would lock the app out of the existing MySQL volume and"
        warn "make every encrypted column and every session unreadable."
        warn "Everything else in the file (mail, API keys, NGINX_CONF) is lost."
        prompt "Do you want to overwrite it? (y/N): "
        read -r response
        if [[ ! "$response" =~ ^[Yy]$ ]]; then
            log "Using existing .env file"
            return 0
        fi
        cp "$ENV_FILE" "${ENV_FILE}.bak-$(date +%Y%m%d%H%M%S)"
        warn "Previous .env backed up alongside it"
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

    # Read the existing secrets BEFORE the example overwrites them. Regenerating
    # these on a server that already has data is unrecoverable: the mysql_data
    # volume was initialised with the old DB passwords, and APP_KEY decrypts
    # every `encrypted` column and signs every session. `check_env_file` warns
    # about this, and this is what makes the warning true.
    local prior_app_key="" prior_db_pass="" prior_db_root="" prior_redis_pass=""
    if [[ -f "$ENV_FILE" ]]; then
        prior_app_key="$(read_env_value APP_KEY)"
        prior_db_pass="$(read_env_value DB_PASSWORD)"
        prior_db_root="$(read_env_value DB_ROOT_PASSWORD)"
        prior_redis_pass="$(read_env_value REDIS_PASSWORD)"
    fi

    cp "$ENV_EXAMPLE" "$ENV_FILE"

    # Prompt for critical configuration
    prompt "Enter your domain name (e.g., registro.com): "
    read -r domain
    write_env_var "APP_URL" "https://${domain}"
    # APP_DOMAIN drives tenant subdomain routing and docker-compose.prod.yml
    # refuses to start without it.
    write_env_var "APP_DOMAIN" "$domain"

    # Secrets are generated HERE, on the host, before anything touches
    # docker compose.
    #
    # The previous implementation ran `docker compose run --rm app php artisan
    # key:generate`, which could never have worked: docker-compose.prod.yml
    # bind-mounts no .env into the app service, so artisan rewrote a copy inside
    # an ephemeral container and the result was discarded. It failed silently.
    #
    # It now cannot even reach that point -- APP_KEY and REDIS_PASSWORD use the
    # ${VAR:?} form, which compose evaluates for EVERY subcommand, so a compose
    # call against an .env that still has them blank aborts the whole script.
    # Generating locally fixes both problems at once.
    log "Restoring carried-over secrets and generating any that are missing..."

    # Carried-over values win; anything still empty gets a fresh one. Laravel's
    # own format for the default AES-256-CBC cipher is base64: plus 32 raw bytes.
    set_secret "APP_KEY"          "${prior_app_key:-base64:$(openssl rand -base64 32)}"
    set_secret "REDIS_PASSWORD"   "${prior_redis_pass:-$(openssl rand -base64 32 | tr -d '/+=')}"
    set_secret "DB_PASSWORD"      "${prior_db_pass:-$(openssl rand -base64 32 | tr -d '/+=')}"
    set_secret "DB_ROOT_PASSWORD" "${prior_db_root:-$(openssl rand -base64 32 | tr -d '/+=')}"

    chmod 600 "$ENV_FILE"
    success "Production .env file created at: $ENV_FILE (mode 600)"
    warn "APP_KEY, REDIS_PASSWORD, DB_PASSWORD and DB_ROOT_PASSWORD are set."
    warn "Still REQUIRED by hand: MAIL_USERNAME, MAIL_PASSWORD, GOOGLE_MAPS_API_KEY,"
    warn "GOOGLE_MAPS_MAP_ID, SMSAPI_TOKEN, SMSAPI_WEBHOOK_SECRET."
    warn "Verify with: ./scripts/validate-env.sh production"
    prompt "Press Enter to continue after editing .env file..."
    read -r
}

# Reads a single value out of $ENV_FILE, or empty if the key is absent.
#
# The `|| true` is load-bearing. This script runs `set -e -o pipefail`, and a
# bare `x="$(grep ... | cut ...)"` is an assignment whose status IS the
# pipeline's, so a missing key propagates grep's exit 1 and kills the script --
# with no message, mid-way through writing .env, leaving a half-built file and
# an operator with no idea what happened.
read_env_value() {
    grep -m1 "^${1}=" "$ENV_FILE" 2>/dev/null | cut -d= -f2- || true
}

# Only fills a variable that is empty -- re-running deploy-init.sh must never
# rotate a live APP_KEY (every encrypted value and every session breaks) or a
# database password the running MySQL was initialised with.
set_secret() {
    local key="$1" value="$2" current
    current="$(read_env_value "$key")"

    if [[ -n "$current" ]]; then
        log "${key} already set -- left untouched"
        return 0
    fi

    write_env_var "$key" "$value"
    log "${key} generated"
}

setup_ssl_certificates() {
    log "Setting up Let's Encrypt SSL certificates..."

    # Extract domain from .env
    local domain
    domain="$(read_env_value APP_URL)"
    domain="${domain#*://}"; domain="${domain%%/*}"

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
            # NOT a bare `return 0`. Skipping renewal must still wire up the
            # config: the caller may have just recreated .env from the example,
            # where NGINX_CONF=app.prod.conf, and returning here would silently
            # drop a TLS-enabled server back to plain HTTP.
            log "Skipping certificate generation -- wiring up the existing certificate"
            wire_up_tls "$domain"
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
    # --cert-name pins the lineage, and with it the directory name under
    # /etc/letsencrypt/live/. Without it certbot names the directory after the
    # first -d and appends -0001, -0002 ... whenever the set of names changes --
    # which happens here the first time www.<host> starts resolving, since it is
    # only requested when it does. nginx would then be configured for a directory
    # that is no longer the current one. --expand allows adding that name to the
    # existing lineage instead of starting a new one.
    if ! certbot certonly --webroot -w "$webroot" "${domains[@]}" \
        --cert-name "$domain" --expand \
        --email "admin@$domain" --agree-tos --no-eff-email --non-interactive --dry-run; then
        [[ "$temp_nginx_started" == true ]] && docker stop temp-nginx >/dev/null 2>&1
        error "Certbot dry run failed -- NOT requesting a real certificate."
        error "Fix DNS or the HTTP challenge path first; the rate limit is intact."
        exit 1
    fi
    success "Dry run passed"

    log "Requesting the real certificate..."
    certbot certonly --webroot -w "$webroot" "${domains[@]}" \
        --cert-name "$domain" --expand \
        --email "admin@$domain" --agree-tos --no-eff-email --non-interactive

    [[ "$temp_nginx_started" == true ]] && docker stop temp-nginx >/dev/null 2>&1

    wire_up_tls "$domain"
    success "SSL certificates generated successfully"
}

# Renders the TLS config and switches nginx onto it.
#
# The certificate path placeholder lives in the TLS config, not in
# app.prod.conf -- that one deliberately contains no ssl_certificate at all, so
# nginx can start before any certificate exists.
#
# Renders into a SEPARATE, gitignored file rather than editing the tracked
# template in place. scripts/server/deploy.sh runs `git checkout --force` on
# every deploy and rollback, which would silently revert an in-place edit back
# to the CERT_DOMAIN placeholder. nginx keeps serving from its loaded config
# until something recreates the container -- a reboot, an image update -- and
# only then refuses to start, taking down HTTP and HTTPS together, weeks after
# the change that caused it.
wire_up_tls() {
    local domain="$1"
    local tls_template="${PROJECT_ROOT}/docker/nginx/production/app.prod-tls.conf"
    local tls_config="${PROJECT_ROOT}/docker/nginx/production/${TLS_CONF_NAME}"
    local cert_dir

    [[ -f "$tls_template" ]] || { error "$tls_template not found -- cannot wire up the certificate"; exit 1; }

    # certbot names the live directory after the FIRST -d, and appends -0001,
    # -0002 ... whenever the set of names changes -- which happens here the first
    # time www.$domain starts resolving, since it is added conditionally above.
    # Trusting $domain would point nginx at a directory that does not exist.
    cert_dir="$(resolve_cert_dir "$domain")"
    [[ -n "$cert_dir" ]] || { error "No certificate directory under /etc/letsencrypt/live for ${domain}"; exit 1; }
    [[ "$cert_dir" == "$domain" ]] || warn "certbot used /etc/letsencrypt/live/${cert_dir}, not ${domain}"

    # Render to a temp file, verify, then move: sed exits 0 even when it
    # substitutes nothing, so a template whose placeholder was renamed would
    # otherwise yield a config nginx cannot start on -- and a truncated write
    # would sit on disk until the next reboot recreated nginx onto it.
    sed "s|/etc/letsencrypt/live/CERT_DOMAIN/|/etc/letsencrypt/live/${cert_dir}/|g" \
        "$tls_template" >"${tls_config}.tmp"
    if grep -q 'CERT_DOMAIN' "${tls_config}.tmp"; then
        rm -f "${tls_config}.tmp"
        error "Rendered TLS config still contains CERT_DOMAIN -- template changed shape"
        exit 1
    fi
    mv -f "${tls_config}.tmp" "$tls_config"
    success "${TLS_CONF_NAME} generated, pointing at /etc/letsencrypt/live/${cert_dir}/"

    # Record which directory was used. scripts/server/deploy.sh re-renders this
    # file after every `git checkout` and has no other way to know, so without
    # this line every deploy after a -0001 rename would either point nginx at a
    # missing directory or abort.
    write_env_var "CERT_DIR" "$cert_dir"

    # Activate TLS by switching which config nginx mounts. Reversible: set this
    # back to app.prod.conf and re-run `up -d nginx`.
    write_env_var "NGINX_CONF" "$TLS_CONF_NAME"
    log "NGINX_CONF=${TLS_CONF_NAME} written to .env -- run: docker compose -f $DOCKER_COMPOSE_FILE up -d nginx"
}

# Newest matching live directory: exact name first, then the -NNNN variants
# certbot creates when the SAN set changes.
resolve_cert_dir() {
    local domain="$1" d
    if [[ -d "/etc/letsencrypt/live/${domain}" ]]; then
        echo "$domain"
        return 0
    fi
    for d in $(ls -1d "/etc/letsencrypt/live/${domain}"-[0-9][0-9][0-9][0-9] 2>/dev/null | sort -r); do
        basename "$d"
        return 0
    done
}

# Sets KEY=VALUE in $ENV_FILE, replacing the first existing line or appending.
#
# Deliberately NOT `sed -i "s|^KEY=.*|KEY=$value|"`. A value is arbitrary text --
# carried-over passwords especially -- and sed would interpret `|` as the
# delimiter and `&` as "the whole match". A password of `p@ss|word&x` silently
# becomes `p@ss`, corrupting the very credential this code exists to preserve.
# Verified: that exact input truncates under sed.
#
# awk with ENVIRON, not `-v`: awk processes backslash escapes in `-v`
# assignments, so a value containing `\n` would be mangled too. `index($0, k"=")`
# instead of a regex means the key is never treated as a pattern.
write_env_var() {
    local key="$1" value="$2" tmp mode
    tmp="$(mktemp)"
    mode="$(stat -c %a "$ENV_FILE" 2>/dev/null || echo 600)"

    ENV_WRITE_KEY="$key" ENV_WRITE_VAL="$value" awk '
        BEGIN { k = ENVIRON["ENV_WRITE_KEY"]; v = ENVIRON["ENV_WRITE_VAL"]; done = 0 }
        !done && index($0, k "=") == 1 { print k "=" v; done = 1; next }
        { print }
        END { if (!done) print k "=" v }
    ' "$ENV_FILE" >"$tmp"

    cat "$tmp" >"$ENV_FILE"   # preserve the original inode and ownership
    rm -f "$tmp"
    chmod "$mode" "$ENV_FILE"
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
        # Two traps here. First, `ps | grep -q healthy` under `set -o pipefail`
        # SIGPIPEs the upstream, so a healthy stack reads as unhealthy. Second --
        # and this one survived the grep -q fix -- "unhealthy" CONTAINS
        # "healthy", so a bare *healthy* match reports success for a stack that
        # is explicitly broken. Match the parenthesised status docker renders.
        if [[ "$(docker compose -f "$DOCKER_COMPOSE_FILE" ps)" == *"(healthy)"* ]]; then
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
    log "Creating the Registro owner (platform super-admin)..."

    # NOT `make:filament-user`, which this step used to call.
    #
    # That command builds the user around a `name` field this schema does not
    # have -- the column was dropped in favour of first_name/last_name, and
    # `name` is a read-only accessor -- so mass assignment silently discards it.
    # It also assigns no role, while User::canAccessPanel() requires super-admin
    # for /platform and one of super-admin|admin|staff for /admin. It then prints
    # "Success! ... may now log in", which is untrue. Verified on a real server:
    # first_name=NULL, last_name=NULL, no role, and a success message.
    #
    # registro:create-owner seeds the roles (without DatabaseSeeder's demo data),
    # sets the name fields that exist, grants super-admin, and asserts
    # canAccessPanel() before reporting success.
    prompt "Do you want to create the owner account now? (Y/n): "
    read -r response
    if [[ "$response" =~ ^[Nn]$ ]]; then
        warn "Skipping. NOTHING can administer this installation until you run:"
        warn "  docker compose -f $DOCKER_COMPOSE_FILE exec app php artisan registro:create-owner"
        return 0
    fi

    docker compose -f "$DOCKER_COMPOSE_FILE" exec app php artisan registro:create-owner

    success "Owner account created"
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
    domain="$(read_env_value APP_URL)"
    domain="${domain#*://}"; domain="${domain%%/*}"

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

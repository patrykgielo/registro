#!/bin/bash

#########################################################
# Staging Server Setup Script - Registro
#
# Description: Complete setup for new staging VPS
# Target: 45.93.138.193 (Hostinger VPS)
# Domain: srv1203357.hstgr.cloud
#
# Usage:
#   # Run on LOCAL machine (copies via SSH):
#   ./scripts/setup-staging-server.sh
#
# Requirements:
#   - SSH access to 45.93.138.193
#   - Root or sudo access on target server
#   - srv1203357.hstgr.cloud DNS configured
#
#########################################################

set -e  # Exit on error

# ======================
# Configuration
# ======================

# Load from environment or use defaults
# IMPORTANT: Never commit actual IP addresses to the repository
# Set these in your shell or use: source ~/.registro-staging.env
STAGING_HOST="${STAGING_VPS_HOST:-srv1203357.hstgr.cloud}"
STAGING_USER="${STAGING_VPS_USER:-root}"  # Will create 'deploy' user during setup
STAGING_DOMAIN="${STAGING_DOMAIN:-srv1203357.hstgr.cloud}"
PROJECT_DIR="/var/www/registro"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

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
    read -p "$(echo -e ${YELLOW}$1 [y/N]:${NC} )" -n 1 -r
    echo
    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
        log_error "Setup cancelled by user"
        exit 1
    fi
}

# ======================
# Main Setup
# ======================

print_header "🚀 Registro Staging Server Setup"

log "Target Server: ${STAGING_HOST}"
log "Domain: ${STAGING_DOMAIN}"
log "Project Path: ${PROJECT_DIR}"
echo ""

confirm "This will set up a NEW staging environment. Continue?"

# Test SSH connection
print_header "Step 1: Verify SSH Access"
if ssh -o ConnectTimeout=5 -o BatchMode=yes ${STAGING_USER}@${STAGING_HOST} "echo 'SSH connection successful'" 2>/dev/null; then
    log_success "SSH connection verified"
else
    log_error "Cannot connect to ${STAGING_HOST}. Check SSH access and credentials."
    log "Expected: ssh ${STAGING_USER}@${STAGING_HOST}"
    exit 1
fi

# Run remote setup commands
print_header "Step 2: Running Remote Setup Commands"

ssh ${STAGING_USER}@${STAGING_HOST} 'bash -s' << 'ENDSSH'
#!/bin/bash
set -e

echo "=================================================="
echo "🔧 Remote Server Setup Starting"
echo "=================================================="

# Update system
echo "📦 Updating system packages..."
apt-get update -qq
apt-get upgrade -y -qq

# Install essential packages
echo "📦 Installing essential packages..."
apt-get install -y -qq \
    curl \
    wget \
    git \
    unzip \
    ca-certificates \
    gnupg \
    lsb-release \
    ufw \
    htop \
    vim \
    certbot

# Install Docker
echo "🐳 Installing Docker..."
if ! command -v docker &> /dev/null; then
    curl -fsSL https://get.docker.com -o get-docker.sh
    sh get-docker.sh
    rm get-docker.sh
    systemctl enable docker
    systemctl start docker
    echo "✅ Docker installed successfully"
else
    echo "✅ Docker already installed"
fi

# Install Docker Compose V2
echo "🐳 Installing Docker Compose V2..."
if ! docker compose version &> /dev/null; then
    apt-get install -y docker-compose-plugin
    echo "✅ Docker Compose installed successfully"
else
    echo "✅ Docker Compose already installed"
fi

# Verify Docker installation
docker --version
docker compose version

# Configure UFW Firewall
echo "🔥 Configuring UFW firewall..."
ufw --force reset
ufw default deny incoming
ufw default allow outgoing
ufw allow 22/tcp    # SSH
ufw allow 80/tcp    # HTTP
ufw allow 443/tcp   # HTTPS
ufw allow 8025/tcp  # Mailpit UI (staging only)

# Enable UFW without confirmation
yes | ufw enable

echo "✅ Firewall configured:"
ufw status verbose

# Install ufw-docker script (prevent Docker bypass)
echo "🔥 Installing ufw-docker security script..."
wget -O /usr/local/bin/ufw-docker https://github.com/chaifeng/ufw-docker/raw/master/ufw-docker
chmod +x /usr/local/bin/ufw-docker

# Configure timezone
echo "🕐 Setting timezone to Europe/Warsaw..."
timedatectl set-timezone Europe/Warsaw

# Create deploy user (if not exists)
if ! id -u deploy &>/dev/null; then
    echo "👤 Creating deploy user..."
    adduser --disabled-password --gecos "" deploy
    usermod -aG sudo deploy
    usermod -aG docker deploy

    # Copy root's SSH keys to deploy user
    mkdir -p /home/deploy/.ssh
    cp /root/.ssh/authorized_keys /home/deploy/.ssh/authorized_keys
    chown -R deploy:deploy /home/deploy/.ssh
    chmod 700 /home/deploy/.ssh
    chmod 600 /home/deploy/.ssh/authorized_keys

    echo "✅ deploy user created with docker and sudo access"
else
    echo "✅ deploy user already exists"
fi

# Create project directory
echo "📁 Creating project directory..."
mkdir -p /var/www/registro
chown -R deploy:deploy /var/www
chmod 755 /var/www

echo "=================================================="
echo "✅ Remote Server Setup Complete"
echo "=================================================="

ENDSSH

log_success "Remote setup completed"

# Clone repository
print_header "Step 3: Cloning Repository"

ssh ${STAGING_USER}@${STAGING_HOST} << 'ENDSSH'
#!/bin/bash
set -e

cd /var/www

if [ -d "registro/.git" ]; then
    echo "📦 Repository already cloned, updating..."
    cd registro
    git fetch origin
    git checkout develop
    git pull origin develop
else
    echo "📦 Cloning repository from GitHub..."
    git clone https://github.com/patrykgielo/registro.git
    cd registro
    git checkout develop
fi

echo "✅ Repository ready at /var/www/registro"
echo "Current branch: $(git branch --show-current)"
echo "Latest commit: $(git log -1 --oneline)"

ENDSSH

log_success "Repository cloned"

# Setup SSL with Let's Encrypt
print_header "Step 4: SSL Certificate Setup"

log_warning "IMPORTANT: DNS for ${STAGING_DOMAIN} must point to ${STAGING_HOST}"
log "Verify with: dig ${STAGING_DOMAIN} +short"
echo ""
confirm "Is DNS configured correctly?"

ssh ${STAGING_USER}@${STAGING_HOST} << ENDSSH
#!/bin/bash
set -e

echo "🔐 Obtaining SSL certificate from Let's Encrypt..."

# Stop any services using port 80
docker compose -f /var/www/registro/docker-compose.staging.yml down 2>/dev/null || true

# Obtain certificate
certbot certonly --standalone \
    --non-interactive \
    --agree-tos \
    --email dev@registro.local \
    --domains ${STAGING_DOMAIN}

# Setup auto-renewal
echo "⏰ Setting up auto-renewal..."
systemctl enable certbot.timer
systemctl start certbot.timer

echo "✅ SSL certificate installed for ${STAGING_DOMAIN}"
ls -l /etc/letsencrypt/live/${STAGING_DOMAIN}/

ENDSSH

log_success "SSL certificate installed"

# Create .env file
print_header "Step 5: Environment Configuration"

log "Creating .env.staging file..."
log_warning "You will need to manually edit /var/www/registro/.env.staging with:"
log "  - Database passwords"
log "  - Redis password"
log "  - Google Maps API key"
log "  - SMSAPI token (if needed)"

ssh ${STAGING_USER}@${STAGING_HOST} << 'ENDSSH'
#!/bin/bash
set -e

cd /var/www/registro

# Copy example file
cp .env.staging.example .env.staging

# Generate APP_KEY
echo "🔑 Generating APP_KEY..."
docker run --rm -v $(pwd):/var/www -w /var/www php:8.2-fpm-alpine php artisan key:generate --env=staging --show > /tmp/appkey.txt
APP_KEY=$(cat /tmp/appkey.txt)
sed -i "s|^APP_KEY=.*|APP_KEY=${APP_KEY}|" .env.staging
rm /tmp/appkey.txt

# Generate secure passwords
echo "🔐 Generating secure passwords..."
DB_PASSWORD=$(openssl rand -base64 32 | tr -d "=+/" | cut -c1-32)
REDIS_PASSWORD=$(openssl rand -base64 32 | tr -d "=+/" | cut -c1-32)

sed -i "s|^DB_PASSWORD=.*|DB_PASSWORD=${DB_PASSWORD}|" .env.staging
sed -i "s|^REDIS_PASSWORD=.*|REDIS_PASSWORD=${REDIS_PASSWORD}|" .env.staging

echo "✅ Environment file created at .env.staging"
echo ""
echo "⚠️  IMPORTANT: Generated passwords (save these securely!):"
echo "   DB_PASSWORD=${DB_PASSWORD}"
echo "   REDIS_PASSWORD=${REDIS_PASSWORD}"
echo ""

ENDSSH

log_success "Environment file created"

# Start Docker containers
print_header "Step 6: Starting Docker Containers"

ssh ${STAGING_USER}@${STAGING_HOST} << 'ENDSSH'
#!/bin/bash
set -e

cd /var/www/registro

echo "🐳 Pulling and starting Docker containers..."
docker compose -f docker-compose.staging.yml pull
docker compose -f docker-compose.staging.yml up -d

echo "⏳ Waiting for services to be ready (30 seconds)..."
sleep 30

echo "✅ Containers started:"
docker compose -f docker-compose.staging.yml ps

ENDSSH

log_success "Docker containers started"

# Run initial migrations and seeds
print_header "Step 7: Database Setup"

ssh ${STAGING_USER}@${STAGING_HOST} << 'ENDSSH'
#!/bin/bash
set -e

cd /var/www/registro

echo "🗄️  Running database migrations..."
docker compose -f docker-compose.staging.yml exec -T app php artisan migrate --force

# No db:seed here. DatabaseSeeder overwrites administrator accounts and settings,
# and this script is re-runnable -- see .claude/rules/deployment.md. Bootstrap
# seeding of an empty database is deploy-init.sh's job, once, with named classes.

echo "🔗 Creating storage symlink..."
docker compose -f docker-compose.staging.yml exec -T app php artisan storage:link

echo "✅ Database setup complete"

ENDSSH

log_success "Database configured"

# Final verification
print_header "Step 8: Verification"

ssh ${STAGING_USER}@${STAGING_HOST} << 'ENDSSH'
#!/bin/bash

cd /var/www/registro

echo "📊 System Status:"
echo "=================="
docker compose -f docker-compose.staging.yml ps
echo ""
echo "🌐 URLs:"
echo "   Application: https://srv1203357.hstgr.cloud"
echo "   Mailpit UI: http://srv1203357.hstgr.cloud:8025"
echo ""
echo "📋 Next Steps:"
echo "   1. Test application access: https://srv1203357.hstgr.cloud"
echo "   2. Create admin user: docker compose -f docker-compose.staging.yml exec app php artisan make:filament-user"
echo "   3. Configure GitHub Actions secrets (see docs/deployment/STAGING-SETUP.md)"
echo ""

ENDSSH

# ======================
# Summary
# ======================

print_header "✅ STAGING SERVER SETUP COMPLETE"

log_success "Staging environment is ready!"
log ""
log "Server Details:"
log "  IP: ${STAGING_HOST}"
log "  Domain: https://${STAGING_DOMAIN}"
log "  Mailpit: http://${STAGING_DOMAIN}:8025"
log "  Project: ${PROJECT_DIR}"
log ""
log "GitHub Actions Secrets Needed:"
log "  STAGING_VPS_HOST=${STAGING_HOST}"
log "  STAGING_VPS_USER=deploy"
log "  STAGING_VPS_SSH_KEY=(copy from ~/.ssh/id_rsa or generate new key)"
log ""
log "Next Steps:"
log "  1. Configure GitHub Actions secrets in repository settings"
log "  2. Test auto-deployment: git push origin develop"
log "  3. Create admin user on staging server"
log "  4. Update GIT_WORKFLOW.md documentation"
log ""
log "Documentation:"
log "  Complete setup guide: docs/deployment/STAGING-SETUP.md"
log ""

exit 0

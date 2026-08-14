# Stage 1: Build frontend assets
FROM node:20-alpine AS frontend-builder

WORKDIR /app

# Copy package files
COPY package.json package-lock.json ./

# Install dependencies
RUN npm ci

# Copy source files needed for build
COPY resources ./resources
COPY vite.config.js ./
COPY design-system.json ./
COPY public ./public
COPY scripts ./scripts

# Build frontend assets
RUN npm run build

# Stage 2: PHP runtime
# Using Debian-based image for Playwright compatibility (Alpine's musl libc is incompatible)
FROM php:8.3-fpm

# Build argument to control OPcache configuration
# Default: dev (safe for local development with bind mounts)
# Production/staging CI must pass --build-arg OPCACHE_MODE=production
ARG OPCACHE_MODE=dev

# Build argument to enable browser testing support (Playwright + Chromium)
# Default: false (production) - keeps image small
# Set to true for local development with E2E tests
ARG BROWSER_TESTING=false

# Runtime and build dependencies (Debian)
RUN apt-get update && apt-get install -y --no-install-recommends \
    libpng-dev \
    libjpeg-dev \
    libzip-dev \
    libonig-dev \
    libicu-dev \
    libxml2-dev \
    libsqlite3-dev \
    unzip \
    git \
    curl \
    netcat-openbsd \
    && rm -rf /var/lib/apt/lists/*

# Install PHP extensions (v0.3.5 + composer.lock requirements + pdo_sqlite for tests + sockets for Pest browser)
RUN docker-php-ext-configure gd --with-jpeg && \
    docker-php-ext-install -j$(nproc) \
    pdo_mysql \
    pdo_sqlite \
    mbstring \
    intl \
    pcntl \
    posix \
    gd \
    zip \
    bcmath \
    fileinfo \
    dom \
    exif \
    opcache \
    sockets

# Install Redis extension via PECL (not a core extension)
RUN pecl install redis && \
    docker-php-ext-enable redis

# Copy PHP configuration files
# - OPcache: Mode-dependent (dev vs production)
# - Upload limits: Always applied (10MB for Filament file uploads)
COPY docker/php/ /tmp/php-config/

# Apply upload limits configuration (ALWAYS)
RUN if [ -f "/tmp/php-config/uploads.ini" ]; then \
        cp /tmp/php-config/uploads.ini /usr/local/etc/php/conf.d/uploads.ini; \
        chmod 644 /usr/local/etc/php/conf.d/uploads.ini; \
        echo "✓ Upload limits configured (10MB)"; \
    else \
        echo "⚠ uploads.ini not found, using PHP defaults"; \
    fi

# Apply OPcache configuration (MODE-DEPENDENT)
RUN if [ "$OPCACHE_MODE" = "dev" ]; then \
        if [ -f "/tmp/php-config/opcache-dev.ini" ]; then \
            cp /tmp/php-config/opcache-dev.ini /usr/local/etc/php/conf.d/opcache.ini; \
            echo "✓ OPcache dev config installed (validate_timestamps=On)"; \
        else \
            echo "⚠ opcache-dev.ini not found, using defaults"; \
        fi; \
    else \
        if [ -f "/tmp/php-config/opcache-prod.ini" ]; then \
            cp /tmp/php-config/opcache-prod.ini /usr/local/etc/php/conf.d/opcache.ini; \
            echo "✓ OPcache production config installed (optimized for max performance)"; \
        else \
            echo "⚠ opcache-prod.ini not found, using PHP defaults"; \
        fi; \
    fi

# Cleanup temporary files
RUN rm -rf /tmp/php-config

# Browser Testing Support (Playwright + Node.js)
# Only installed when BROWSER_TESTING=true (local development)
# System Chromium deps only here - the browser binaries themselves are
# installed further down, once package.json is available (see below),
# so their version can be pinned to the exact npm "playwright" version.
RUN if [ "$BROWSER_TESTING" = "true" ]; then \
        echo "✓ Installing browser testing dependencies..."; \
        curl -fsSL https://deb.nodesource.com/setup_20.x | bash - && \
        apt-get update && apt-get install -y --no-install-recommends \
            nodejs \
            libnss3 \
            libatk1.0-0 \
            libatk-bridge2.0-0 \
            libcups2 \
            libdrm2 \
            libxkbcommon0 \
            libxcomposite1 \
            libxdamage1 \
            libxfixes3 \
            libxrandr2 \
            libgbm1 \
            libasound2 \
            libpango-1.0-0 \
            libcairo2 \
            fonts-liberation \
            fonts-noto-color-emoji && \
        rm -rf /var/lib/apt/lists/*; \
        echo "✓ Node.js $(node --version) installed"; \
    else \
        echo "⊘ Browser testing disabled (BROWSER_TESTING=false)"; \
    fi

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www

# Copy composer files
COPY composer.json composer.lock ./

# Install dependencies
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist

# Copy ALL code (bez --link!)
COPY . .

# Copy built frontend assets from frontend-builder stage
COPY --from=frontend-builder /app/public/build ./public/build

# Autoload
RUN composer dump-autoload --optimize --no-dev

# Install Playwright browser binaries (only when enabled)
#
# WHY NOT node_modules: docker-compose.yml bind-mounts the whole project
# (".:/var/www"), so anything this layer wrote under /var/www/node_modules
# would be shadowed by the host's node_modules at runtime anyway. The npm
# "playwright" package itself already lives on the host (devDependency,
# vendored via bind mount) and pest-plugin-browser always shells out to the
# host's "./node_modules/.bin/playwright" — never anything from the image.
# What the image DOES need to provide, and what a rebuild was silently
# losing, is the actual browser executables (Chromium binaries), which are
# NOT part of node_modules and are never installed by "npm install".
#
# WHERE: $PLAYWRIGHT_BROWSERS_PATH is pinned to /opt/playwright-browsers,
# outside /var/www, so the bind mount can never shadow it.
#
# VERSION: the browser build downloaded by "playwright install" is tied to
# the exact version of the playwright CLI that requests it. If that version
# drifts from the host's node_modules/playwright (package.json), the host
# CLI refuses to launch the image's browser ("Executable doesn't exist" /
# "Playwright was just installed or updated"). To guarantee they match, the
# version is read from package.json at build time (COPY . . above already
# brought it in) rather than hardcoded — bumping the npm package forces a
# rebuild to pick up the new pin instead of silently drifting.
RUN if [ "$BROWSER_TESTING" = "true" ]; then \
        set -e; \
        PLAYWRIGHT_VERSION=$(node -e "process.stdout.write(require('/var/www/package.json').devDependencies.playwright.replace(/^[^0-9]*/, ''))"); \
        echo "✓ Installing Playwright CLI + Chromium pinned to package.json version: $PLAYWRIGHT_VERSION"; \
        npm install -g "playwright@$PLAYWRIGHT_VERSION"; \
        mkdir -p /opt/playwright-browsers; \
        PLAYWRIGHT_BROWSERS_PATH=/opt/playwright-browsers playwright install chromium; \
        echo "✓ Playwright $PLAYWRIGHT_VERSION Chromium binaries installed to /opt/playwright-browsers"; \
    fi

# Browser binaries live outside /var/www (see rationale above) so the bind
# mount in docker-compose.yml never shadows them. Harmless no-op when
# BROWSER_TESTING=false — nothing reads this var if node/playwright aren't
# installed, and it adds no size to the production image.
ENV PLAYWRIGHT_BROWSERS_PATH=/opt/playwright-browsers

# Copy public directory to /tmp for entrypoint script
RUN cp -r /var/www/public /tmp/public

# Copy entrypoint script
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# CRITICAL DOCKER USER MODEL DECISION (ADR-013)
#
# Container runs as 'laravel:laravel' (UID 1000, GID 1000), NOT www-data!
#
# Rationale:
# - UID 1000 matches typical developer's primary user (dev/prod parity)
# - Consistent ownership in dev, staging, and production environments
# - Non-root for security (reduces attack surface, best practice)
# - Simplifies permission management (no chown needed in entrypoint)
#
# IMPORTANT: Do NOT try to chown files to www-data in entrypoint.sh!
# Attempting to chown to non-existent user causes restart loops (v0.6.1 incident).
#
# See: app/docs/decisions/ADR-013-docker-user-model.md
#
# /opt/playwright-browsers only exists when BROWSER_TESTING=true; chown is
# skipped otherwise so this step doesn't fail on the production image.
RUN groupadd -g 1000 laravel && \
    useradd -u 1000 -g laravel -m laravel && \
    chown -R laravel:laravel /var/www && \
    chown -R laravel:laravel /tmp/public && \
    if [ -d /opt/playwright-browsers ]; then chown -R laravel:laravel /opt/playwright-browsers; fi

USER laravel

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["php-fpm"]

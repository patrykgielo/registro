# Project Dependencies

**Last Updated:** 2025-11-30
**Version:** v0.2.11
**Audience:** DevOps, Backend Developers, Frontend Developers

---

## Overview

Complete inventory of ALL dependencies used in the Registro Laravel application, including:
- Core technology stack versions
- Composer packages (PHP)
- NPM packages (JavaScript)
- PHP extensions
- System packages (Alpine Linux)
- Docker base images

---

## Core Technology Stack

### Backend

| Technology | Version | Purpose |
|------------|---------|---------|
| **Laravel** | 12.32.5 | PHP application framework |
| **PHP** | 8.2-fpm-alpine | Server-side language |
| **MySQL** | 8.0 | Relational database |
| **Redis** | 7.2-alpine | Cache, sessions, queues |

### Frontend

| Technology | Version | Purpose |
|------------|---------|---------|
| **Node.js** | 20-alpine | JavaScript runtime (build only) |
| **Vite** | 7.0.7 | Frontend build tool |
| **Tailwind CSS** | 4.0.0 | Utility-first CSS framework |
| **Alpine.js** | 3.14.1 | Lightweight JavaScript framework |

### Infrastructure

| Technology | Version | Purpose |
|------------|---------|---------|
| **Docker** | 20+ | Containerization |
| **Docker Compose** | 2.0+ | Multi-container orchestration |
| **Nginx** | 1.25-alpine | Web server / reverse proxy |
| **Alpine Linux** | Latest | Base OS for containers |

---

## Composer Packages (PHP)

### Production Dependencies

| Package | Version | Purpose |
|---------|---------|---------|
| **php** | ^8.2 | Core PHP version requirement |
| **laravel/framework** | ^12.0 | Laravel core framework |
| **laravel/horizon** | ^5.39 | Queue monitoring dashboard |
| **laravel/tinker** | ^2.10.1 | Interactive REPL |
| **laravel/ui** | ^4.6 | Authentication UI scaffolding |
| **filament/filament** | ^4.0 | Admin panel framework |
| **guava/calendar** | ^2.0 | Calendar/scheduling functionality |
| **predis/predis** | ^3.2 | PHP Redis client (fallback for phpredis) |
| **smsapi/php-client** | ^3.0 | SMS gateway integration |
| **spatie/laravel-permission** | ^6.21 | Role & permission management |

### Development Dependencies

| Package | Version | Purpose |
|---------|---------|---------|
| **fakerphp/faker** | ^1.23 | Fake data generation for testing/seeding |
| **filament/upgrade** | ^4.0 | Filament upgrade helper |
| **laravel/pail** | ^1.2.2 | Real-time log tailing |
| **laravel/pint** | ^1.24 | PHP code style fixer |
| **laravel/sail** | ^1.41 | Docker development environment |
| **mockery/mockery** | ^1.6 | Mocking framework for testing |
| **nunomaduro/collision** | ^8.6 | Beautiful error handling for CLI |
| **phpunit/phpunit** | ^11.5.3 | PHP testing framework |

### Recommended Cleanup

**Packages to remove (not used in production):**
```bash
composer remove laravel/sail  # Only for local development
composer remove laravel/ui    # Bootstrap scaffolding (using Filament instead)
```

---

## NPM Packages (JavaScript)

### Production Dependencies

| Package | Version | Purpose |
|---------|---------|---------|
| **alpinejs** | ^3.14.1 | Lightweight reactive framework |

### Development Dependencies

| Package | Version | Purpose |
|---------|---------|---------|
| **@tailwindcss/vite** | ^4.0.0 | Tailwind CSS v4 Vite plugin |
| **tailwindcss** | ^4.0.0 | Utility-first CSS framework |
| **vite** | ^7.0.7 | Next generation frontend tooling |
| **laravel-vite-plugin** | ^2.0.0 | Laravel integration for Vite |
| **axios** | ^1.11.0 | HTTP client |
| **concurrently** | ^9.0.1 | Run multiple commands concurrently |
| **@popperjs/core** | ^2.11.6 | Positioning engine (for tooltips/dropdowns) |
| **bootstrap** | ^5.2.3 | CSS framework (legacy, not used) |
| **sass** | ^1.56.1 | CSS preprocessor (legacy, not used) |

### Recommended Cleanup

**Packages to remove (not used):**
```bash
npm uninstall bootstrap @popperjs/core sass
# Project uses Tailwind CSS exclusively, not Bootstrap
```

---

## PHP Extensions

### Core Extensions (php:8.2-fpm-alpine)

Included by default in base image:
- `pdo` - Database abstraction layer
- `json` - JSON handling
- `ctype` - Character type checking
- `fileinfo` - File information
- `tokenizer` - PHP tokenizer

### Manually Installed Extensions

| Extension | Installation Method | Purpose |
|-----------|-------------------|---------|
| **pdo_mysql** | docker-php-ext-install | MySQL database driver |
| **mbstring** | docker-php-ext-install | Multibyte string handling |
| **exif** | docker-php-ext-install | Image metadata reading |
| **pcntl** | docker-php-ext-install | Process control (queue workers) |
| **bcmath** | docker-php-ext-install | Arbitrary precision math |
| **gd** | docker-php-ext-install | Image manipulation |
| **zip** | docker-php-ext-install | ZIP archive handling |
| **intl** | docker-php-ext-install | Internationalization |
| **opcache** | docker-php-ext-install | Bytecode caching |
| **redis** | pecl install | Redis client (C extension) |

### Extension Details

**redis Extension (Critical):**
```dockerfile
RUN pecl install redis && \
    docker-php-ext-enable redis
```

**Why phpredis (C extension) not predis (PHP package):**
- 5x faster performance
- Required by Laravel Horizon
- Lower memory usage
- Native support for advanced Redis features

**gd Extension Configuration:**
```dockerfile
RUN docker-php-ext-configure gd --with-jpeg && \
    docker-php-ext-install -j$(nproc) gd
```

Enables JPEG support for image processing.

---

## System Packages (Alpine Linux)

### Runtime Libraries

Installed in final image (NOT removed after build):

| Package | Size | Purpose |
|---------|------|---------|
| `libpng` | ~200KB | PNG image support for GD |
| `libjpeg-turbo` | ~400KB | JPEG image support for GD |
| `libzip` | ~100KB | ZIP archive support |
| `icu-libs` | ~11MB | Internationalization libraries |
| `libxml2` | ~1.2MB | XML parsing |
| `oniguruma` | ~200KB | Regex library for mbstring |

**Total runtime size:** ~22MB

### Build Dependencies (Removed After Build)

Installed temporarily for compiling PHP extensions, then removed:

| Package | Size | Purpose |
|---------|------|---------|
| `libpng-dev` | ~1MB | PNG headers for GD compilation |
| `libjpeg-turbo-dev` | ~600KB | JPEG headers for GD compilation |
| `libzip-dev` | ~400KB | ZIP headers for zip extension |
| `icu-dev` | ~42MB | ICU headers for intl extension |
| `libxml2-dev` | ~8MB | XML headers for XML extensions |
| `oniguruma-dev` | ~600KB | Regex headers for mbstring |
| `$PHPIZE_DEPS` | ~100MB | Base build tools (gcc, make, autoconf) |

**Total build deps removed:** ~154MB

**Space saving strategy:**
```dockerfile
# Install build deps
RUN apk add --no-cache --virtual .build-deps \
    libpng-dev libjpeg-turbo-dev ...

# Compile extensions
RUN docker-php-ext-install ...

# Remove build deps (saves ~150MB)
RUN apk del .build-deps
```

---

## Docker Base Images

### Multi-Stage Build Layers

| Stage | Base Image | Purpose | Size |
|-------|------------|---------|------|
| **php-base** | php:8.2-fpm-alpine | PHP runtime with extensions | ~120MB |
| **composer-deps** | php-base | Install Composer dependencies | +~80MB (vendor/) |
| **frontend-build** | node:20-alpine | Build frontend assets | ~180MB (build only) |
| **runtime** | php-base | Final production image | ~220MB total |

**Final image size:** ~220MB (compressed: ~80MB)

### Version Pinning Strategy

**Current:**
```dockerfile
FROM php:8.2-fpm-alpine
FROM node:20-alpine
```

**Recommended for production:**
```dockerfile
FROM php:8.2.25-fpm-alpine3.20  # Pin exact versions
FROM node:20.11.0-alpine3.20
```

Ensures reproducible builds.

---

## Dependency Update Policy

### Security Updates

**Frequency:** Immediate (within 24 hours of CVE disclosure)

**Process:**
1. Check for security advisories: `composer audit`, `npm audit`
2. Update affected packages: `composer update package/name`
3. Run full test suite
4. Deploy to staging
5. Monitor for 24 hours
6. Deploy to production

### Major Version Updates

**Frequency:** Quarterly (every 3 months)

**Process:**
1. Review changelogs and breaking changes
2. Update in development environment
3. Update tests for breaking changes
4. Run full test suite + manual QA
5. Deploy to staging for 1 week
6. Deploy to production with rollback plan

**Major version calendar:**
- Laravel: April, October (LTS releases)
- PHP: November (annual release)
- Tailwind: As needed (stable v4 recently released)

### Minor Version Updates

**Frequency:** Monthly

**Process:**
1. Run `composer outdated` and `npm outdated`
2. Update non-major versions
3. Run automated tests
4. Deploy to staging
5. Deploy to production

**Monthly schedule:**
- Week 1: Update composer dependencies
- Week 2: Update npm dependencies
- Week 3: Testing and QA
- Week 4: Production deployment

### Patch Updates

**Frequency:** Weekly (automated via Dependabot)

**Process:**
- Automated PRs from GitHub Dependabot
- CI/CD runs tests automatically
- Merge if tests pass
- Auto-deploy to staging
- Manual deploy to production after 48h

---

## Dependency Audit Commands

### PHP Dependencies

```bash
# Check for security vulnerabilities
composer audit

# Show outdated packages
composer outdated

# Update all packages (within version constraints)
composer update

# Update specific package
composer update laravel/framework

# Install exact versions from composer.lock
composer install
```

### JavaScript Dependencies

```bash
# Check for security vulnerabilities
npm audit

# Fix vulnerabilities automatically
npm audit fix

# Show outdated packages
npm outdated

# Update all packages (within version constraints)
npm update

# Update to latest (ignoring semver)
npm install package@latest
```

### PHP Extensions

```bash
# List installed extensions
docker compose exec app php -m

# Check specific extension
docker compose exec app php -m | grep redis

# Show extension version
docker compose exec app php -r "echo phpversion('redis');"
```

---

## Monitoring & Alerts

### Dependency Monitoring Tools

**GitHub Dependabot:**
- Enabled for composer.json and package.json
- Creates PRs for security updates
- Runs CI/CD tests automatically

**Composer Audit:** runs as a step in CI since 2026-08-16 (`test` job of `test.yml` and
`deploy-production.yml`), not on a weekly schedule — both workflows are `workflow_dispatch`-only,
so this runs whenever someone dispatches one of them, and is report-only (does not fail the job).
See `.claude/rules/ci-cd-troubleshooting.md`, 2026-08-16 entry, for measured counts and how to
make it blocking.
```bash
# What CI runs (reads composer.lock directly, independent of --no-dev)
composer audit --locked --format=json
```

**NPM Audit (Weekly):**
```bash
# Run in CI/CD weekly
npm audit --json > npm-audit.json
```

### Version Tracking

**composer.lock:**
- Locked exact versions for reproducible builds
- Committed to git
- Updated with `composer update`

**package-lock.json:**
- Locked exact versions for reproducible builds
- Committed to git
- Updated with `npm update`

**Never delete lock files!** They ensure all environments use identical versions.

---

## Production vs Development Dependencies

### Production Only

**Composer:**
```bash
composer install --no-dev --optimize-autoloader
```

Excludes:
- phpunit/phpunit
- mockery/mockery
- laravel/pint
- laravel/sail
- fakerphp/faker

**NPM:**
```bash
npm ci --production
```

Excludes all devDependencies (vite, tailwindcss, etc. - only needed for build).

### Development Only

**Composer:**
```bash
composer install  # Includes dev dependencies
```

**NPM:**
```bash
npm ci  # Includes all dependencies
```

---

## Known Dependency Issues

### 1. predis vs phpredis

**Issue:** Project has both predis package AND phpredis extension.

**Current:**
```json
"require": {
    "predis/predis": "^3.2"
}
```

```dockerfile
RUN pecl install redis
```

**Recommendation:**
Remove predis package, use phpredis exclusively:

```bash
composer remove predis/predis
```

Update .env:
```bash
REDIS_CLIENT=phpredis  # Not 'predis'
```

**Impact:** Removes unused package, avoids confusion.

---

### 2. Bootstrap + Sass (Not Used)

**Issue:** Bootstrap and Sass installed but project uses Tailwind CSS exclusively.

**Current:**
```json
"devDependencies": {
    "bootstrap": "^5.2.3",
    "@popperjs/core": "^2.11.6",
    "sass": "^1.56.1"
}
```

**Recommendation:**
```bash
npm uninstall bootstrap @popperjs/core sass
```

**Impact:** Reduces node_modules size by ~15MB.

---

### 3. Laravel Sail (Development Only)

**Issue:** Sail included in composer.json but not used (using custom Docker setup).

**Current:**
```json
"require-dev": {
    "laravel/sail": "^1.41"
}
```

**Recommendation:**
```bash
composer remove laravel/sail
```

**Impact:** Production builds don't include Sail (already using --no-dev), but cleanup reduces development complexity.

---

## Dockerfile Dependency Strategy

### Layer Caching Optimization

Dependencies installed in optimal order for Docker layer caching:

```dockerfile
# 1. System packages (rarely change)
RUN apk add --no-cache libpng libjpeg-turbo ...

# 2. PHP extensions (rarely change)
RUN docker-php-ext-install pdo_mysql mbstring ...

# 3. Composer dependencies (change more often)
COPY composer.json composer.lock ./
RUN composer install --no-dev

# 4. NPM dependencies (change more often)
COPY package.json package-lock.json ./
RUN npm ci

# 5. Application code (changes most often)
COPY . .
```

**Result:**
- Dependency layers cached
- Only application code layer rebuilds on code changes
- Build time: 15-30 seconds (from cache) vs 5-7 minutes (full build)

---

## References

- [Deployment History](app/docs/deployment/deployment-history.md) - Dependency issues during deployments
- [Docker Infrastructure](app/docs/deployment/docker-infrastructure.md) - Multi-stage build details
- [Laravel 12 Documentation](https://laravel.com/docs/12.x)
- [Filament v4 Documentation](https://filamentphp.com/docs/4.x)
- [Tailwind CSS v4 Documentation](https://tailwindcss.com/docs)

---

**Document Version:** 1.0
**Last Updated:** 2025-11-30
**Maintained By:** Development Team

**Update Schedule:**
- Update after composer/npm package changes
- Monthly dependency audit review
- Quarterly major version assessment

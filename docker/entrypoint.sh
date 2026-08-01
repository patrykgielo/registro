#!/bin/sh
set -e

echo "🚀 Starting Laravel entrypoint..."

# SELF-VALIDATION
echo "🔍 Validating container configuration..."
EXPECTED_USER="laravel"
CURRENT_USER=$(whoami)
if [ "$CURRENT_USER" != "$EXPECTED_USER" ]; then
    echo "❌ CRITICAL: Running as '$CURRENT_USER' but expected '$EXPECTED_USER'"
    exit 1
fi
echo "✅ Container user: $CURRENT_USER"

# Clear OPcache file cache to prevent stale bytecode
if [ -d /tmp/opcache ]; then
    rm -rf /tmp/opcache/*
    echo "✅ OPcache file cache cleared"
fi

# Wait for database with timeout
# Use DB_HOST from environment (defaults to 'mysql' - Docker service name)
DB_HOST="${DB_HOST:-mysql}"
DB_PORT="${DB_PORT:-3306}"
MAX_WAIT=60
WAIT_COUNT=0
echo "🔌 Connecting to database: $DB_HOST:$DB_PORT"
while ! nc -z "$DB_HOST" "$DB_PORT"; do
    if [ $WAIT_COUNT -ge $MAX_WAIT ]; then
        echo "❌ Database timeout after ${MAX_WAIT}s (host: $DB_HOST:$DB_PORT)"
        exit 1
    fi
    echo "⏳ Waiting for database... ($WAIT_COUNT/$MAX_WAIT)"
    sleep 2
    WAIT_COUNT=$((WAIT_COUNT + 2))
done
echo "✅ Database ready! ($DB_HOST:$DB_PORT)"

# Create storage directories if they don't exist
mkdir -p /var/www/storage/app/public/services/images
mkdir -p /var/www/storage/app/public/services/featured
mkdir -p /var/www/storage/app/public/services/galleries
mkdir -p /var/www/storage/app/public/portfolio
mkdir -p /var/www/storage/app/public/avatars
mkdir -p /var/www/storage/framework/{cache,sessions,views}
mkdir -p /var/www/storage/logs

# Production vs Development mode
if [ "$APP_ENV" = "production" ]; then
    echo "ℹ️  Production mode: Files owned by $(id -un):$(id -gn)"
    # No chown needed - files already have correct ownership (laravel:laravel)
    # Docker volumes created with correct UID/GID (1000:1000)
else
    echo "🔓 Development mode: Files owned by host user"
fi

# Create storage symlink if it doesn't exist
if [ ! -L /var/www/public/storage ]; then
    echo "🔗 Creating storage symlink..."
    php artisan storage:link
else
    echo "✅ Storage symlink already exists"
fi

# Copy fresh build assets from image to public volume (if newer)
if [ -d /tmp/public/build ]; then
    if [ ! -d /var/www/public/build ] || [ /tmp/public/build -nt /var/www/public/build ]; then
        echo "📦 Updating frontend assets from image..."
        cp -r /tmp/public/build /var/www/public/
        echo "✅ Frontend assets updated"
    else
        echo "✅ Frontend assets already up to date"
    fi
else
    echo "⚠️  No build assets found in image"
fi

# Production optimizations
#
# Migrations are deliberately NOT run here. This entrypoint is shared by three
# containers -- app, horizon and scheduler -- which start concurrently from the
# same image, so `migrate --force` here meant three migrators racing each other
# against one database on every deploy and on every reboot. It also ran before
# any maintenance mode was engaged, and swallowed its own failure ("container
# will start anyway"), leaving a container serving traffic against a
# half-migrated schema with nothing failing loudly.
#
# Migrations now have exactly one owner: /opt/registro/deploy.sh (first install:
# scripts/deploy-init.sh), which wraps them in `artisan down` and aborts the
# deploy if they fail. A reboot must never migrate.
if [ "$APP_ENV" = "production" ]; then
    echo "🧹 Optimizing application..."
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
fi

echo "✅ Application ready!"

# Start PHP-FPM
exec "$@"

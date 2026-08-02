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

# ---------------------------------------------------------------------------
# Sync public/ from the image into the shared volume
#
# /var/www/public is a NAMED VOLUME (app_public), shared read-only with nginx so
# it can serve static files. Docker seeds a named volume from the image ONLY
# when the volume is empty, so from the second deploy onward the volume keeps
# the FIRST image's public/ forever -- index.php, .htaccess, and above all
# build/ with its content-hashed filenames and manifest.json.
#
# Vite emits new hashes every release. A frozen volume therefore means the app
# renders a manifest naming files the volume does not contain: every asset 404s
# and the site is unstyled, while the deploy reports success. Verified by
# reproduction, not inference.
#
# The previous guard here -- `[ /tmp/public/build -nt /var/www/public/build ]` --
# never fired even once. The volume is seeded from the same image, so both
# directories start with identical mtimes, and `cp -r` would stamp the
# destination with the copy time anyway. It printed "Frontend assets already up
# to date" on the very first run and every run after it.
#
# Only the `app` service mounts this volume read-write (nginx has it :ro, and
# horizon/scheduler do not mount it at all), so there is exactly one writer and
# no need to coordinate.
# ---------------------------------------------------------------------------
if [ -d /tmp/public ]; then
    echo "📦 Syncing public/ from image..."

    # build/ is swapped whole rather than merged. Merging would leave every past
    # release's hashed assets in the volume forever, and -- worse -- `cp` gives
    # no ordering guarantee, so a merge can land the new manifest.json before the
    # files it names.
    #
    # Staging into build.new and renaming means nginx, which may be serving
    # throughout and re-stats per request (no open_file_cache is configured),
    # never reads a half-copied tree. Note the honest limit: two renames are
    # needed, because rename(2) cannot replace a non-empty directory, so there is
    # a microsecond between them where build/ does not exist. A request landing
    # exactly there 404s. Deploys run inside maintenance mode, so this is not
    # worth a symlink indirection to close.
    rm -rf /var/www/public/build.new /var/www/public/build.old
    if [ -d /tmp/public/build ]; then
        cp -a /tmp/public/build /var/www/public/build.new
    else
        echo "⚠️  No build assets in image -- publishing an empty build/"
        mkdir -p /var/www/public/build.new
    fi

    # Everything else (index.php, .htaccess, robots.txt, css, js, fonts, images,
    # vendor) overwrites in place; these are small and not content-hashed.
    # `storage` is deliberately absent from the image (.dockerignore excludes it)
    # and is created below, so this loop cannot clobber the symlink.
    for entry in /tmp/public/* /tmp/public/.[!.]*; do
        [ -e "$entry" ] || continue
        case "$(basename "$entry")" in
            build) continue ;;
        esac
        cp -a "$entry" /var/www/public/
    done

    if [ -d /var/www/public/build ]; then
        mv /var/www/public/build /var/www/public/build.old
    fi
    mv /var/www/public/build.new /var/www/public/build
    rm -rf /var/www/public/build.old
    echo "✅ public/ synced from image"
else
    echo "⚠️  /tmp/public missing from image -- public/ left as-is"
fi

# Storage symlink AFTER the sync: the sync writes into the same directory, and
# creating the link first only to copy over it would be pointless work.
if [ ! -L /var/www/public/storage ]; then
    echo "🔗 Creating storage symlink..."
    php artisan storage:link
else
    echo "✅ Storage symlink already exists"
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

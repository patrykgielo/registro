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
# OPT-IN ONLY, via SYNC_PUBLIC_FROM_IMAGE. This must never run where
# /var/www is a BIND MOUNT of the repository -- docker-compose.yml and
# docker-compose.dev.yml mount `.:/var/www` on app, horizon AND scheduler, and
# the container runs as uid 1000, the same as the host developer. An
# unconditional sync there deletes the developer's `npm run build` output
# (public/build is gitignored, so it is unrecoverable) and reverts tracked files
# under public/css/filament, public/js/filament and public/vendor/livewire to
# whatever was baked into the image. Opt-in rather than an APP_ENV check,
# because the failure mode of forgetting the flag is "keeps current behaviour"
# rather than "eats the working tree".
#
# Why it is needed in production: /var/www/public is a NAMED VOLUME (app_public),
# shared read-only with nginx. Docker seeds a named volume from the image ONLY
# when the volume is empty, so from the second deploy onward the volume kept the
# FIRST image's public/ forever.
#
# What that actually breaks -- stated carefully, because the obvious guess is
# wrong: the frozen build/ keeps manifest.json AND its hashed assets together,
# so it stays internally consistent and nothing 404s. The site just serves the
# OLD frontend indefinitely; a UI fix deploys, reports success and never
# appears. The sharp edge is a newly added Vite entry point, which is missing
# from the stale manifest and makes Laravel raise "Unable to locate file in Vite
# manifest" -- a 500 on that page. The same freeze also pinned the git-tracked,
# NON-hashed public/css/filament/**, public/js/filament/** and
# public/vendor/livewire/** , so a Filament upgrade would have shipped new PHP
# against first-release JS.
#
# The previous guard here -- `[ /tmp/public/build -nt /var/www/public/build ]` --
# never fired even once: the volume is seeded from the same image, so both
# directories start with identical mtimes, and `cp -r` would restamp the
# destination anyway. Verified by reproduction.
# ---------------------------------------------------------------------------
if [ "${SYNC_PUBLIC_FROM_IMAGE:-}" = "true" ] && [ -d /tmp/public ]; then
    echo "📦 Syncing public/ from image..."

    # Deliberately non-fatal despite `set -e`. A failed copy used to abort the
    # entrypoint before `exec "$@"`, so the container never started php-fpm and
    # crashlooped under `restart: unless-stopped` -- strictly worse than the
    # stale frontend this replaces. deploy.sh gates the result explicitly, so a
    # bad sync fails the DEPLOY loudly instead of killing the container.
    sync_public() {
        set +e
        rc=0

        # build/ is swapped whole rather than merged. Merging would leave every
        # past release's hashed assets in the volume forever and, worse, `cp`
        # gives no ordering guarantee, so it can land the new manifest.json
        # before the files it names.
        #
        # Honest limit: replacing a directory needs two renames, because
        # rename(2) cannot replace a non-empty directory, so build/ is absent for
        # a microsecond between them. Deploys run inside maintenance mode.
        rm -rf /var/www/public/build.new /var/www/public/build.old
        if [ -d /tmp/public/build ]; then
            cp -a /tmp/public/build /var/www/public/build.new || rc=1
        else
            echo "⚠️  No build assets in image -- publishing an empty build/"
            mkdir -p /var/www/public/build.new || rc=1
        fi

        # Everything else, one entry at a time, each staged and renamed.
        #
        # These paths are NOT content-hashed (public/css/filament/**,
        # public/vendor/livewire/livewire.js ...) and nginx serves them directly
        # with `expires 1y; Cache-Control: immutable`. Copying in place is
        # truncate-then-write, so a request landing mid-copy would receive a
        # TRUNCATED file with HTTP 200 and have it cached for a year on a URL
        # that never changes. Maintenance mode does not help: it gates PHP, not
        # nginx's static serving. Rename within the volume is atomic.
        #
        # `-f` so a destination left non-writable by a previous release does not
        # fail the copy. `build` is skipped: it is handled above. `storage` is
        # absent from the image (.dockerignore) so the runtime symlink created
        # below is never at risk.
        for entry in /tmp/public/* /tmp/public/.[!.]*; do
            [ -e "$entry" ] || continue
            name="${entry##*/}"
            [ "$name" = "build" ] && continue

            rm -rf "/var/www/public/.sync.$name"
            if cp -af "$entry" "/var/www/public/.sync.$name"; then
                rm -rf "/var/www/public/$name"
                mv "/var/www/public/.sync.$name" "/var/www/public/$name" || rc=1
            else
                rm -rf "/var/www/public/.sync.$name"
                echo "⚠️  Could not stage $name"
                rc=1
            fi
        done

        if [ -d /var/www/public/build ]; then
            mv /var/www/public/build /var/www/public/build.old || rc=1
        fi
        mv /var/www/public/build.new /var/www/public/build || rc=1
        rm -rf /var/www/public/build.old

        set -e
        return $rc
    }

    if sync_public; then
        echo "✅ public/ synced from image"
    else
        # Not fatal here on purpose -- see above. deploy.sh verifies the result.
        echo "⚠️  public/ sync reported errors -- deploy.sh will verify and fail if stale"
    fi
elif [ -d /tmp/public ]; then
    echo "ℹ️  SYNC_PUBLIC_FROM_IMAGE not set -- leaving public/ untouched (dev bind mount?)"
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

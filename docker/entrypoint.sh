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
# Written out rather than `framework/{cache,sessions,views}`: this script runs
# under #!/bin/sh (dash in the image), which has no brace expansion, so that form
# silently created one directory literally named "{cache,sessions,views}" and
# none of the three real ones.
mkdir -p /var/www/storage/framework/cache
mkdir -p /var/www/storage/framework/sessions
mkdir -p /var/www/storage/framework/views
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
# Replaces one entry of public/ with the image's copy, as atomically as the
# filesystem allows. Returns non-zero without having destroyed anything.
#
# Regular files: rename(2) replaces an existing file atomically, so the
# destination is NEVER unlinked first -- there is no window at all.
#
# Directories: rename(2) refuses to replace a non-empty directory, so the swap
# needs two renames and the destination is briefly absent. That is still far
# better than the alternatives: `rm -rf dest` first would leave the path missing
# for the whole duration of a recursive delete, and copying in place is
# truncate-then-write, which can hand nginx a TRUNCATED file with HTTP 200 on
# paths served with `expires 1y; Cache-Control: immutable` -- cached for a year
# on a URL that never changes. A transient 404 is not cached that way; a
# truncated 200 is. That asymmetry is the whole reason for staging.
replace_public_entry() {
    src="$1"
    name="$2"
    dest="/var/www/public/${name}"
    stage="/var/www/public/.sync.${name}"

    rm -rf "$stage"
    if ! cp -a "$src" "$stage"; then
        rm -rf "$stage"
        echo "⚠️  Could not stage ${name} -- leaving the existing copy in place"
        return 1
    fi

    if [ -d "$dest" ] && [ ! -L "$dest" ]; then
        rm -rf "${dest}.old"
        if ! mv "$dest" "${dest}.old"; then
            rm -rf "$stage"
            echo "⚠️  Could not move ${name} aside -- leaving the existing copy in place"
            return 1
        fi
        if ! mv "$stage" "$dest"; then
            # Put the original back rather than leaving the path missing.
            mv "${dest}.old" "$dest" 2>/dev/null || true
            rm -rf "$stage"
            echo "⚠️  Could not install ${name} -- restored the previous copy"
            return 1
        fi
        rm -rf "${dest}.old"
        return 0
    fi

    # File, symlink, or nothing there yet: a single atomic rename.
    if ! mv "$stage" "$dest"; then
        rm -rf "$stage"
        echo "⚠️  Could not install ${name} -- left the existing copy alone"
        return 1
    fi
    return 0
}

if [ "${SYNC_PUBLIC_FROM_IMAGE:-}" = "true" ] && [ -d /tmp/public ]; then
    echo "📦 Syncing public/ from image..."

    # Deliberately non-fatal. A failed copy used to abort the entrypoint before
    # `exec "$@"`, so the container never started php-fpm and crashlooped under
    # `restart: unless-stopped` -- strictly worse than the stale frontend this
    # replaces. Every failure path above leaves the previous copy intact, and
    # deploy.sh compares the whole tree afterwards and fails the DEPLOY.
    #
    # NOTE: this function must have exactly one exit path. `set +e` here and
    # `set -e` at the end are not a save/restore pair -- an early `return` would
    # leave errexit OFF for the remainder of the entrypoint, so config:cache and
    # friends would fail silently and php-fpm would start on broken caches. Do
    # not add a `return` above the restore.
    sync_public() {
        set +e
        rc=0

        # Recover from a container killed mid-swap BEFORE cleaning anything.
        #
        # `replace_public_entry` moves the live copy to <name>.old, installs the
        # new one, then deletes the backup. A container killed between the first
        # two steps leaves the ONLY copy at <name>.old. Deleting it here and then
        # failing to copy from the image would destroy the entry outright -- so
        # promote the backup first, and only remove it if the real entry is
        # already back in place.
        for orphan in /var/www/public/*.old; do
            [ -e "$orphan" ] || continue
            base="${orphan%.old}"
            if [ -e "$base" ]; then
                rm -rf "$orphan"
            else
                echo "♻️  Restoring ${base##*/} from an interrupted sync"
                mv "$orphan" "$base" || rc=1
            fi
        done

        # Staging leftovers are always safe to drop: they are incomplete copies
        # by definition. Not web-accessible either (nginx denies dotfiles), but
        # they would accumulate.
        rm -rf /var/www/public/.sync.* /var/www/public/build.new

        # build/ is swapped whole rather than merged: merging keeps every past
        # release's hashed assets forever and, worse, `cp` gives no ordering
        # guarantee, so it can land the new manifest.json before the files it
        # names.
        build_ok=0
        if [ -d /tmp/public/build ]; then
            cp -a /tmp/public/build /var/www/public/build.new && build_ok=1
        else
            echo "⚠️  No build assets in image -- publishing an empty build/"
            mkdir -p /var/www/public/build.new && build_ok=1
        fi

        if [ "$build_ok" -eq 1 ]; then
            # Only swap once the new copy is complete. Doing this unconditionally
            # would move the live build/ aside, fail to install the replacement,
            # and then delete the only surviving copy -- leaving the volume with
            # no assets at all.
            if [ -d /var/www/public/build ]; then
                mv /var/www/public/build /var/www/public/build.old || rc=1
            fi
            if mv /var/www/public/build.new /var/www/public/build; then
                rm -rf /var/www/public/build.old
            else
                mv /var/www/public/build.old /var/www/public/build 2>/dev/null || true
                echo "⚠️  Could not install build/ -- restored the previous copy"
                rc=1
            fi
        else
            rm -rf /var/www/public/build.new
            echo "⚠️  build/ copy failed -- keeping the existing build/"
            rc=1
        fi

        # Everything else. `build` is handled above; `storage` is absent from the
        # image (.dockerignore excludes it) so the runtime symlink created below
        # is never reachable from this loop.
        for entry in /tmp/public/* /tmp/public/.[!.]*; do
            [ -e "$entry" ] || continue
            name="${entry##*/}"
            [ "$name" = "build" ] && continue
            replace_public_entry "$entry" "$name" || rc=1
        done

        # Prune top-level entries the image no longer ships, so the volume is a
        # true mirror of it rather than an accumulation of every release ever
        # deployed. Two reasons this matters beyond tidiness:
        #
        #   - `public/hot` is the dangerous one. It is excluded by .dockerignore,
        #     so without pruning, if it ever reached the volume no deploy could
        #     remove it and Vite::asset() would resolve to a dev server for the
        #     whole application.
        #   - deploy.sh compares the two trees, and an un-prunable leftover would
        #     fail every subsequent deploy until someone cleaned the volume by
        #     hand.
        #
        # `storage` is exempt: it is a runtime symlink that deliberately does not
        # exist in the image.
        for dest in /var/www/public/* /var/www/public/.[!.]*; do
            [ -e "$dest" ] || [ -L "$dest" ] || continue
            dname="${dest##*/}"
            case "$dname" in
                # `storage` is the runtime symlink and never in the image.
                # `build` is installed above and is legitimately absent from
                # /tmp/public when the image ships no frontend build -- without
                # this exemption the prune would delete the empty build/ that was
                # just published, and still report success.
                # *.old / .sync.* are swap state, handled above; deleting a
                # crash orphan here would defeat the recovery.
                storage|build|*.old) continue ;;
            esac
            if [ ! -e "/tmp/public/${dname}" ]; then
                echo "🧹 Removing ${dname} -- no longer shipped in the image"
                rm -rf "$dest" || rc=1
            fi
        done

        set -e
        return $rc
    }

    if sync_public; then
        echo "✅ public/ synced from image"
    else
        echo "⚠️  public/ sync reported errors -- deploy.sh will verify and fail the deploy"
    fi
elif [ "${SYNC_PUBLIC_FROM_IMAGE:-}" = "true" ]; then
    echo "⚠️  SYNC_PUBLIC_FROM_IMAGE is set but /tmp/public is missing from the image"
else
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

#!/bin/sh
set -u

log() {
    printf '[%s] %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$1"
}

/workspace/docs-site/build.sh

log "watching /workspace/app/docs and /workspace/docs-site for changes (entr -r)"

# Exclude the generated site/ output dir and generated .svg files from the
# watch list — otherwise the build script writing into site/ (or writing
# .svg next to .d2 sources) would re-trigger entr and cause a rebuild loop.
while true; do
    find /workspace/app/docs /workspace/docs-site \
        -path /workspace/docs-site/site -prune -o \
        -name '*.svg' -prune -o \
        -type f -print 2>/dev/null \
        | entr -nr -d /workspace/docs-site/build.sh
    # entr -d exits when the watched *directory tree* structure changes
    # (files added/removed); loop re-collects the file list and restarts.
    sleep 1
done

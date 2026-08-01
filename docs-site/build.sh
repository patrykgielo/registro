#!/bin/sh
set -u

log() {
    printf '[%s] %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$1"
}

log "docs build starting"

# 1. Compile every .d2 diagram to an adjacent .svg. Skip/log per-file errors
#    instead of aborting the whole build on one bad diagram.
D2_ERRORS=0
D2_COUNT=0
for f in $(find /workspace/app/docs /workspace/docs-site -path /workspace/docs-site/site -prune -o -type f -name '*.d2' -print 2>/dev/null); do
    D2_COUNT=$((D2_COUNT + 1))
    out="${f%.d2}.svg"
    if d2 "$f" "$out" 2>/tmp/d2-error.log; then
        log "d2 compiled: $f -> $out"
    else
        D2_ERRORS=$((D2_ERRORS + 1))
        log "d2 ERROR compiling $f (see below), continuing"
        cat /tmp/d2-error.log
    fi
done
log "d2 compile pass done ($D2_COUNT file(s), $D2_ERRORS error(s))"

# 2. Build the MkDocs site.
if mkdocs build --site-dir /workspace/docs-site/site -f /workspace/docs-site/mkdocs.yml; then
    log "mkdocs build succeeded"
else
    log "mkdocs build FAILED"
    exit 1
fi

log "docs build complete"

#!/usr/bin/env bash
#
# Measure which deployment model an installation is actually running, and say
# loudly when it cannot be measured.
#
# WHY THIS EXISTS
# ---------------
# This codebase ships two deployment models that want OPPOSITE code wherever
# tenant identity is involved:
#
#   shared stack     (TENANT_SLUG blank) -- one app serves many tenants,
#                    resolved per-request from the Host header. Components
#                    MUST resolve the tenant.
#   dedicated stack  (TENANT_SLUG set)   -- one app IS one tenant. APP_URL,
#                    the database and the container already encode it.
#                    Resolving a tenant here is a BUG, not a safeguard.
#
# `config('app.tenant_slug')` is the single discriminator (ResolveTenant.php:36).
# `organizations.singleton` is its database-side counterpart, and
# `registro:tenant-provisioned --assert` cross-checks the two.
#
# The failure this guards against is not "not knowing the models". It is
# reasoning about a REMOTE installation's behaviour from THIS machine's .env --
# which produces a confident, wrong answer. So the remote half is never
# inferred: it is either a timestamped measurement or the word UNKNOWN.
#
# Usage:
#   scripts/architecture-facts.sh              # local, human readable
#   scripts/architecture-facts.sh --hook       # compact, for prompt injection
#   scripts/architecture-facts.sh --uat        # measure UAT over SSH, cache it
#   scripts/architecture-facts.sh --clear      # drop caches

set -euo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CACHE_DIR="${PROJECT_ROOT}/.claude/.architecture-facts"
LOCAL_CACHE="${CACHE_DIR}/local.txt"
UAT_CACHE="${CACHE_DIR}/uat.txt"

# Local measurement is cheap but not free (a docker exec). Cache it so the
# hook cannot turn every prompt into container traffic on the user's machine.
LOCAL_TTL_SECONDS=600

# A remote snapshot is a photograph, not a feed. After this it is presented as
# stale rather than as fact -- containers get recreated, .env files get edited.
UAT_MAX_AGE_SECONDS=$(( 7 * 24 * 3600 ))

UAT_HOST="${REGISTRO_UAT_HOST:-srv1342834.hstgr.cloud}"
# `deploy`, not `ubuntu`: verified by a real connection 2026-08-22, and matching
# setup-production-server.sh's own DEPLOY_USER. A stale note claiming `ubuntu`
# cost three rejected key offers against a hardened host before it was caught.
UAT_USER="${REGISTRO_UAT_USER:-deploy}"
UAT_APP_DIR="${REGISTRO_LEGACY_APP_DIR:-/var/www/registro}"

mkdir -p "$CACHE_DIR"

# --- helpers ---------------------------------------------------------------

file_age_seconds() {
    local f="$1"
    [ -f "$f" ] || { echo "999999999"; return; }
    local mtime now
    mtime="$(stat -c %Y "$f" 2>/dev/null || echo 0)"
    now="$(date +%s)"
    echo $(( now - mtime ))
}

human_age() {
    local s="$1"
    if   [ "$s" -lt 3600 ];  then echo "$(( s / 60 )) min temu"
    elif [ "$s" -lt 86400 ]; then echo "$(( s / 3600 )) godz. temu"
    else                          echo "$(( s / 86400 )) dni temu"
    fi
}

# Classify an installation from the two facts that decide it. Kept as one
# function so local and remote can never drift into different verdicts.
#   $1 = tenant_slug value (may be empty)
#   $2 = output of `registro:tenant-provisioned --assert`
#
# The markers below are load-bearing: tests/shell/cases/36_* extracts this
# body verbatim rather than copying it, so editing the verdicts here is what
# turns that case red. `${slug}` inside the body makes a bare `}` unusable as
# an extraction boundary.
classify() {
    # >>> classify-body-start
    local slug="$1" provisioned="$2"

    if [ -n "$slug" ]; then
        echo "STACK DEDYKOWANY (tenant=${slug}) — aplikacja JEST tenantem"
    elif echo "$provisioned" | grep -q "not-provisioned"; then
        echo "STACK WSPÓŁDZIELONY — jedna aplikacja, wielu tenantów po subdomenie"
    else
        # Slug blank but the database claims singleton provisioning, or the
        # assert failed for another reason. Never guess past this.
        echo "NIESPÓJNY — TENANT_SLUG puste, ale provisioning mówi: ${provisioned}"
    fi
    # <<< classify-body-end
}

# --- local measurement -----------------------------------------------------

measure_local() {
    cd "$PROJECT_ROOT"

    if ! docker compose ps app --format '{{.State}}' 2>/dev/null | grep -q running; then
        {
            echo "MODEL|NIEZMIERZONY — kontener app nie działa"
            echo "DETAIL|Uruchom stack, potem: scripts/architecture-facts.sh"
        } > "$LOCAL_CACHE"
        return
    fi

    # One exec, not five: this runs behind a hook on the user's own machine.
    # Everything goes through Laravel's config -- `getenv("TENANT_SLUG")` is
    # false even when .env sets it, and config('app.tenant_slug') is the exact
    # expression ResolveTenant.php:36 branches on, so the verdict here cannot
    # drift from the verdict the running app reaches.
    local facts slug app_url app_domain queue orgs provisioned
    # `</dev/null` is mandatory, not defensive: `docker compose exec -T` inherits
    # and CONSUMES this process's stdin. Run over an ssh heredoc it swallows the
    # remaining script and the session dies mid-read, silently. Same class as
    # tests/shell/cases/17.
    facts="$(docker compose exec -T app php artisan tinker --execute \
        'echo "SLUG=".config("app.tenant_slug")."|URL=".config("app.url")."|DOM=".config("app.domain")."|Q=".config("queue.default")."|ORGS=".\App\Models\Organization::withoutGlobalScopes()->count();' \
        </dev/null 2>/dev/null | tr -d '\r' | grep -m1 'SLUG=' || echo "")"

    provisioned="$(docker compose exec -T app php artisan registro:tenant-provisioned --assert 2>&1 </dev/null | tr -d '\r' | head -1 || true)"

    if [ -z "$facts" ]; then
        {
            echo "MODEL|NIEZMIERZONY — odczyt configu w kontenerze nie powiódł się"
            echo "DETAIL|Sprawdź ręcznie: docker compose exec -T app php artisan tenant-provisioned --assert"
        } > "$LOCAL_CACHE"
        return
    fi

    slug="$(echo "$facts"       | sed -n 's/.*SLUG=\([^|]*\).*/\1/p')"
    app_url="$(echo "$facts"    | sed -n 's/.*URL=\([^|]*\).*/\1/p')"
    app_domain="$(echo "$facts" | sed -n 's/.*DOM=\([^|]*\).*/\1/p')"
    queue="$(echo "$facts"      | sed -n 's/.*Q=\([^|]*\).*/\1/p')"
    orgs="$(echo "$facts"       | sed -n 's/.*ORGS=\([0-9]*\).*/\1/p')"

    {
        echo "MODEL|$(classify "$slug" "$provisioned")"
        echo "DETAIL|APP_URL=${app_url} APP_DOMAIN=${app_domain} QUEUE=${queue} organizacji=${orgs:-?}"
    } > "$LOCAL_CACHE"
}

ensure_local_fresh() {
    local age
    age="$(file_age_seconds "$LOCAL_CACHE")"
    if [ "$age" -gt "$LOCAL_TTL_SECONDS" ]; then
        measure_local
    fi
}

# --- remote measurement ----------------------------------------------------

measure_uat() {
    echo "Łączę się z ${UAT_USER}@${UAT_HOST} (odczyt, bez zmian)..." >&2

    if ! ssh -o ConnectTimeout=8 -o BatchMode=yes "${UAT_USER}@${UAT_HOST}" true 2>/dev/null; then
        echo "BŁĄD: brak dostępu SSH do ${UAT_USER}@${UAT_HOST}." >&2
        echo "Snapshot NIE został zapisany — wolę brak danych niż zmyślone." >&2
        return 1
    fi

    local remote_out
    # Read-only: prints env values and the provisioning verdict. Touches nothing.
    remote_out="$(ssh -o BatchMode=yes "${UAT_USER}@${UAT_HOST}" bash -s <<REMOTE 2>/dev/null || true
cd "${UAT_APP_DIR}" 2>/dev/null || { echo "NO_APP_DIR"; exit 0; }
facts=\$(docker compose exec -T app php artisan tinker --execute \
  'echo "F|SLUG=".config("app.tenant_slug")."|URL=".config("app.url")."|DOM=".config("app.domain")."|Q=".config("queue.default");' \
  </dev/null 2>/dev/null | tr -d '\r' | grep -m1 '^F|' || echo "")
prov=\$(docker compose exec -T app php artisan registro:tenant-provisioned --assert 2>&1 </dev/null | head -1 || true)
echo "FACTS=\${facts}"
echo "PROV=\${prov}"
REMOTE
)"

    # Two distinct causes, two distinct messages. Collapsing them once made this
    # script report "directory not found" for a directory that existed, because
    # the real cause was an empty read -- naming a cause it had not established
    # is exactly what this tool exists to prevent.
    if echo "$remote_out" | grep -q "NO_APP_DIR"; then
        echo "BŁĄD: ${UAT_HOST} nie ma katalogu ${UAT_APP_DIR} (ustaw REGISTRO_LEGACY_APP_DIR)." >&2
        return 1
    fi

    if [ -z "$remote_out" ]; then
        echo "BŁĄD: ${UAT_HOST} nie zwrócił nic — katalog istnieje, ale odczyt się nie udał." >&2
        echo "Sprawdź ręcznie: ssh ${UAT_USER}@${UAT_HOST} 'cd ${UAT_APP_DIR} && docker compose ps'" >&2
        return 1
    fi

    local facts slug prov detail
    facts="$(echo "$remote_out" | sed -n 's/^FACTS=//p' | head -1)"
    prov="$(echo "$remote_out" | sed -n 's/^PROV=//p' | head -1)"

    if [ -z "$facts" ]; then
        echo "BŁĄD: ${UAT_HOST} nie zwrócił odczytu configu — snapshot NIE zapisany." >&2
        return 1
    fi

    slug="$(echo "$facts" | sed -n 's/.*SLUG=\([^|]*\).*/\1/p')"
    detail="APP_URL=$(echo "$facts" | sed -n 's/.*URL=\([^|]*\).*/\1/p') APP_DOMAIN=$(echo "$facts" | sed -n 's/.*DOM=\([^|]*\).*/\1/p') QUEUE=$(echo "$facts" | sed -n 's/.*Q=\([^|]*\).*/\1/p')"

    {
        echo "MODEL|$(classify "$slug" "$prov")"
        echo "DETAIL|${detail}"
        echo "HOST|${UAT_USER}@${UAT_HOST}:${UAT_APP_DIR}"
    } > "$UAT_CACHE"

    echo "Zapisano snapshot UAT." >&2
}

# --- rendering -------------------------------------------------------------

render_env() {
    local label="$1" cache="$2" age
    age="$(file_age_seconds "$cache")"

    if [ ! -f "$cache" ]; then
        echo "  ${label}: **NIEZNANY** — brak pomiaru"
        return
    fi

    local model detail
    model="$(sed -n 's/^MODEL|//p' "$cache" | head -1)"
    detail="$(sed -n 's/^DETAIL|//p' "$cache" | head -1)"

    echo "  ${label}: ${model}"
    [ -n "$detail" ] && echo "      ${detail}"
    echo "      (zmierzone $(human_age "$age"))"
}

render_hook() {
    ensure_local_fresh

    local uat_age
    uat_age="$(file_age_seconds "$UAT_CACHE")"

    echo ""
    echo "## ARCHITEKTURA — FAKTY ZMIERZONE (nie odczytane z plików repo)"
    echo ""
    render_env "LOKALNIE" "$LOCAL_CACHE"

    if [ ! -f "$UAT_CACHE" ]; then
        echo "  UAT (${UAT_HOST}): **NIEZNANY — NIE ZMIERZONY**"
        echo "      Każde twierdzenie o zachowaniu na UAT/produkcji jest NIEZWERYFIKOWANE."
        echo "      Zmierz (odczyt, wymaga zgody użytkownika): scripts/architecture-facts.sh --uat"
    elif [ "$uat_age" -gt "$UAT_MAX_AGE_SECONDS" ]; then
        render_env "UAT (PRZETERMINOWANY)" "$UAT_CACHE"
        echo "      Starszy niż 7 dni — traktuj jak nieznany, zmierz ponownie."
    else
        render_env "UAT" "$UAT_CACHE"
    fi

    echo ""
    echo "  ZASADA: oba modele chcą PRZECIWNEGO kodu tam, gdzie w grę wchodzi"
    echo "  tożsamość tenanta. Na stacku współdzielonym komponent MUSI ustalić"
    echo "  tenanta; na dedykowanym ustalanie tenanta JEST błędem. Zanim"
    echo "  zaproponujesz zmianę dotykającą tenantów, URL-i w mailach, kolejek"
    echo "  lub middleware — ustal, o którym modelu mowa."
    echo ""
}

render_full() {
    ensure_local_fresh
    echo "Fakty architektoniczne — zmierzone, nie wywnioskowane"
    echo "======================================================"
    echo ""
    render_env "LOKALNIE" "$LOCAL_CACHE"
    echo ""
    if [ -f "$UAT_CACHE" ]; then
        render_env "UAT" "$UAT_CACHE"
        echo "      $(sed -n 's/^HOST|//p' "$UAT_CACHE" | head -1)"
    else
        echo "  UAT: **NIEZNANY** — uruchom: $0 --uat"
    fi
    echo ""
    echo "Dyskryminator: config('app.tenant_slug') — ResolveTenant.php:36"
    echo "Kontrola krzyżowa env vs baza: php artisan registro:tenant-provisioned --assert"
}

# --- entrypoint ------------------------------------------------------------

case "${1:---full}" in
    --hook)  render_hook ;;
    --uat)   measure_uat && render_full ;;
    --clear) rm -f "$LOCAL_CACHE" "$UAT_CACHE"; echo "Cache wyczyszczony." ;;
    --full|*) render_full ;;
esac

#!/bin/bash
###############################################################################
# Pins: .claude/hooks/stop-gate.sh (Stop hook) -- rewritten 2026-09-27.
#
# The previous version ended with `exit 1` and printed to stdout. For a Stop
# hook that is a non-blocking notice: Claude Code shows it and lets the turn
# end. It never blocked once, although CLAUDE.md said it did. A Stop hook
# blocks only with exit 2, and only stderr reaches Claude.
#
# It also never asked about docs/business/ (now docs/oferta/), which is why the
# customer-facing docs went a month without an update while ~15 customer-facing
# features shipped.
#
# Runs the REAL hook (stdin JSON in, exit code + stderr out) inside a real
# throwaway git repo, so `git diff` reflects each scenario.
###############################################################################
set -uo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/../lib/harness.sh"
test_start "stop-gate.sh: exit 2 on missing docs, offer question, loop guard"

HOOK="$REPO_ROOT/.claude/hooks/stop-gate.sh"
[ -f "$HOOK" ] || fail "hook not found at $HOOK"

sandbox_init
REPO_DIR="$SANDBOX/repo"

reset_repo() {
    rm -rf "$REPO_DIR"
    mkdir -p "$REPO_DIR"
    cd "$REPO_DIR"
    git init -q
    git config user.email test@example.com
    git config user.name test
    : >README.md
    git add README.md
    git commit -qm init >/dev/null
}

touch_file() {
    mkdir -p "$(dirname "$1")"
    echo "x" >>"$1"
    git add "$1"
}

# Runs the hook; sets RC and ERR (stderr). stdout is discarded on purpose --
# anything the hook wants Claude to read must be on stderr.
run_hook() {
    local stdin_json="${1:-{\}}"
    ERR="$(printf '%s' "$stdin_json" | bash "$HOOK" 2>&1 >/dev/null)"
    RC=$?
}

# --- Case 1: clean tree -> allowed. ---
reset_repo
run_hook
assert_eq "0" "$RC" "clean tree"

# --- Case 2: 5 PHP files, no docs -> BLOCKED with exit 2 on stderr. ---
reset_repo
for i in 1 2 3 4 5; do touch_file "app/Services/S$i.php"; done
run_hook '{"stop_hook_active":false}'
assert_eq "2" "$RC" "5 source files, 0 docs"
assert_contains "$ERR" "BLOCKED" "5 source files, 0 docs (stderr)"

# --- Case 3: same diff, but Claude is already continuing because of this
# hook -> let it stop (loop guard). ---
run_hook '{"stop_hook_active": true}'
assert_eq "0" "$RC" "loop guard: stop_hook_active=true"

# --- Case 4: 5 PHP files + a docs/ change (not only app/docs/) counts. ---
touch_file "docs/oferta/README.md"
run_hook
assert_eq "0" "$RC" "5 source files + docs/oferta change"

# --- Case 5: one customer-facing view changed, docs/oferta untouched ->
# the offer question (exit 2). ---
reset_repo
touch_file "resources/views/cart/show.blade.php"
run_hook
assert_eq "2" "$RC" "customer-facing view, no docs/oferta"
assert_contains "$ERR" "docs/oferta" "offer question names docs/oferta"
assert_contains "$ERR" "Brak wpływu na ofertę" "offer question offers the explicit no-impact answer"

# --- Case 6: customer-facing change answered by an offer update -> allowed. ---
touch_file "docs/oferta/wypozyczalnia.md"
run_hook
assert_eq "0" "$RC" "customer-facing view + docs/oferta update"

# --- Case 7: untracked new notification (never `git add`-ed) still counts. ---
reset_repo
mkdir -p app/Notifications && echo x >app/Notifications/NewThing.php
run_hook
assert_eq "2" "$RC" "untracked customer-facing file"

# --- Case 8: internal-only change (service + its dev doc) -> no offer question. ---
reset_repo
touch_file "app/Services/Internal.php"
touch_file "app/docs/features/internal.md"
run_hook
assert_eq "0" "$RC" "internal-only change"

test_finish

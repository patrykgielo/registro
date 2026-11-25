#!/bin/bash
###############################################################################
# Pins: lib/wsl-forward.sh -- the shim every hook sources, which re-executes the
# hook inside WSL when Claude Code runs it from Windows Git Bash.
#
# THE BUG: hooks inspected their OWN cwd. From the Windows app that is a mirror
# clone (always develop, never changes, no docker, no jq), so the branch rule
# misfired, the Stop gate saw no changes and the quality gate had no docker.
#
# Drives the REAL shim and the REAL wsl-boot.sh. Only `wsl.exe` is faked: it
# records its argv and stdin, drops `-d DISTRO -e` and runs the rest locally --
# so what is asserted is the actual command line the shim builds, and what the
# forwarded process actually sees (cwd, env, stdin, exit status).
# REGISTRO_HOOK_ASSUME_WINDOWS=1 is the documented test seam for "OSTYPE=msys".
# NOT PROVEN HERE: that real wsl.exe accepts this command line. That was proven
# by hand from Git Bash (see the PR description / ci-cd-troubleshooting.md).
###############################################################################
set -uo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/../lib/harness.sh"
test_start "wsl-forward.sh: forwards to WSL with stdin/cwd/env/exit status intact, fails closed"

sandbox_init

# --- A sandbox "project" with a copy of the REAL lib/, plus a probe hook ------
PROJ="$SANDBOX/proj"
TREE="$SANDBOX/wsltree"
mkdir -p "$PROJ/.claude/hooks" "$TREE"
cp -r "$REPO_ROOT/.claude/hooks/lib" "$PROJ/.claude/hooks/lib"
cat >"$PROJ/.claude/hooks/probe.sh" <<'EOS'
#!/bin/bash
source "${BASH_SOURCE[0]%/*}/lib/wsl-forward.sh"
registro_wsl_forward "${BASH_SOURCE[0]}" "$@"
hook_read_input
printf 'PWD=%s\nCLAUDE_PROJECT_DIR=%s\nORIGIN=%s\nCLONE=%s\nASSUME=%s\nARGS=%s\nWSLENV=%s\nSTDIN=%s\n' \
    "$PWD" "$CLAUDE_PROJECT_DIR" "${REGISTRO_HOOK_ORIGIN:-}" "${REGISTRO_WIN_CLONE:-}" \
    "${REGISTRO_HOOK_ASSUME_WINDOWS:-unset}" "$*" "${WSLENV:-}" "$HOOK_INPUT"
exit "${PROBE_RC:-0}"
EOS
chmod +x "$PROJ/.claude/hooks/probe.sh"

fake_exe wsl.exe <<'EOS'
while [ $# -gt 0 ]; do
    case "$1" in
        -d) shift 2 ;;
        -e) shift; break ;;
        *) shift ;;
    esac
done
[ -z "${FAKE_WSL_RC:-}" ] || exit "$FAKE_WSL_RC"          # wsl.exe itself failing
[ -z "${FAKE_WSL_SILENT:-}" ] || { cat >/dev/null; exit 0; } # exits 0, says nothing
tee "$SANDBOX/wsl.stdin" | exec "$@"
EOS

wsl_calls() { grep -c '^wsl.exe ' "$CALL_LOG" || true; }

# --- 1. forwarded: cwd, env, args, stdin, and a hook exit status of 2 survive --
OUT="$(printf '%s' '{"k":"v with \"quotes\""}' | REGISTRO_HOOK_ASSUME_WINDOWS=1 PROBE_RC=2 \
    REGISTRO_WSL_TREE="$TREE" bash "$PROJ/.claude/hooks/probe.sh" --flag value 2>"$SANDBOX/stderr")"
RC=$?
assert_eq "2" "$RC" "the hook's own exit status (2 = block) passes through the shim"
assert_contains "$OUT" "PWD=$TREE" "forwarded hook runs with the WSL tree as cwd"
assert_contains "$OUT" "CLAUDE_PROJECT_DIR=$TREE" "CLAUDE_PROJECT_DIR is the WSL tree inside WSL"
assert_contains "$OUT" "ORIGIN=windows" "forwarded process knows it was forwarded"
assert_contains "$OUT" "CLONE=$PROJ" "the Windows clone is passed alongside"
assert_contains "$OUT" "ASSUME=unset" "the test seam does not leak into the forwarded process (no re-forwarding)"
assert_contains "$OUT" "ARGS=--flag value" "hook arguments are forwarded"
assert_contains "$OUT" 'STDIN={"k":"v with \"quotes\""}' "stdin is preserved byte for byte"
assert_eq '{"k":"v with \"quotes\""}' "$(cat "$SANDBOX/wsl.stdin")" "wsl.exe received the original stdin"
assert_eq "1" "$(wsl_calls)" "exactly one wsl.exe call"
# REGISTRO_TESTS (read by subagent-quality-gate.sh) only crosses wsl.exe if WSLENV lists it.
assert_contains "$OUT" "WSLENV=REGISTRO_TESTS" "REGISTRO_TESTS is exported across wsl.exe via WSLENV"
OUT2="$(printf '{}' | REGISTRO_HOOK_ASSUME_WINDOWS=1 WSLENV='FOO/p:REGISTRO_TESTS/u' REGISTRO_WSL_TREE="$TREE" \
    bash "$PROJ/.claude/hooks/probe.sh" 2>/dev/null)"
assert_contains "$OUT2" "WSLENV=FOO/p:REGISTRO_TESTS/u"$'\n' "an existing WSLENV entry for it is kept, not duplicated"
OUT2="$(printf '{}' | REGISTRO_HOOK_ASSUME_WINDOWS=1 WSLENV='FOO/p' REGISTRO_WSL_TREE="$TREE" \
    bash "$PROJ/.claude/hooks/probe.sh" 2>/dev/null)"
assert_contains "$OUT2" "WSLENV=FOO/p:REGISTRO_TESTS" "other WSLENV entries are preserved"

# --- 2. the command line the shim builds, with NO configuration at all -----------
: >"$CALL_LOG"
printf '{}' | env -u REGISTRO_WSL_TREE -u REGISTRO_WSL_DISTRO REGISTRO_HOOK_ASSUME_WINDOWS=1     bash "$PROJ/.claude/hooks/probe.sh" >/dev/null 2>&1
LINE="$(grep '^wsl.exe ' "$CALL_LOG" | head -1)"
assert_contains "$LINE" "-d Ubuntu-24.04 -e bash" "default distro, exec form (no shell re-parse)"
assert_contains "$LINE" "$PROJ/.claude/hooks/lib/wsl-boot.sh" "boot script path"
assert_contains "$LINE" "/var/www/projects/registro/app" "default tree is the documented one"

# --- 3. distro and tree are configurable ----------------------------------------
: >"$CALL_LOG"
printf '{}' | REGISTRO_HOOK_ASSUME_WINDOWS=1 REGISTRO_WSL_DISTRO=Debian-X REGISTRO_WSL_TREE="$TREE" \
    bash "$PROJ/.claude/hooks/probe.sh" >/dev/null 2>&1
assert_contains "$(cat "$CALL_LOG")" "-d Debian-X" "REGISTRO_WSL_DISTRO is honoured"

# --- 4. a native session does NOT forward ---------------------------------------
: >"$CALL_LOG"
OUT="$(printf '{}' | env -u REGISTRO_HOOK_ASSUME_WINDOWS -u REGISTRO_HOOK_ORIGIN CLAUDE_PROJECT_DIR="$PROJ" \
    bash "$PROJ/.claude/hooks/probe.sh" 2>&1)"
assert_eq "0" "$(wsl_calls)" "no wsl.exe call outside Windows"
assert_contains "$OUT" "PWD=" "the hook still ran, in place"

# --- 5. WSL cannot run the hook: nothing is checked, and that is SAID ------------
OUT="$(printf '{}' | REGISTRO_HOOK_ASSUME_WINDOWS=1 FAKE_WSL_RC=255 REGISTRO_WSL_TREE="$TREE" \
    bash "$PROJ/.claude/hooks/probe.sh" 2>&1 >/dev/null)"
RC=$?
assert_contains "$OUT" "did NOT run" "default failure handler says the hook did not run"
assert_eq "1" "$RC" "...and exits non-zero (a visible hook error)"

# --- 6. tree missing in WSL: the boot script refuses (97) ------------------------
OUT="$(printf '{}' | REGISTRO_HOOK_ASSUME_WINDOWS=1 REGISTRO_WSL_TREE="$SANDBOX/no-such-tree" \
    bash "$PROJ/.claude/hooks/probe.sh" 2>&1 >/dev/null)"
assert_contains "$OUT" "does not exist in this WSL distro" "boot script names the missing tree"
assert_contains "$OUT" "did NOT run" "...and the shim reports the hook as not run"

# --- 7. pre-tool-use fails CLOSED on a guarded command, passes the rest ---------
HOOK_PROJ="$SANDBOX/proj2"
mkdir -p "$HOOK_PROJ/.claude/hooks"
cp -r "$REPO_ROOT/.claude/hooks/lib" "$HOOK_PROJ/.claude/hooks/lib"
cp "$REPO_ROOT/.claude/hooks/pre-tool-use.sh" "$HOOK_PROJ/.claude/hooks/"
PRE="$HOOK_PROJ/.claude/hooks/pre-tool-use.sh"

: >"$CALL_LOG"
OUT="$(pretool_json 'ls -la' "$SANDBOX" | REGISTRO_HOOK_ASSUME_WINDOWS=1 REGISTRO_WSL_TREE="$TREE" bash "$PRE" 2>/dev/null)"
assert_eq "pass" "$(decision_of "$OUT")" "unguarded command answered by the Windows-side prefilter"
assert_not_contains "$OUT" "permissionDecision" "...and its pass carries no permissionDecision (never allow)"
assert_eq "0" "$(wsl_calls)" "...without ever starting WSL (hot path stays cheap)"

: >"$CALL_LOG"
OUT="$(pretool_json 'git status' "$SANDBOX" | REGISTRO_HOOK_ASSUME_WINDOWS=1 REGISTRO_WSL_TREE="$TREE" bash "$PRE" 2>/dev/null)"
assert_eq "1" "$(wsl_calls)" "a command mentioning git is forwarded"

OUT="$(pretool_json 'git commit -m x' "$SANDBOX" | REGISTRO_HOOK_ASSUME_WINDOWS=1 FAKE_WSL_RC=255 REGISTRO_WSL_TREE="$TREE" bash "$PRE" 2>/dev/null)"
assert_eq "deny" "$(decision_of "$OUT")" "guarded command + WSL down => deny, not allow"
assert_contains "$(reason_of "$OUT")" "NOT checked" "the reason says the command was not checked"

OUT="$(pretool_json 'git commit -m x' "$SANDBOX" | REGISTRO_HOOK_ASSUME_WINDOWS=1 FAKE_WSL_SILENT=1 REGISTRO_WSL_TREE="$TREE" bash "$PRE" 2>/dev/null)"
assert_eq "deny" "$(decision_of "$OUT")" "WSL exited 0 with no answer => deny (the guard never really ran)"

OUT="$(pretool_json 'git commit -m x' "$SANDBOX" | REGISTRO_HOOK_ASSUME_WINDOWS=1 REGISTRO_WSL_TREE="$SANDBOX/no-such-tree" bash "$PRE" 2>/dev/null)"
assert_eq "deny" "$(decision_of "$OUT")" "tree missing in WSL => deny"

# --- 8. path translation ---------------------------------------------------------
( # shellcheck disable=SC1091
  source "$REPO_ROOT/.claude/hooks/lib/wsl-forward.sh"
  export REGISTRO_HOOK_ASSUME_WINDOWS=1
  t() { hook_to_wsl_path "$1"; printf '%s' "$HOOK_WSL_PATH"; }
  printf '%s\n' \
    "1|$(t 'C:\projects\registro')|/mnt/c/projects/registro" \
    "2|$(t 'C:/projects/registro/.claude/hooks')|/mnt/c/projects/registro/.claude/hooks" \
    "3|$(t '/c/projects/registro')|/mnt/c/projects/registro" \
    "4|$(t 'D:\x')|/mnt/d/x" \
    "5|$(t '\\wsl.localhost\Ubuntu-24.04\var\www\projects\registro\app-hooks')|/var/www/projects/registro/app-hooks" \
    "6|$(t '//wsl$/Ubuntu-24.04/var/www/x')|/var/www/x" \
    "7|$(t '/var/www/projects/registro/app')|/var/www/projects/registro/app" \
    "8|$(t '/usr/bin/x')|/usr/bin/x"
) >"$SANDBOX/paths.txt"
while IFS='|' read -r n got want; do
    assert_eq "$want" "$got" "path translation case $n"
done <"$SANDBOX/paths.txt"

test_finish

#!/bin/bash
###############################################################################
# Pins: scripts/cc-doctor.sh notices every way the hooks can be silently dead.
#
# THE FAILURE: the five hook scripts lived in the repo for months while the
# wiring lived only in a gitignored settings.local.json that did not exist on
# this machine. Nothing ran, nothing said so, and .claude/rules/ described
# enforcement that was not happening. cc-doctor existed to catch exactly this
# class (its own header: "a missing artifact is a FAILURE, never a silent skip")
# but its hook check silently skipped any file that was not there, and its
# SessionStart output was built with `jq` -- absent -- so even a real finding was
# computed and then dropped on the floor.
#
# Each case below breaks ONE thing in a throwaway project and demands a finding;
# the unbroken project must be clean. Also pinned: --hook mode emits valid JSON
# with no jq and exits 0 (Claude Code reads a hook's JSON only on exit 0).
###############################################################################
set -uo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/../lib/harness.sh"
test_start "cc-doctor.sh: flags unwired / missing / non-executable hooks, bad settings, no parser, clone drift"

sandbox_init
fake_exe jq <<'EOS'
exit 99
EOS

P="$SANDBOX/proj"
USERDIR="$SANDBOX/userdir"
mkdir -p "$USERDIR" "$SANDBOX/home"

build() { # a fresh, committed, CLEAN project
    rm -rf "$P"
    mkdir -p "$P/scripts" "$P/.claude/hooks"
    new_repo "$P" develop
    cp -r "$REPO_ROOT/.claude/hooks/." "$P/.claude/hooks/"
    cp "$REPO_ROOT/.claude/settings.json" "$P/.claude/settings.json"
    cp "$REPO_ROOT/scripts/cc-doctor.sh" "$P/scripts/cc-doctor.sh"
    chmod +x "$P"/.claude/hooks/*.sh "$P"/.claude/hooks/lib/*.sh "$P"/scripts/cc-doctor.sh
    git -C "$P" add -A && git -C "$P" commit -qm project >/dev/null
    touch "$P/.claude/.cc-doctor-mcp-stamp" # MCP health is checked weekly; not what this tests
}

doctor() { # [env...] [-- args]  -> OUT, RC
    OUT="$(env -u REGISTRO_HOOK_ORIGIN -u REGISTRO_HOOK_ASSUME_WINDOWS HOME="$SANDBOX/home" \
        CLAUDE_CONFIG_DIR="$USERDIR" REGISTRO_WIN_CLONE= "$@" bash "$P/scripts/cc-doctor.sh" 2>&1)"
    RC=$?
}
doctor_args() { # ARGS... (no extra env)
    OUT="$(env -u REGISTRO_HOOK_ORIGIN -u REGISTRO_HOOK_ASSUME_WINDOWS HOME="$SANDBOX/home" \
        CLAUDE_CONFIG_DIR="$USERDIR" REGISTRO_WIN_CLONE= bash "$P/scripts/cc-doctor.sh" "$@" 2>/dev/null)"
    RC=$?
}

# --- 0. the committed project is clean -----------------------------------------------
build
doctor X=1
assert_eq "0" "$RC" "unmodified project: exit 0"
assert_contains "$OUT" "cc-doctor: clean" "unmodified project: reported clean (every hook script is wired)"

# --- 1. no settings.json at all: the exact original failure ---------------------------
build
git -C "$P" rm -q -f .claude/settings.json
doctor X=1
assert_eq "1" "$RC" "no settings.json: findings"
assert_contains "$OUT" ".claude/settings.json is missing" "missing settings.json is a FAILURE, not a skip"
assert_contains "$OUT" "wired in no settings file: .claude/hooks/pre-tool-use.sh" "...and the unwired script is named"

# --- 2. one hook unwired ----------------------------------------------------------------
build
python3 - "$P/.claude/settings.json" <<'PY'
import json, sys
p = sys.argv[1]
d = json.load(open(p))
del d["hooks"]["Stop"]
json.dump(d, open(p, "w"), indent=2)
PY
doctor X=1
assert_contains "$OUT" "wired in no settings file: .claude/hooks/stop-gate.sh" "an unwired hook script is flagged by name"

# --- 3. wired script missing / not executable --------------------------------------------
build
git -C "$P" rm -q -f .claude/hooks/prompt-submit.sh
doctor X=1
assert_contains "$OUT" "hook script missing:" "wired-but-missing script"
assert_contains "$OUT" "prompt-submit.sh" "...named"

build
git -C "$P" update-index --chmod=-x .claude/hooks/pre-tool-use.sh
doctor X=1
assert_contains "$OUT" "not executable" "git mode 100644 on a hook is flagged (the index, not the NTFS mount, is the truth)"

# --- 4. settings.json that does not parse unwires EVERYTHING -------------------------------
build
sed -i '0,/"hooks": {/s//"hooks": {,/' "$P/.claude/settings.json"
doctor X=1
assert_contains "$OUT" "is not valid JSON" "a broken settings.json is flagged"

# --- 5. the same hook wired twice runs twice -----------------------------------------------
build
cp "$P/.claude/settings.json" "$P/.claude/settings.local.json"
doctor X=1
assert_contains "$OUT" "BOTH settings.json and settings.local.json" "duplicate wiring is flagged"

# --- 6. no JSON parser where hooks run ----------------------------------------------------
build
doctor REGISTRO_HOOK_PYTHONS=no-such-python
assert_eq "1" "$RC" "no parser: findings"
assert_contains "$OUT" "no python3" "the missing parser is a finding"
assert_contains "$OUT" "hooks cannot read their input" "...with what it breaks"

# --- 7. --hook mode: valid JSON without jq, exit 0 on findings, silent when clean ------------
build
git -C "$P" rm -q -f .claude/settings.json
doctor_args --hook
assert_eq "0" "$RC" "--hook with findings exits 0 (Claude Code ignores the JSON of any other status)"
CTX="$(printf '%s' "$OUT" | python3 -c 'import json,sys; d=json.load(sys.stdin); print(d["hookSpecificOutput"]["additionalContext"]); print(d["systemMessage"][:20])' 2>&1)"
assert_contains "$CTX" "settings.json is missing" "--hook output is valid JSON carrying the finding in additionalContext"
assert_contains "$CTX" "cc-doctor found" "...and a systemMessage for the user"
assert_not_contains "$(cat "$CALL_LOG")" "jq" "jq was never needed"
build
doctor_args --hook
assert_eq "" "$OUT" "--hook on a clean project prints nothing"
assert_eq "0" "$RC" "--hook clean: exit 0"

# --- 7b. the PreToolUse guard must select the PowerShell tool as well as Bash -----------------
# (primary shell tool on the Windows app; a matcher of just "Bash" leaves it unguarded)
set_matcher() { # VALUE (empty = remove the matcher key)
    python3 - "$P/.claude/settings.json" "$1" <<'PY'
import json, sys
p, m = sys.argv[1], sys.argv[2]
d = json.load(open(p))
if m:
    d["hooks"]["PreToolUse"][0]["matcher"] = m
else:
    d["hooks"]["PreToolUse"][0].pop("matcher", None)
json.dump(d, open(p, "w"), indent=2)
PY
}
for m in 'Bash' 'PowerShell' 'Bash|Edit' 'Bas.*'; do
    build
    set_matcher "$m"
    doctor X=1
    assert_contains "$OUT" "does not select BOTH Bash and PowerShell" "matcher '$m' is flagged"
done
for m in 'Bash|PowerShell' 'PowerShell|Bash' '.*' '(Bash|PowerShell)' ''; do
    build
    set_matcher "$m"
    doctor X=1
    assert_not_contains "$OUT" "does not select BOTH" "matcher '${m:-<none>}' is accepted"
done

# --- 8. Windows-clone drift (manual runs) ----------------------------------------------------
build
TREE="$SANDBOX/tree"
git clone -q "$P" "$TREE" 2>/dev/null
echo "only on the WSL tree" >"$TREE/.claude/branch-only.md"
git -C "$TREE" add -A && git -C "$TREE" -c user.email=t@e.st -c user.name=t commit -qm drift >/dev/null
doctor REGISTRO_WIN_CLONE="$P" REGISTRO_WSL_TREE="$TREE"
assert_contains "$OUT" "differ from the WSL tree" "clone drift is a finding (same branch)"
assert_contains "$OUT" ".claude/branch-only.md" "...naming the file"
# S3: the WSL tree on a FEATURE branch legitimately differs -- not drift.
git -C "$TREE" checkout -q -b feature/something
doctor REGISTRO_WIN_CLONE="$P" REGISTRO_WSL_TREE="$TREE"
assert_not_contains "$OUT" "differ from the WSL tree" "WSL tree on a feature branch: not reported as drift"
git -C "$TREE" checkout -q "$(git -C "$P" symbolic-ref --short HEAD)"
doctor REGISTRO_WIN_CLONE="$P" REGISTRO_WSL_TREE="$SANDBOX/no-such-tree"
assert_contains "$OUT" "does not exist" "an absent WSL tree is a finding, not a skipped check"
OUT="$(env -u REGISTRO_HOOK_ORIGIN HOME="$SANDBOX/home" CLAUDE_CONFIG_DIR="$USERDIR" REGISTRO_WIN_CLONE="$P" \
    REGISTRO_WSL_TREE="$TREE" bash "$P/scripts/cc-doctor.sh" --hook 2>/dev/null)"
assert_eq "" "$OUT" "--hook leaves drift to session-sync.sh (no double report, no race)"

test_finish

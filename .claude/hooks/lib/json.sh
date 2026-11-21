# shellcheck shell=bash
###############################################################################
# JSON plumbing for the hooks, with NO jq dependency.
#
# Every hook used to `jq -r` its stdin. jq is installed on neither the Windows
# host nor the WSL distro, and `jq: command not found` inside `$(...)` merely
# leaves the variable empty -- so pre-tool-use.sh saw an empty command and
# silently blocked nothing. A missing parser must be LOUD or avoided; here it
# is avoided (python3 ships with WSL) and, when truly absent, reported by the
# callers instead of being read as "nothing to check".
#
# jq is deliberately not a fallback: it is installed nowhere we run, and a
# fallback path nobody can execute is a fallback nobody has tested.
#
# Sourced, never executed. Needs bash >= 4.
###############################################################################

HOOK_INPUT=""
HOOK_INPUT_READ=0

# hook_read_input -- read stdin once. Safe to call repeatedly; the shim may have
# consumed it already (it must, to forward it).
hook_read_input() {
    if [ "$HOOK_INPUT_READ" -eq 0 ]; then
        HOOK_INPUT="$(cat)"
        HOOK_INPUT_READ=1
    fi
}

# hook_python -- find a python 3: sets $HOOK_PYTHON (cached) and returns 0, or
# returns non-zero. Not printed, so callers pay no subshell, and each python
# startup is ~20 ms that a PreToolUse hook cannot afford to spend on probing.
# `python3` is taken at its word; plain `python` may be Python 2, so only that
# one is asked for its version. A python3 that is present but broken shows up
# where it is used (hook_parse returns non-zero) -- loudly, not as "no command".
# REGISTRO_HOOK_PYTHONS overrides the candidates (tests use it to prove the
# no-parser path; it is not an operator knob).
HOOK_PYTHON=""
hook_python() {
    [ -z "$HOOK_PYTHON" ] || return 0
    local p
    for p in ${REGISTRO_HOOK_PYTHONS:-python3 python}; do
        command -v "$p" >/dev/null 2>&1 || continue
        if [ "$p" != python3 ] && ! "$p" -c 'import sys; sys.exit(0 if sys.version_info[0] >= 3 else 1)' >/dev/null 2>&1; then
            continue
        fi
        HOOK_PYTHON="$p"
        return 0
    done
    return 1
}

# hook_parse VAR:dotted.path ... -- read each path from $HOOK_INPUT into VAR.
# Missing keys give "", booleans give "true"/"false", objects stay JSON.
# Non-zero (and no variable touched) when there is no parser or the input is
# not valid JSON; the caller decides what that means for ITS hook.
#
# FRAMING: python emits `NAME='<shlex-quoted value>'` assignments and bash `eval`s
# them. An earlier version joined the values with 0x1f and split on the first
# 0x1f -- so a command that CONTAINED 0x1f (`echo '<US>'; git commit ...`) was
# cut short and every rule saw only the harmless prefix: a total bypass. A
# shell-quoted value cannot collide with its own framing, whatever bytes it
# holds. (NUL cannot live in a bash string; the value is cut there, which is
# also what the shell that later runs the command sees.) Variable names come
# from the hook's own code and are validated again in python before `eval`.
hook_parse() {
    hook_read_input
    local __hp_out
    hook_python || return 3
    __hp_out="$(printf '%s' "$HOOK_INPUT" | "$HOOK_PYTHON" -X utf8 -c '
import json, re, shlex, sys
try:
    d = json.load(sys.stdin)
except Exception:
    sys.exit(4)
lines = []
for spec in sys.argv[1:]:
    name, _, path = spec.partition(":")
    if not re.fullmatch(r"[A-Za-z_][A-Za-z0-9_]*", name):
        sys.exit(5)
    v = d
    for k in path.split("."):
        v = v.get(k) if isinstance(v, dict) else None
    if v is None:
        v = ""
    elif not isinstance(v, str):
        v = json.dumps(v)
    v = v.split("\x00")[0]
    lines.append("%s=%s" % (name, shlex.quote(v)))
sys.stdout.write("\n".join(lines) + "\n")
' "$@" 2>/dev/null)" || return 4
    eval "$__hp_out"
}

# hook_json_escape STRING -- escape for embedding in a JSON string literal.
# Every control character (< 0x20) must be escaped or the reply is not valid
# JSON -- and an unparseable deny is read by Claude Code as "no decision".
hook_json_escape() {
    local s="$1" c esc
    s="${s//\\/\\\\}"
    s="${s//\"/\\\"}"
    s="${s//$'\n'/\\n}"
    s="${s//$'\r'/\\r}"
    s="${s//$'\t'/\\t}"
    while [[ "$s" =~ ([[:cntrl:]]) ]]; do
        c="${BASH_REMATCH[1]}"
        printf -v esc '\\u%04x' "'$c"
        s="${s//"$c"/$esc}"
    done
    printf '%s' "$s"
}

# HOOK_REPLIED -- set once a PreToolUse answer has been printed. The crash guard
# in pre-tool-use.sh reads it: exiting without an answer is not "no objection".
HOOK_REPLIED=0

# PreToolUse replies. hook_deny keeps the exact shape the hook has always emitted.
hook_deny() {
    HOOK_REPLIED=1
    printf '{"hookSpecificOutput":{"hookEventName":"PreToolUse","permissionDecision":"deny","permissionDecisionReason":"%s"}}\n' "$(hook_json_escape "$1")"
}
# hook_pass -- "this hook has no objection". Deliberately NOT permissionDecision
# "allow": that skips the user's permission prompt for every call that clears the
# rules, i.e. the hook would silently escalate permissions. A guard's job is to
# deny; everything else falls through to the normal permission flow. Valid JSON
# with no decision (not empty output: lib/wsl-forward.sh reads empty stdout as
# "the hook never ran" and fails closed). `suppressOutput` is a universal field
# in the hooks reference, default false, with no effect on permissions.
hook_pass() {
    HOOK_REPLIED=1
    printf '%s\n' '{"suppressOutput":true}'
}

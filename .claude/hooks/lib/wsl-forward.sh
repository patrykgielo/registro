# shellcheck shell=bash
###############################################################################
# The shim every hook sources FIRST.
#
# Claude Code runs from the Windows desktop app: hook commands execute under
# Git Bash (MINGW64), with cwd = a Windows mirror clone that is read-only,
# usually behind origin, and has neither `docker` nor `jq`. The real code, git
# work and Docker live in WSL. A hook that inspects ITS OWN cwd therefore looks
# at the wrong tree, and one that calls `docker compose` finds no docker.
#
# So on Windows Git Bash the shim re-executes the SAME hook script inside WSL,
# stdin preserved, cwd = the WSL tree, and exits with its status. On Linux (a
# native session, or the forwarded process itself) it returns immediately and
# the hook runs where it is -- the old native setup keeps working unchanged.
#
# Usage, at the top of a hook:
#
#     source "${BASH_SOURCE[0]%/*}/lib/wsl-forward.sh"
#     registro_wsl_forward "${BASH_SOURCE[0]}" "$@"
#
# A hook may define, BEFORE calling registro_wsl_forward:
#     HOOK_FORWARD=0                  never forward (pure text, no WSL needed)
#     HOOK_EXPECT_OUTPUT=1            this hook is a GUARD: only rc 0 with a JSON
#                                     answer (or rc 2) counts as it having run
#     HOOK_WSL_TIMEOUT=SECONDS        bound wsl.exe (rc 124 = failure), because
#                                     Claude Code's own kill is fail-OPEN
#     hook_windows_prefilter()        runs in Git Bash with $HOOK_INPUT read;
#                                     return 0 = handled here, skip WSL entirely
#     hook_on_forward_failure RC SCRIPT   called when WSL could not run the
#                                     hook; default prints to stderr, exit 1
#
# Environment (defaults match this project's machine):
#     REGISTRO_WSL_DISTRO   Ubuntu-24.04
#     REGISTRO_WSL_TREE     /var/www/projects/registro/app
###############################################################################

# shellcheck source=json.sh
source "${BASH_SOURCE[0]%/*}/json.sh"

REGISTRO_WSL_DISTRO="${REGISTRO_WSL_DISTRO:-Ubuntu-24.04}"
REGISTRO_WSL_TREE="${REGISTRO_WSL_TREE:-/var/www/projects/registro/app}"

# hook_on_windows -- true under Git Bash / MSYS / Cygwin.
# REGISTRO_HOOK_ASSUME_WINDOWS=1 is a TEST seam: it lets the suite drive the
# forwarding path on Linux with a fake wsl.exe. Never set it for real.
hook_on_windows() {
    [ "${REGISTRO_HOOK_ORIGIN:-}" = windows ] && return 1 # already inside WSL
    [ "${REGISTRO_HOOK_ASSUME_WINDOWS:-}" = 1 ] && return 0
    case "${OSTYPE:-}" in msys* | cygwin* | win32*) return 0 ;; esac
    return 1
}

# hook_to_wsl_path PATH -- Windows / Git Bash / UNC path -> the path WSL sees,
# returned in $HOOK_WSL_PATH. Not printed: under Git Bash every $(...) is a real
# fork, and this runs four times per forwarded hook, on the hot path of every
# tool call.
#   C:\x\y  C:/x/y  /c/x/y  ->  /mnt/c/x/y
#   \\wsl.localhost\Distro\x  ->  /x
# Anything else (already a Linux path) is left unchanged.
hook_to_wsl_path() {
    local p="${1//\\//}" re
    HOOK_WSL_PATH="$1"
    re='^([A-Za-z]):/?(.*)$'
    if [[ "$p" =~ $re ]]; then
        HOOK_WSL_PATH="/mnt/${BASH_REMATCH[1],,}/${BASH_REMATCH[2]}"
        return 0
    fi
    re='^//wsl(\.localhost|\$)/[^/]+(/.*)?$'
    if [[ "$p" =~ $re ]]; then
        HOOK_WSL_PATH="${BASH_REMATCH[2]:-/}"
        return 0
    fi
    re='^/([A-Za-z])(/.*)?$'
    if hook_on_windows && [[ "$p" =~ $re ]]; then
        HOOK_WSL_PATH="/mnt/${BASH_REMATCH[1],,}${BASH_REMATCH[2]}"
        return 0
    fi
    # Any other POSIX-looking path under Git Bash is an MSYS mount, not a WSL
    # path: /tmp is %TEMP%, /usr is Git's install dir. Let cygpath say where it
    # really is. (Skipped when cygpath does not exist, e.g. the test seam.)
    if hook_on_windows && [[ "$p" == /* ]] && command -v cygpath >/dev/null 2>&1; then
        local w
        w="$(cygpath -m "$1" 2>/dev/null)" || w=""
        re='^([A-Za-z]):/?(.*)$'
        if [[ "$w" =~ $re ]]; then
            HOOK_WSL_PATH="/mnt/${BASH_REMATCH[1],,}/${BASH_REMATCH[2]}"
        fi
    fi
    return 0
}

hook_on_forward_failure_default() {
    echo "registro hooks: could not run $(basename "$2") inside WSL (rc=$1, distro '$REGISTRO_WSL_DISTRO', tree '$REGISTRO_WSL_TREE'). The hook did NOT run. Check: wsl.exe -d $REGISTRO_WSL_DISTRO -e true" >&2
    exit 1
}

# registro_wsl_forward SCRIPT [ARGS...] -- see header. Never returns on Windows.
registro_wsl_forward() {
    local script="$1"
    shift
    hook_on_windows || return 0
    [ "${HOOK_FORWARD:-1}" = 0 ] && return 0

    hook_read_input
    if declare -F hook_windows_prefilter >/dev/null 2>&1 && hook_windows_prefilter; then
        exit 0
    fi

    local lib="${BASH_SOURCE[0]%/*}" project clone winhome wscript boot rc=0 out=""
    # The clone is wherever THESE scripts live; CLAUDE_PROJECT_DIR is only the
    # fallback, because a path derived from the file cannot disagree with it.
    project="${lib%/.claude/hooks/lib}"
    [ "$project" != "$lib" ] || project="${CLAUDE_PROJECT_DIR:-}"
    hook_to_wsl_path "$project"
    clone="$HOOK_WSL_PATH"
    hook_to_wsl_path "${USERPROFILE:-${HOME:-}}"
    winhome="$HOOK_WSL_PATH"
    hook_to_wsl_path "$script"
    wscript="$HOOK_WSL_PATH"
    hook_to_wsl_path "$lib/wsl-boot.sh"
    boot="$HOOK_WSL_PATH"

    # Claude Code kills a hook at its own timeout and treats that as NO decision
    # (the command runs). A guard must answer first, so a hook that sets
    # HOOK_WSL_TIMEOUT gets wsl.exe bounded below Claude Code's limit; rc 124 is
    # then a forward failure the hook can turn into a deny.
    local bound=()
    if [ -n "${HOOK_WSL_TIMEOUT:-}" ] && command -v timeout >/dev/null 2>&1; then
        bound=(timeout -k 2 "$HOOK_WSL_TIMEOUT")
    fi

    if ! command -v wsl.exe >/dev/null 2>&1; then
        rc=127
    else
        # MSYS rewrites any argument that looks like a POSIX path into a
        # Windows one before spawning a native .exe -- /mnt/c/x would arrive as
        # C:/Program Files/Git/mnt/c/x. Both switches are needed: the first is
        # Git for Windows', the second MSYS2's.
        out="$(
            export MSYS_NO_PATHCONV=1 MSYS2_ARG_CONV_EXCL='*'
            # An env var does not cross wsl.exe unless WSLENV lists it (measured:
            # without it the forwarded hook sees REGISTRO_TESTS empty). The quality
            # gate reads it to force the full/fast test scope; an unset var arrives
            # as empty, which the gate treats as "no override".
            case ":${WSLENV:-}:" in
                *:REGISTRO_TESTS:* | *:REGISTRO_TESTS/*) ;;
                *) export WSLENV="${WSLENV:+$WSLENV:}REGISTRO_TESTS" ;;
            esac
            set +o pipefail # the hook may exit before reading all of stdin (SIGPIPE != failure)
            printf '%s' "$HOOK_INPUT" | ${bound[@]+"${bound[@]}"} wsl.exe -d "$REGISTRO_WSL_DISTRO" -e bash "$boot" \
                "$REGISTRO_WSL_TREE" "$clone" "$winhome" "$wscript" "$@"
        )" || rc=$?
    fi

    # What counts as "the hook answered":
    #  * rc 2 -- the hook's own blocking status, always passed through;
    #  * rc 0 or 1 -- the hook's own statuses, for hooks that may exit quietly;
    #  * a hook that sets HOOK_EXPECT_OUTPUT=1 (a guard) must instead return rc 0
    #    WITH a JSON answer. rc 1 is what `set -e`/`set -u` produce when the hook
    #    dies halfway, and Claude Code treats exit 1 as a NON-blocking error: the
    #    command would run unguarded. For a guard that is a forward failure.
    # Anything else is wsl.exe or the boot script failing (97/98 = boot refused;
    # 124 = timed out; 127 = no wsl.exe; 255 = wsl.exe).
    case "$rc" in
        2) [ -z "$out" ] || printf '%s\n' "$out"; exit 2 ;;
        0 | 1)
            if [ "${HOOK_EXPECT_OUTPUT:-0}" != 1 ]; then
                [ -z "$out" ] || printf '%s\n' "$out"
                exit "$rc"
            fi
            if [ "$rc" -eq 0 ] && [[ "$out" == \{* ]]; then
                printf '%s\n' "$out"
                exit 0
            fi
            rc=99 # died (rc 1) or said nothing / not JSON (rc 0): the guard never really answered
            ;;
    esac
    if declare -F hook_on_forward_failure >/dev/null 2>&1; then
        hook_on_forward_failure "$rc" "$script"
    fi
    hook_on_forward_failure_default "$rc" "$script"
}

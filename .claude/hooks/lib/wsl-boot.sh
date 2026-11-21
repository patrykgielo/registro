#!/bin/bash
###############################################################################
# Runs INSIDE WSL, started by lib/wsl-forward.sh. Not a hook.
#
#   wsl-boot.sh TREE CLONE WINHOME SCRIPT [ARGS...]
#
# It is a file rather than a `bash -c '...'` string on purpose: wsl.exe
# re-parses its command line through Windows quoting, and a multi-line script
# with nested quotes does not survive that round trip. Plain path arguments do.
#
# Exit 97/98 are this script's own refusals; anything else is the hook's.
###############################################################################
tree="$1" clone="$2" winhome="$3" script="$4"
shift 4

if [ ! -d "$tree" ]; then
    echo "registro hooks: REGISTRO_WSL_TREE '$tree' does not exist in this WSL distro" >&2
    exit 97
fi
if [ ! -f "$script" ]; then
    echo "registro hooks: hook script '$script' is not visible from WSL (is the Windows drive mounted?)" >&2
    exit 98
fi
cd "$tree" || exit 97

# The forwarded hook must not forward again.
unset REGISTRO_HOOK_ASSUME_WINDOWS
export REGISTRO_HOOK_ORIGIN=windows
# The hook now works on the WSL tree, so that is its project directory. The
# Windows clone (what Claude Code actually loads rules from) is passed beside it.
export CLAUDE_PROJECT_DIR="$tree" REGISTRO_WSL_TREE="$tree"
[ -z "$clone" ] || export REGISTRO_WIN_CLONE="$clone"
[ -z "$winhome" ] || export REGISTRO_WIN_HOME="$winhome"

exec bash "$script" "$@"

---
name: project-hooks-wsl-forwarding-2026-11-21
description: Claude Code hooks on the Windows desktop app forward into WSL via lib/wsl-forward.sh; traps found while building it and how to verify hooks for real
metadata:
  type: project
---

Hooks were wired nowhere (gitignored settings.local.json absent) and would have misbehaved if wired. Fixed on branch `feature/hooks-wsl-forwarding` (worktree `app-hooks`): committed `.claude/settings.json`, shim `.claude/hooks/lib/wsl-forward.sh`, python3 instead of jq, target-tree resolver `lib/pretool_target.py`, `session-sync.sh`, cc-doctor extensions, shell tests 38-44.

**Why:** the user runs Claude Code from the Windows app (hooks run in Git Bash, cwd = read-only mirror clone `C:\projects\registro`), real work is in WSL worktrees.

**How to apply / traps that cost time:**
- Verify hooks with a REAL Claude Code, not just piped JSON: `"$CLAUDE_CODE_EXECPATH" -p "<prompt>" --output-format stream-json --verbose < /dev/null` inside a scratch project with a copy of settings.json shows `hook_started/hook_response` events and the PreToolUse deny in the tool result. The auto-mode classifier BLOCKS a nested run with `--permission-mode bypassPermissions` and `docker run koalaman/shellcheck` — do not retry/work around; shellcheck stays unrun unless the user installs it.
- Edit/Write on a `.sh` file resets its exec bit (git shows mode 100755 => 100644). `chmod +x` hooks and scripts/cc-doctor.sh as the LAST step; cc-doctor and test 44 catch it.
- Git Bash under Claude Code collapses `\\` in command text (JSON test files came out with a single backslash -> invalid JSON). Generate JSON with node/python, not printf.
- `/tmp` in Git Bash is %TEMP%; `pwd` there returns `/tmp/...`, not `/c/...` — the shim uses `cygpath -m` as fallback.
- Stop hook blocks only on exit 2 (stderr); the old stop-gate used exit 1 + stdout and never blocked. JSON from a hook is read only on exit 0.
- Each `$(...)` under Git Bash is a real fork (~15-30 ms); keep the hot path (PreToolUse) free of them. Measured: ~130 ms prefiltered, ~450 ms forwarded.
- TIER 1 was already over budget (12136 bytes vs 12000) before this work; not fixed here.

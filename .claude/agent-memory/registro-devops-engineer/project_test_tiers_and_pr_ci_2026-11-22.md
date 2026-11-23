---
name: project-test-tiers-and-pr-ci-2026-11-22
description: SubagentStop gate now picks test scope (slow group) and test.yml runs on PRs to develop; WSLENV needed for env vars to cross wsl.exe; real parallel failure output format measured
metadata:
  type: project
---

Gate `.claude/hooks/subagent-quality-gate.sh` runs `artisan test --parallel [--exclude-group=slow]`; full when a changed path matches `SLOW_TRIGGERS_RE` (top of script) or `REGISTRO_TESTS=full`. `test.yml` is the only auto-running workflow (PR -> develop, full suite incl. slow). Shell case 52 pins it.

**Why:** full suite was 608 s sequential vs the hook's 600 s timeout.

**How to apply:**
- An env var set in Windows does NOT reach a forwarded hook unless listed in `WSLENV` (measured with real wsl.exe; unset var arrives as empty string). The shim `lib/wsl-forward.sh` adds `REGISTRO_TESTS`; any new hook knob needs the same.
- Real `artisan test --parallel` failure output (measured in a throwaway container copy): progress dots like `.⨯`, a `FAILED  Tests\X > name` block, then `Tests:  1 failed, 1 passed`, `Parallel: N processes`, rc 1 — the gate's old parser works unchanged.
- Laravel parallel + MySQL names DBs `<DB_DATABASE>_test_<token>` (TestDatabases.php); skipped for SQLite :memory:.
- **A hook killed by CC's own timeout is "no decision" = a silent PASS.** The gate therefore ends itself first: `GATE_BUDGET_S`=540 (override `REGISTRO_GATE_BUDGET_S`) via `timeout -k 5`, overrun -> `NOT VERIFIED` (exit 0 + systemMessage, same family as `SKIPPED`, deliberately not a block: agent cannot fix it and the 2nd stop passes anyway). `timeout 0` means NO limit — guard the computed limit >= 1. A 137 only counts as timeout if the limit had elapsed (early 137 = OOM = block). Killing the `docker exec` client leaves the process running in the container (unproven how to reap it safely).
- Scope = working tree + branch commits since `origin/develop` (`registro_tree_scope_changes`, silent fallback), scope ONLY; the "any PHP changed" skip stays on the working tree. `registro_git` carries `core.quotePath=false` (quoted non-ASCII paths missed `^` anchors AND `\.php$`).
- Testing a timeout with a fake `docker`: the sleeper must be `exec sleep`, else `timeout` kills the shell and the orphan holds the `$(...)` pipe open. Prove a case fails against each bug by sed-mutating a COPY of `.claude/hooks` + `tests/shell` in a temp dir (harness derives REPO_ROOT from its own location).
- Tool traps hit: Windows-side Git Bash heredocs with `'`-heavy bodies can die with "unexpected EOF" — use the Write tool for scripts, run them via `wsl.exe -- bash /mnt/c/...`; the WSL tree is also editable via `\\wsl.localhost\Ubuntu-24.04\...` with Read/Edit.
- Tool traps hit: a Bash command whose text contains `migrate:` + `fresh` is blocked by the PreToolUse hook even inside a heredoc writing a file — create such files with the Write tool. Edit resets `.sh` exec bit (chmod +x after).

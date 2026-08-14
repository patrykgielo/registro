---
name: fake-pipe-stdin-drain
description: a tests/shell fake on the READ side of a piped command must drain stdin, but ONLY in the branch matching that specific piped invocation — never in a catch-all, or it hangs forever on any stdin that never EOFs
metadata:
  type: feedback
---

**Corrected 2026-08-14 after review found the first version of this note caused a regression.**
The original entry said "drain stdin" and stopped there — no mention of scoping. A later session
read it, put the drain in a `case ... esac`'s catch-all (the obvious place), and shipped a
deterministic hang. If you are reading this because you are about to add a drain, the scoping
below is not optional detail, it is the whole lesson.

When a real script under test does `writer | reader` (e.g. `tenant-restore.sh`'s
`restic dump ... | docker run -i ... tar -x ...`), and both `writer` and `reader` are faked with
`fake_exe` (separate forked processes joined by a real OS pipe), the fake `reader` must consume its
stdin (`cat >/dev/null` before its own `exit 0`) — **but only in the branch that matches THAT ONE
piped invocation, never in a wildcard/catch-all branch that also matches other, non-piped calls.**
Correct shapes, both in this codebase: case 15's `case "$1" in run) cat >/dev/null; exit 0 ;;`
(narrow on the subcommand) and case 16's `*"tar -x -C /dest"*) cat >/dev/null; exit 0 ;;` (narrow on
a substring unique to that one invocation's actual arguments, with a plain `*) exit 0 ;;` still
below it for everything else).

**Two separate failure modes, on two separate axes — both real, both reproduced, neither implies
the other:**

1. **No drain at all → SIGPIPE flake under CPU load.** If the fake reader exits without reading, it
   closes its end of the pipe. Under light/no load the writer's `echo` (small, fits the pipe buffer)
   always lands before the reader exits, so nothing breaks. Under real CPU contention, scheduling
   jitter can let the reader fork/exec/exit BEFORE the writer even starts — the writer's `echo` then
   hits a closed pipe, dies with SIGPIPE (exit 141), and `set -o pipefail` (used throughout
   `tests/shell/cases/*.sh`) turns that into the pipeline's own exit status. Reproduced: 5/15
   failures under `yes >/dev/null &` on every core with no drain at all, 0/many with a drain.
2. **Drain in a catch-all → deterministic hang on open stdin.** A catch-all drain also runs for
   EVERY other, non-piped docker call in the same fake (`docker compose exec`, `docker volume
   inspect`, etc.) — those inherit the TEST PROCESS's own stdin, not a pipe from a fake writer.
   `tests/shell/run.sh`'s own documented invocation (`out="$(bash "$case_file" 2>&1)"`) does not
   redirect stdin, so it inherits whatever `run.sh` itself has — an interactive terminal, or any
   other source that never reaches EOF, and `cat >/dev/null` in that catch-all blocks forever.
   Reproduced: `timeout 8 bash 16_....sh <fifo` → exit 124 (hang) with the catch-all drain, exit 0
   (real PASS) with the drain scoped to only the piped branch. Permanent regression test:
   `tests/shell/cases/29_tenant_restore_docker_fake_never_hangs_on_open_stdin.sh`.

**Verify BOTH axes for any fix here, neither alone is sufficient:** CPU load
(`for i in $(seq 1 $(nproc)); do (yes >/dev/null &); done`) AND an open, non-EOF stdin
(`mkfifo f; exec 3<>f; bash case.sh <f` — see case 29 for the pattern used inside a test case
itself). A fix proven only under load can still hang on open stdin, and vice versa.

**Related, separate lesson found while fixing the hang above — a backgrounded helper used to hold a
FIFO open leaked a file descriptor and quietly added 30s to the suite:** see
[[test-both-load-and-stdin-axes]] for the full write-up (background jobs inherit the parent's
stdout unless redirected; `$(...)` does not return until every holder of its pipe's write end
closes it; `kill $!` on a backgrounded subshell does not reach children it already forked). Use
`exec N<>fifo` in the test's own process instead of a background job to hold a FIFO open — no
subprocess, no fd-inheritance risk, no orphan risk.

**How to apply:** any NEW `tests/shell/cases/*.sh` that fakes both sides of a real pipeline (grep
the script under test for `restic dump.*|.*docker run` or similar) needs the drain scoped to that
ONE invocation from day one — write the narrow `case` pattern first, never a catch-all, even if it
looks like more typing. If a case is later reported flaky under load, check for a missing/unscoped
drain (failure mode 1). If a case or the whole suite hangs or silently grows slower under a
different invocation shape (interactive, or `run.sh` itself), check for an over-scoped drain
(failure mode 2) before assuming it is a new bug.

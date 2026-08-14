---
name: test-both-load-and-stdin-axes
description: verifying a tests/shell fix under CPU load OR under an open stdin is not enough -- both axes are independent and must be checked together, and a $(...) command substitution hangs on ANY inherited fd, not just the obvious one
metadata:
  type: feedback
---

Two independent lessons from the same review cycle (2026-08-14, `feature/silent-failure-probes`,
see [[project-silent-failure-probes]] and [[fake-pipe-stdin-drain]]):

**1. CPU-load robustness and open-stdin robustness are different axes -- proving one says nothing
about the other.** I fixed a SIGPIPE flake in `tests/shell/cases/15/16/17` by draining a fake
`docker`'s stdin, verified it exhaustively under `yes >/dev/null &` on every core, and shipped it.
Review reproduced a DETERMINISTIC HANG under a completely different condition (an open, non-EOF
stdin -- what a real terminal or `run.sh` run without `</dev/null` looks like) that my load-only
testing never touched, because I'd scoped the drain to a catch-all instead of the one actually-piped
invocation. **How to apply:** for any `tests/shell` fix involving fakes and stdin/pipes, verify BOTH
`for i in $(seq 1 $(nproc)); do (yes >/dev/null &); done` (load) AND `mkfifo f; exec 3<>f; bash
case.sh <f` or `<f` per-case (open stdin, non-EOF) BEFORE calling it done. Neither alone caught what
the other did.

**2. A backgrounded `&` job inherits the parent's stdout/stderr unless explicitly redirected, and
`$(...)` command substitution does not return until EVERY process holding its pipe's write end
closes it.** My first fix for the open-stdin hang itself introduced a NEW, quieter hang: a
`( ...; sleep 30; ... ) &` background job used to hold a FIFO open leaked its parent's stdout fd,
so `out="$(bash case.sh 2>&1)"` (exactly `run.sh`'s own invocation shape) blocked for the full sleep
duration even after "PASS" had already printed. Caught only by TIMING the run, not by checking
pass/fail. Compounding this: `kill $WRITER_PID` on the backgrounded subshell did not kill its forked
`sleep` child once the parent died (reparented, not killed) -- a real, observed orphan via `ps aux`
run immediately after the script exited. **How to apply:** never background a subprocess in a test
case just to hold something open -- use `exec N<>fifo` (the non-blocking read-write FIFO-open idiom)
in the test's OWN process instead. No subprocess, no fd-inheritance risk, no orphan/PID-tracking
risk, and it closes automatically on process exit. If a background job is genuinely unavoidable,
redirect its stdout/stderr explicitly (`>/dev/null 2>&1 &`) and never assume `kill $!` reaches its
own children.

Both fixes verified against the real `run.sh` invocation shape (`out="$(bash "$case_file" 2>&1)"`,
not a direct/interactive invocation) -- the interactive shape I used while first writing the fix
masked both bugs.

---
name: fake-pipe-stdin-drain
description: any tests/shell fake executable on the READ side of a piped command (docker run consuming restic dump's stdout) must drain stdin before exiting, or the pipeline is flaky under CPU load
metadata:
  type: feedback
---

When a real script under test does `writer | reader` (e.g. `tenant-restore.sh`'s
`restic dump ... | docker run -i ... tar -x ...`), and both `writer` and `reader` are faked with
`fake_exe` (separate forked processes joined by a real OS pipe), the fake `reader` MUST consume its
stdin (`cat >/dev/null` before its own `exit 0`) even if it doesn't care about the content.

**Why:** if the fake reader exits without reading, it closes its end of the pipe. Under normal,
lightly-loaded scheduling the writer's `echo` (small, fits the pipe buffer) always lands before the
reader gets scheduled and exits, so nothing breaks. Under real CPU contention, scheduling jitter can
let the reader fork/exec/exit BEFORE the writer even starts — the writer's `echo` then hits a closed
pipe, dies with SIGPIPE (exit 141), and `set -o pipefail` (used throughout `tests/shell/cases/*.sh`)
turns that into the whole pipeline's exit status, failing the test for a reason unrelated to the real
script's correctness. Confirmed by reproducing 5/15 failures under `yes >/dev/null &` on every core
(before the fix) vs 0 failures across ~90 loaded runs (after), 2026-08-14 — see
[[project-silent-failure-probes]] for what this was found while working on. Cost nothing to fix once
found: `cat >/dev/null` before `exit 0` in the reader's fake, no change to the real script.

**How to apply:** any NEW `tests/shell/cases/*.sh` that fakes both sides of a real pipeline (grep the
script under test for `restic dump.*|.*docker run` or similar) needs this from day one, not just when
someone reports flakiness under load. If a case is later reported flaky and only reproduces under
contention, this shape is the first thing to check — stress-test locally with `for i in $(seq 1
$(nproc)); do (yes >/dev/null &); done` before assuming it's a real product race.

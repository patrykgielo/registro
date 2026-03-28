---
name: Stop hook output must go to stderr
description: Claude Stop/SubagentStop hooks capture stderr only — stdout is invisible to the hook reporter
type: feedback
---

The Stop hook framework (and SubagentStop) captures **stderr** for feedback display. Any `echo` without `>&2` goes to stdout and produces "No stderr output" in the hook feedback panel, making the quality gate invisible.

**Why:** Discovered 2026-03-26 when `subagent-quality-gate.sh` was silently passing — all its `echo` calls used stdout.

**How to apply:** In ALL hook scripts (`.claude/hooks/*.sh`), redirect every diagnostic/feedback line to stderr with `>&2`. Exit codes still control block/pass behavior regardless.

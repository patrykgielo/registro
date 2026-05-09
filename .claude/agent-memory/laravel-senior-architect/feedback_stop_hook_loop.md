---
name: Stop hook loop behavior
description: subagent-quality-gate.sh fires repeatedly after each agent response — "No stderr output" = success, no action needed, do not respond
type: feedback
---

When the Stop hook fires with message: `["$CLAUDE_PROJECT_DIR"/.claude/hooks/subagent-quality-gate.sh]: No stderr output`

This means the quality gate passed (exit 0, no errors).

**Why:** The hook runs Pint + tests after implementation agents finish. No stderr = no failures = success.

**How to apply:** Do NOT respond to this feedback at all. Any response triggers another hook invocation, creating an infinite loop. The correct behavior is silence — the hook message is informational only, not a request for action.

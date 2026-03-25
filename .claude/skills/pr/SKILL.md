---
name: pr
description: Push branch and create Pull Request to develop with standardized template. Use after commits are ready.
argument-hint: "[PR title override]"
disable-model-invocation: true
allowed-tools: Bash, Read, Grep, Glob
effort: low
---

# /pr — Create Pull Request

## Step 1: Verify State

```bash
git branch --show-current
git status
git log --oneline develop..HEAD
```

Must be on `feature/*`, `bugfix/*`, or `hotfix/*` branch. Must have commits ahead of develop.

## Step 2: Push Branch

```bash
git push -u origin $(git branch --show-current)
```

## Step 3: Analyze Changes

Read ALL commits (not just latest). Understand the full scope:
```bash
git diff develop...HEAD --stat
```

## Step 4: Create PR

Title: short, under 70 chars. Use `$ARGUMENTS` if provided.

```bash
gh pr create --base develop --title "..." --body "$(cat <<'EOF'
## Summary
- [1-3 bullet points from commit analysis]

## Test plan
- [ ] Pint passes
- [ ] Tests pass (X passed, Y pre-existing failures)
- [ ] [Feature-specific verification steps]
EOF
)"
```

**ALWAYS `--base develop`** (hook blocks other targets for feature branches).

## Step 5: Report

State: **"PR created: [URL]. Base: develop. Commits: [count]."**

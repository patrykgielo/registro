---
name: catchup
description: Show what changed since last session — recent commits, branch state, pending changes. Use at start of new conversation.
disable-model-invocation: true
allowed-tools: Bash, Read, Grep
---

# /catchup — Session Start Briefing

## Step 1: Branch & Status

```bash
git branch --show-current
git status --short
```

## Step 2: Recent Commits

```bash
git log --oneline --since="3 days ago" -20
```

## Step 3: Pending Changes

```bash
git diff --stat HEAD
git stash list
```

## Step 4: Open PRs

```bash
gh pr list --state open --limit 5
```

## Step 5: Summary

Report concisely:
- **Branch**: current branch name
- **Status**: clean / pending changes / stashed work
- **Recent**: last N commits (what was done)
- **Open PRs**: any waiting for review/merge
- **Action needed**: what should we work on next?

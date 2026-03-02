# Git Quick Reference - Cheat Sheet

**Quick access** to common Git commands and workflows for Registro.

**Model**: Gitflow with staging-based release approval

---

## Table of Contents

- [Quick Start](#quick-start)
- [Branch Commands](#branch-commands)
- [Commit Commands](#commit-commands)
- [Merge & Rebase](#merge--rebase)
- [Tag Commands](#tag-commands)
- [Undo Commands](#undo-commands)
- [Status & Information](#status--information)
- [Workflows](#workflows)
- [Troubleshooting](#troubleshooting)

---

## Quick Start

### Initial Setup

```bash
# Clone repository
git clone git@github.com:your-username/registro.git
cd registro/app

# Set up Git identity
git config user.name "Your Name"
git config user.email "your.email@example.com"

# Check configuration
git config --list
```

### Daily Workflow

```bash
# 1. Start new feature
git checkout develop
git pull origin develop
git checkout -b feature/my-feature

# 2. Make changes
git add .
git commit -m "feat(scope): description"

# 3. Push to remote
git push -u origin feature/my-feature

# 4. Create PR on GitHub
# feature/my-feature → develop

# 5. After merge → delete local branch
git checkout develop
git branch -d feature/my-feature
```

---

## Branch Commands

### Creating Branches

```bash
# Feature branch from develop
git checkout develop
git pull origin develop
git checkout -b feature/my-feature

# Release branch from develop
git checkout develop
git pull origin develop
git checkout -b release/v0.3.0

# Hotfix branch from main
git checkout main
git pull origin main
git checkout -b hotfix/v0.3.1-patch
```

### Switching Branches

```bash
# Switch to existing branch
git checkout develop
git checkout main
git checkout feature/my-feature

# Switch to remote branch
git checkout -b feature/existing origin/feature/existing
```

### Listing Branches

```bash
# Local branches
git branch

# Remote branches
git branch -r

# All branches (local + remote)
git branch -a

# Show last commit on each branch
git branch -v
```

### Deleting Branches

```bash
# Delete local branch (safe - prevents unmerged delete)
git branch -d feature/my-feature

# Force delete local branch
git branch -D feature/my-feature

# Delete remote branch
git push origin --delete feature/my-feature
```

### Renaming Branches

```bash
# Rename current branch
git branch -m new-name

# Rename another branch
git branch -m old-name new-name

# Update remote after rename
git push origin -u new-name
git push origin --delete old-name
```

---

## Commit Commands

### Basic Commits

```bash
# Stage all changes
git add .

# Stage specific file
git add app/Models/User.php

# Stage specific directory
git add app/Http/Controllers/

# Commit staged changes
git commit -m "feat(scope): description"

# Commit with multi-line message
git commit -m "feat(booking): add multi-service booking

Allow customers to book multiple services in single appointment.

Changes:
- Add service_ids JSON column
- Update booking wizard
- Add validation

Closes #123"

# Stage and commit in one command
git commit -am "fix(auth): resolve session issue"
```

### Commit Message Templates

```bash
# Feature
git commit -m "feat(booking): add appointment cancellation"

# Bug fix
git commit -m "fix(auth): resolve session fixation vulnerability"

# Documentation
git commit -m "docs(readme): update installation instructions"

# Refactoring
git commit -m "refactor(services): extract email logic to service"

# Tests
git commit -m "test(appointment): add integration tests"

# Chores
git commit -m "chore(deps): upgrade Laravel to 12.32.5"

# Performance
git commit -m "perf(queries): optimize booking availability query"

# CI/CD
git commit -m "ci(deploy): add staging deployment workflow"
```

### Amending Commits

```bash
# Amend last commit message
git commit --amend -m "feat(booking): corrected description"

# Add files to last commit (keep message)
git add forgotten-file.php
git commit --amend --no-edit

# Amend last commit (interactive)
git commit --amend
```

---

## Merge & Rebase

### Merging

```bash
# Merge feature into develop (fast-forward if possible)
git checkout develop
git merge feature/my-feature

# Merge with no fast-forward (creates merge commit)
git checkout develop
git merge --no-ff release/v0.3.0

# Squash merge (combines all commits into one)
git checkout develop
git merge --squash feature/my-feature
git commit -m "feat(profile): add customer profile"
```

### Rebasing

```bash
# Rebase feature on develop (update feature with latest develop)
git checkout feature/my-feature
git rebase develop

# Interactive rebase (edit last 3 commits)
git rebase -i HEAD~3

# Continue rebase after resolving conflicts
git rebase --continue

# Abort rebase
git rebase --abort
```

### Handling Merge Conflicts

```bash
# See conflicted files
git status

# Edit conflicted files (remove <<<<, ====, >>>> markers)
# Then stage resolved files
git add resolved-file.php

# Continue merge
git commit

# Or continue rebase
git rebase --continue

# Abort merge
git merge --abort
```

---

## Tag Commands

### Creating Tags

```bash
# Lightweight tag (not recommended for releases)
git tag v0.3.0

# Annotated tag (recommended)
git tag -a v0.3.0 -m "Release v0.3.0 - Customer Profile"

# Tag specific commit
git tag -a v0.3.0 abc1234 -m "Release v0.3.0"

# Pre-release tags
git tag -a v0.3.0-rc1 -m "Release candidate 1"
git tag -a v0.3.0-beta -m "Beta version"
```

### Listing Tags

```bash
# List all tags
git tag

# List tags matching pattern
git tag -l "v0.3.*"

# Show tag details
git show v0.3.0
```

### Pushing Tags

```bash
# Push single tag
git push origin v0.3.0

# Push all tags
git push origin --tags

# Push branch and tags together
git push origin main --tags
```

### Deleting Tags

```bash
# Delete local tag
git tag -d v0.3.0

# Delete remote tag
git push origin --delete v0.3.0

# Delete local and remote tag
git tag -d v0.3.0
git push origin --delete v0.3.0
```

### Using Release Script

```bash
# Automated tagging with version bump
./scripts/release.sh patch  # v0.3.0 → v0.3.1
./scripts/release.sh minor  # v0.3.1 → v0.4.0
./scripts/release.sh major  # v0.4.0 → v1.0.0
```

---

## Undo Commands

### Undo Staged Changes

```bash
# Unstage file (keep changes)
git restore --staged file.php

# Unstage all files (keep changes)
git restore --staged .

# Old syntax (still works)
git reset HEAD file.php
```

### Undo Local Changes

```bash
# Discard changes in file
git restore file.php

# Discard all local changes
git restore .

# Old syntax (still works)
git checkout -- file.php
```

### Undo Commits

```bash
# Undo last commit, keep changes staged
git reset --soft HEAD~1

# Undo last commit, keep changes unstaged
git reset HEAD~1

# Undo last commit, discard changes (DANGER!)
git reset --hard HEAD~1

# Undo last 3 commits
git reset --soft HEAD~3

# Revert specific commit (creates new commit)
git revert abc1234

# Revert merge commit
git revert -m 1 abc1234
```

### Undo Pushed Commits

```bash
# Revert (safe - creates new commit)
git revert abc1234
git push origin feature/my-feature

# Force push (DANGER - rewrites history)
git reset --hard HEAD~1
git push -f origin feature/my-feature

# NEVER force push to main or develop!
```

---

## Status & Information

### Repository Status

```bash
# Show working tree status
git status

# Short status
git status -s

# Show ignored files
git status --ignored
```

### Viewing History

```bash
# Show commit history
git log

# Show last 10 commits
git log -10

# Show commits in one line
git log --oneline

# Show commits with diff
git log -p

# Show commits for specific file
git log -- app/Models/User.php

# Show commits by author
git log --author="John Doe"

# Show commits between dates
git log --since="2 weeks ago" --until="yesterday"

# Show graph
git log --graph --oneline --all
```

### Viewing Changes

```bash
# Show unstaged changes
git diff

# Show staged changes
git diff --staged

# Show changes in specific file
git diff app/Models/User.php

# Show changes between branches
git diff develop..feature/my-feature

# Show changes between commits
git diff abc1234..def5678
```

### Show Commit Details

```bash
# Show specific commit
git show abc1234

# Show file from specific commit
git show abc1234:app/Models/User.php

# Show latest commit
git show HEAD

# Show commit before latest
git show HEAD~1
```

### Finding Changes

```bash
# Search for text in files
git grep "SearchTerm"

# Search in specific branch
git grep "SearchTerm" develop

# Show which commit introduced change
git blame app/Models/User.php

# Find commit that introduced bug (binary search)
git bisect start
git bisect bad
git bisect good abc1234
```

---

## Workflows

### Feature Development

```bash
# 1. Create feature branch
git checkout develop
git pull origin develop
git checkout -b feature/customer-profile

# 2. Develop (multiple commits)
git add .
git commit -m "feat(profile): add profile page"
git add .
git commit -m "feat(profile): add validation"

# 3. Keep feature updated with develop
git fetch origin
git rebase origin/develop

# 4. Push to remote
git push -u origin feature/customer-profile

# 5. Create PR on GitHub
# feature/customer-profile → develop

# 6. After merge, delete local branch
git checkout develop
git pull origin develop
git branch -d feature/customer-profile
```

### Release Preparation

```bash
# 1. Create release branch (after staging approval)
git checkout develop
git pull origin develop
git checkout -b release/v0.3.0

# 2. Update CHANGELOG.md
# Edit CHANGELOG.md

# 3. Commit changes
git add CHANGELOG.md
git commit -m "chore(release): prepare v0.3.0"

# 4. Push release branch
git push -u origin release/v0.3.0

# 5. Merge to main
git checkout main
git pull origin main
git merge --no-ff release/v0.3.0

# 6. Tag release
git tag -a v0.3.0 -m "Release v0.3.0 - Customer Profile"
git push origin main v0.3.0

# 7. Merge back to develop
git checkout develop
git merge --no-ff release/v0.3.0
git push origin develop

# 8. Delete release branch
git branch -d release/v0.3.0
git push origin --delete release/v0.3.0
```

### Hotfix Workflow

```bash
# 1. Create hotfix branch
git checkout main
git pull origin main
git checkout -b hotfix/v0.3.1-security

# 2. Fix bug
git add .
git commit -m "fix(auth): patch SQL injection"

# 3. Merge to main
git checkout main
git merge --no-ff hotfix/v0.3.1-security

# 4. Tag hotfix
git tag -a v0.3.1 -m "Hotfix v0.3.1 - Security patch"
git push origin main v0.3.1

# 5. Merge to develop
git checkout develop
git merge --no-ff hotfix/v0.3.1-security
git push origin develop

# 6. Delete hotfix branch
git branch -d hotfix/v0.3.1-security
git push origin --delete hotfix/v0.3.1-security
```

---

## Troubleshooting

### Recover Deleted Commits

```bash
# Show all recent actions (including deleted commits)
git reflog

# Restore deleted commit
git checkout abc1234

# Restore deleted branch
git checkout -b recovered-branch abc1234
```

### Fix Wrong Branch

```bash
# Created feature from main instead of develop
git checkout feature/my-feature
git rebase --onto develop main feature/my-feature
git push -f origin feature/my-feature
```

### Sync Fork with Upstream

```bash
# Add upstream remote (one time)
git remote add upstream git@github.com:original/registro.git

# Fetch upstream changes
git fetch upstream

# Merge upstream into local main
git checkout main
git merge upstream/main

# Push to your fork
git push origin main
```

### Clean Up Repository

```bash
# Remove untracked files (dry run)
git clean -n

# Remove untracked files
git clean -f

# Remove untracked files and directories
git clean -fd

# Remove ignored files
git clean -X
```

### Stash Changes

```bash
# Stash current changes
git stash

# Stash with message
git stash save "WIP: customer profile"

# List stashes
git stash list

# Apply latest stash
git stash apply

# Apply and remove latest stash
git stash pop

# Apply specific stash
git stash apply stash@{2}

# Delete stash
git stash drop stash@{0}

# Clear all stashes
git stash clear
```

### Fix Detached HEAD

```bash
# If you're in detached HEAD state
git checkout -b new-branch-name
git push -u origin new-branch-name

# Or go back to previous branch
git checkout -
```

### Update Remote URL

```bash
# Show current remote
git remote -v

# Change remote URL (HTTPS → SSH)
git remote set-url origin git@github.com:username/registro.git

# Verify
git remote -v
```

---

## Aliases (Time Savers)

Add to `~/.gitconfig`:

```ini
[alias]
    # Status
    st = status
    s = status -s

    # Checkout
    co = checkout
    cob = checkout -b

    # Branch
    br = branch
    brd = branch -d
    bra = branch -a

    # Commit
    cm = commit -m
    cam = commit -am
    amend = commit --amend

    # Log
    lg = log --oneline --graph --all
    last = log -1 HEAD

    # Diff
    d = diff
    ds = diff --staged

    # Push/Pull
    pu = push
    pl = pull

    # Undo
    undo = reset --soft HEAD~1
    unstage = restore --staged

    # Stash
    save = stash save
    pop = stash pop
```

Usage:
```bash
git st           # Instead of git status
git cob feature  # Instead of git checkout -b feature
git lg           # Instead of git log --oneline --graph --all
```

---

## Resources

- [Git Workflow Guide](../deployment/GIT_WORKFLOW.md) - Detailed workflow
- [Branching Strategy](../deployment/BRANCHING_STRATEGY.md) - Visual diagrams
- [CONTRIBUTING.md](../../CONTRIBUTING.md) - Contributor guidelines
- [Official Git Documentation](https://git-scm.com/doc)

---

## Emergency Contacts

- **Git Issues**: #tech-support Slack channel
- **Workflow Questions**: See [CONTRIBUTING.md](../../CONTRIBUTING.md)
- **Deployment Issues**: See [CI/CD Runbook](../deployment/runbooks/ci-cd-deployment.md)

---

**Last Updated**: 2025-12-01
**Maintained By**: Registro Development Team

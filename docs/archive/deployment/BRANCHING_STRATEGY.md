# Branching Strategy - Visual Guide

**Version**: 1.0.0
**Last Updated**: 2025-12-01
**Model**: Gitflow with Staging-Based Release Approval

---

## Table of Contents

1. [Overview](#overview)
2. [Branch Hierarchy](#branch-hierarchy)
3. [Branch Types](#branch-types)
4. [Visual Workflows](#visual-workflows)
5. [Common Scenarios](#common-scenarios)
6. [Best Practices](#best-practices)

---

## Overview

This document provides visual representations of Paradocks' Git branching strategy.

### Key Principle

**Quality-First Approach**: Version tags are assigned only after successful staging verification.

```
feature → develop → staging (test) → release → main (tag + deploy)
          ↓                            ↓         ↓
     integration              version bump    production
```

---

## Branch Hierarchy

### Complete Branch Structure

```
┌─────────────────────────────────────────────────────────────┐
│                     PRODUCTION (main)                       │
│  • Always deployable                                        │
│  • Tagged with versions (v0.3.0, v0.3.1, etc.)            │
│  • Protected: Requires PR + review                         │
│  • Deployment triggered by tags                            │
└─────────────────────────────────────────────────────────────┘
                            ↑
                            │ merge from release/*
                            │
┌─────────────────────────────────────────────────────────────┐
│                  RELEASE PREPARATION (release/*)            │
│  • Branch from: develop (after staging approval)           │
│  • Merge to: main + develop                                │
│  • Purpose: Version bumping, changelog, bug fixes          │
│  • NO new features                                         │
│  • Short-lived (deleted after merge)                       │
└─────────────────────────────────────────────────────────────┘
                            ↑
                            │ branch from develop
                            │
┌─────────────────────────────────────────────────────────────┐
│                   INTEGRATION (develop)                     │
│  • Latest development state                                │
│  • All features merge here first                           │
│  • Auto-deploys to staging                                 │
│  • Protected: Requires PR                                  │
└─────────────────────────────────────────────────────────────┘
          ↑                 ↑                 ↑
          │                 │                 │
   ┌──────┴─────┐    ┌─────┴──────┐    ┌────┴─────┐
   │  feature/  │    │  feature/  │    │ feature/ │
   │  booking   │    │  profile   │    │   cms    │
   └────────────┘    └────────────┘    └──────────┘
```

### Hotfix Branch (Emergency Path)

```
┌─────────────────────────────────────────────────────────────┐
│                     PRODUCTION (main)                       │
│  v0.3.0 ← Critical bug discovered!                         │
└─────────────────────────────────────────────────────────────┘
          ↓ branch for hotfix          ↑ merge back
          │                             │
┌─────────────────────────────────────────────────────────────┐
│               HOTFIX (hotfix/v0.3.1-security)              │
│  • Branch from: main                                       │
│  • Merge to: main + develop                                │
│  • Purpose: Critical production fixes                      │
│  • Timeline: < 24 hours                                    │
└─────────────────────────────────────────────────────────────┘
```

---

## Branch Types

### Primary Branches (Long-Lived)

#### `main` - Production Branch

```
main
  |
  *--- v0.3.1 (tag - latest production)
  |
  *--- v0.3.0 (tag - previous production)
  |
  *--- v0.2.11 (tag - old production)
  |
  *--- v0.2.10
  |
  ...
```

**Characteristics**:
- ✅ Always deployable
- ✅ Tagged with semantic versions
- ✅ Protected: Force push disabled
- ✅ Requires PR + code review
- ❌ No direct commits
- ❌ No untagged releases

**Deployment Trigger**:
```yaml
# .github/workflows/deploy-production.yml
on:
  push:
    tags:
      - 'v*.*.*'
```

#### `develop` - Integration Branch

```
develop
  |
  *--- Merge feature/cms
  |
  *--- Merge feature/profile
  |
  *--- Merge feature/booking
  |
  *--- Initial commit
```

**Characteristics**:
- ✅ Latest development code
- ✅ Auto-deploys to staging
- ✅ Protected: Requires PR
- ✅ All features merge here first
- ❌ No direct commits
- ❌ May be ahead of main (expected)

**Auto-Deploy**:
```yaml
# .github/workflows/deploy-staging.yml
on:
  push:
    branches:
      - develop
```

#### `staging` - Staging Environment

```
staging (mirrors develop)
  |
  *--- Auto-synced from develop
  |
  *--- Auto-synced from develop
  |
  *--- Auto-synced from develop
```

**Characteristics**:
- ✅ QA testing environment
- ✅ Auto-updated from develop
- ❌ No direct commits
- ❌ No manual merges
- ⚙️ Managed by CI/CD

### Supporting Branches (Short-Lived)

#### `feature/*` - Feature Development

```
develop
  |
  |\
  | \--- feature/customer-profile
  |       |
  |       *--- test: add profile tests
  |       |
  |       *--- feat: add validation
  |       |
  |       *--- feat: add profile page
  |       |
  |       |--- (branched from develop)
  |
  *--- (other commits)
```

**Naming Convention**:
- ✅ `feature/customer-profile`
- ✅ `feature/booking-cancellation`
- ✅ `feature/email-templates`
- ❌ `feature/john-work` (not descriptive)
- ❌ `customer-profile` (missing prefix)

**Lifecycle**:
1. Branch from `develop`
2. Develop feature (multiple commits)
3. Push to remote
4. Create PR → `develop`
5. Code review
6. Merge (squash or merge commit)
7. Auto-delete ✅

#### `release/*` - Release Preparation

```
develop
  |
  *--- Merge release/v0.3.0 back
  |
  |\
  | \--- release/v0.3.0
  |       |
  |       *--- chore: prepare v0.3.0
  |       |
  |       *--- docs: update CHANGELOG
  |       |
  |       |--- (branched after staging approval)
  |
  *--- (commits ready for release)
```

**Naming Convention**:
- ✅ `release/v0.3.0` (semantic version)
- ✅ `release/v1.0.0` (major release)
- ❌ `release/customer-profile` (feature name)
- ❌ `release/0.3.0` (missing 'v' prefix)

**Purpose**:
- Version bumping
- CHANGELOG updates
- Documentation fixes
- Last-minute bug fixes only
- ❌ NO new features

**Lifecycle**:
1. Branch from `develop` (after staging approval)
2. Update versions, CHANGELOG
3. Push to remote
4. Merge to `main` (tag created)
5. Merge back to `develop`
6. Delete branch ✅

#### `hotfix/*` - Emergency Production Fixes

```
main (v0.3.0)
  |
  *--- v0.3.1 (hotfix tag)
  |
  |\
  | \--- hotfix/v0.3.1-security-patch
  |       |
  |       *--- fix: patch SQL injection
  |       |
  |       *--- test: add security test
  |       |
  |       |--- (branched from main v0.3.0)
  |
  *--- v0.3.0 (production tag)
```

**Naming Convention**:
- ✅ `hotfix/v0.3.1-security-patch` (version + description)
- ✅ `hotfix/v0.3.2-booking-fix` (clear purpose)
- ❌ `hotfix/urgent-fix` (no version)
- ❌ `fix/bug` (wrong prefix)

**Purpose**:
- Critical production bugs
- Security vulnerabilities
- Data integrity issues
- ❌ NOT for minor UI bugs

**Lifecycle**:
1. Branch from `main`
2. Fix critical bug
3. Test thoroughly
4. Merge to `main` (create patch tag)
5. Merge to `develop`
6. Delete branch ✅

---

## Visual Workflows

### Complete Feature Development Cycle

```
┌────────────────────────────────────────────────────────────────┐
│ STEP 1: Feature Development                                   │
└────────────────────────────────────────────────────────────────┘

    develop                    feature/customer-profile
       |                              |
       |                              *--- test: add tests
       |                              |
       |                              *--- feat: add validation
       |                              |
       |                              *--- feat: add profile page
       |                              |
       *------------------------------ (branch created)


┌────────────────────────────────────────────────────────────────┐
│ STEP 2: Code Review & Merge                                   │
└────────────────────────────────────────────────────────────────┘

    develop                    feature/customer-profile
       |                              |
       *<-----------------------------* (PR approved, squash merge)
       |
       *--- feat(profile): add customer profile (merged)
       |
       |                              X (branch deleted)


┌────────────────────────────────────────────────────────────────┐
│ STEP 3: Staging Deployment (Auto)                             │
└────────────────────────────────────────────────────────────────┘

    develop                    staging
       |                         |
       |------------------------>* (auto-deploy via CI/CD)
       |                         |
       |                         | QA Testing
       |                         | ✅ Feature works
       |                         | ✅ No regressions
       |                         | ✅ Ready for production


┌────────────────────────────────────────────────────────────────┐
│ STEP 4: Release Creation (After Staging Approval)             │
└────────────────────────────────────────────────────────────────┘

    develop                    release/v0.3.0
       |                              |
       *------------------------------> (branch created)
       |                              |
       |                              *--- chore: prepare v0.3.0
       |                              |
       |                              *--- docs: update CHANGELOG


┌────────────────────────────────────────────────────────────────┐
│ STEP 5: Production Deployment                                 │
└────────────────────────────────────────────────────────────────┘

    main                       release/v0.3.0
      |                              |
      *<-----------------------------* (merge to main)
      |
      *--- v0.3.0 (tag created - triggers deployment)
      |
      |                              X (branch will be deleted)


┌────────────────────────────────────────────────────────────────┐
│ STEP 6: Sync Develop                                          │
└────────────────────────────────────────────────────────────────┘

    develop                    main (v0.3.0)
       |                         |
       *<------------------------* (merge release back)
       |
       *--- Merge release/v0.3.0 back to develop
       |
       | (develop now synced with production)
```

### Hotfix Workflow (Emergency)

```
┌────────────────────────────────────────────────────────────────┐
│ EMERGENCY: Critical Bug in Production                         │
└────────────────────────────────────────────────────────────────┘

    main (v0.3.0)              hotfix/v0.3.1-security
      |                              |
      | 🔴 Bug discovered!           |
      |                              |
      *------------------------------> (branch from main)
      |                              |
      |                              *--- fix: patch vulnerability
      |                              |
      |                              *--- test: add security test
      |                              |


┌────────────────────────────────────────────────────────────────┐
│ Deploy Hotfix to Production                                   │
└────────────────────────────────────────────────────────────────┘

    main                       hotfix/v0.3.1-security
      |                              |
      *<-----------------------------* (merge to main)
      |
      *--- v0.3.1 (hotfix tag - triggers deployment)
      |


┌────────────────────────────────────────────────────────────────┐
│ Sync Hotfix to Develop                                        │
└────────────────────────────────────────────────────────────────┘

    develop                    main (v0.3.1)
       |                         |
       *<------------------------* (merge hotfix)
       |
       *--- Merge hotfix/v0.3.1 to develop
       |
       |                       hotfix/v0.3.1-security
       |                              |
       |                              X (branch deleted)
```

### Multiple Features in Parallel

```
Time: Day 1-5

    develop
       |
       |\-------------------------------- feature/booking
       | |
       | \----------------------------- feature/profile
       |  |
       |  \-------------------------- feature/cms
       |   |
       |   *--- feat: add pages (cms)
       |   |
       *---*--- feat: add profile validation (profile)
       |   |
       |   *--- feat: add booking wizard (booking)
       |   |
       *---*--- feat: add posts (cms)
       |   |
       |   *--- feat: add profile page (profile)
       |   |
       |   *--- feat: add service selection (booking)
       |   |
       |   ...


Time: Day 6 (First merge)

    develop
       |
       *<------------------------------ feature/profile (merged)
       |                                    X (deleted)
       |\
       | \----------------------------- feature/booking (still open)
       |  |
       |  \-------------------------- feature/cms (still open)
       |   |
       |   *--- (continued development)


Time: Day 8 (All merged)

    develop
       |
       *<------------------------------ feature/cms (merged)
       |                                    X (deleted)
       *<------------------------------ feature/booking (merged)
       |                                    X (deleted)
       *--- feat(cms): CMS system complete
       |
       *--- feat(booking): Booking wizard complete
       |
       *--- feat(profile): Customer profile complete
       |
       | ✅ Ready for staging deployment
```

---

## Common Scenarios

### Scenario 1: Feature Approved on Staging

```
┌─ BEFORE ─────────────────────────────────────────────┐

develop (deployed to staging)
  |
  *--- feat(profile): customer profile
  |
  *--- feat(booking): booking system
  |

staging: ✅ All tests pass


┌─ ACTION ─────────────────────────────────────────────┐

$ git checkout -b release/v0.3.0 develop
$ # Update CHANGELOG, bump versions
$ git push -u origin release/v0.3.0


┌─ AFTER ──────────────────────────────────────────────┐

main
  |
  *--- v0.3.0 (tagged, deployed to production)
  |
  |<--- release/v0.3.0 (merged, deleted)

develop
  |
  *--- Merge release/v0.3.0 back
  |
  *--- feat(profile): customer profile
  |
  *--- feat(booking): booking system
  |
```

### Scenario 2: Bug Found on Staging

```
┌─ PROBLEM ────────────────────────────────────────────┐

develop (deployed to staging)
  |
  *--- feat(profile): customer profile
  |

staging: ❌ Validation bug found!


┌─ ACTION ─────────────────────────────────────────────┐

$ git checkout develop
$ git checkout -b feature/profile-fix-validation
$ # Fix bug
$ git commit -m "fix(profile): correct phone validation"
$ git push -u origin feature/profile-fix-validation
$ # Create PR → develop
$ # After merge → staging auto-deploys


┌─ AFTER ──────────────────────────────────────────────┐

develop (re-deployed to staging)
  |
  *--- fix(profile): correct phone validation
  |
  *--- feat(profile): customer profile
  |

staging: ✅ Bug fixed, re-test complete
```

### Scenario 3: Multiple Releases from Same Develop State

```
┌─ SITUATION ──────────────────────────────────────────┐

develop
  |
  *--- feat(cms): CMS system
  |
  *--- feat(profile): customer profile
  |
  *--- feat(booking): booking system
  |

Decision: Release in 2 stages
- Stage 1: booking + profile (v0.3.0)
- Stage 2: cms (v0.4.0)


┌─ FIRST RELEASE ──────────────────────────────────────┐

$ git checkout -b release/v0.3.0 develop
$ # Cherry-pick or selective commits
$ # Or tag all features as v0.3.0

main
  |
  *--- v0.3.0 (booking + profile)


┌─ CONTINUE DEVELOPMENT ───────────────────────────────┐

develop
  |
  *--- feat(cms): additional CMS work
  |
  *--- Merge release/v0.3.0 back
  |

(Later)

$ git checkout -b release/v0.4.0 develop

main
  |
  *--- v0.4.0 (cms system)
  |
  *--- v0.3.0
  |
```

### Scenario 4: Emergency Hotfix During Feature Development

```
┌─ TIMELINE ───────────────────────────────────────────┐

DAY 1: Feature development in progress

    develop                    feature/new-feature
       |                              |
       |                              *--- WIP commits
       |                              |

    main (v0.3.0)
       |
       | 🔴 Critical bug discovered!


DAY 1 (2 hours later): Hotfix created

    main                       hotfix/v0.3.1-critical
      |                              |
      *------------------------------> (branch)
      |                              |
      |                              *--- fix: critical bug


DAY 1 (4 hours later): Hotfix deployed

    main
      |
      *--- v0.3.1 (hotfix deployed)
      |
      *--- v0.3.0


DAY 2: Sync hotfix to develop + feature

    develop
       |
       *--- Merge hotfix/v0.3.1
       |

    feature/new-feature
       |
       *--- Merge develop (includes hotfix)
       |
       *--- Continue feature development


DAY 5: Feature merged normally

    develop
       |
       *--- feat: new feature complete
       |
       *--- Merge hotfix/v0.3.1
       |
```

---

## Best Practices

### 1. Branch Naming

**Good**:
```
feature/customer-profile
feature/booking-cancellation
feature/email-templates
release/v0.3.0
release/v1.0.0
hotfix/v0.3.1-security-patch
hotfix/v0.3.2-booking-fix
```

**Bad**:
```
feature/john-work (not descriptive)
customer-profile (missing prefix)
release/0.3.0 (missing 'v')
hotfix/fix-bug (no version)
```

### 2. Commit Frequency

**Good Pattern**:
```
feature/my-feature
  |
  *--- test: add integration tests (20 files)
  |
  *--- feat: add validation (5 files)
  |
  *--- feat: add controller (2 files)
  |
  *--- feat: add model (1 file)
```

**Anti-Pattern**:
```
feature/my-feature
  |
  *--- WIP (100 files, unclear what changed)
```

### 3. Branch Lifetime

**Ideal**:
- **feature/***: 1-5 days (merge quickly)
- **release/***: 1-2 days (rapid release preparation)
- **hotfix/***: < 24 hours (emergency only)

**Warning Signs**:
- Feature branch open for > 2 weeks (too large)
- Release branch open for > 1 week (too slow)
- Multiple hotfixes per day (systemic issues)

### 4. Merge Strategies

**Squash and Merge** (recommended for features):
```
Before:
feature/my-feature
  *--- test: add tests
  *--- feat: add validation
  *--- feat: add controller
  *--- feat: add model

After (on develop):
develop
  *--- feat(profile): add customer profile management
```

**Merge Commit** (recommended for releases/hotfixes):
```
develop
  |
  *--- Merge release/v0.3.0 (preserves history)
  |
  |\
  | *--- chore: prepare v0.3.0
  | *--- docs: update CHANGELOG
  |/
  *--- feat: features...
```

### 5. Branch Protection

**Enforce on GitHub**:

```yaml
main:
  - require_pull_request: true
  - required_approvals: 1
  - require_status_checks: true
  - enforce_admins: true
  - restrict_pushes: true

develop:
  - require_pull_request: true
  - require_status_checks: true
  - enforce_admins: false

feature/*:
  - no restrictions (allow force push for rebasing)
```

### 6. Handling Merge Conflicts

**Prefer rebase for feature branches**:
```bash
# Update feature with latest develop
git checkout feature/my-feature
git fetch origin
git rebase origin/develop

# Resolve conflicts
# Continue development
```

**Use merge for releases/hotfixes**:
```bash
# Safer for shared branches
git checkout develop
git merge release/v0.3.0
```

---

## Summary

### Branch Hierarchy (Quick Reference)

```
Production:    main ← release/* ← develop ← feature/*
                 ↑
                 └─ hotfix/* (emergency path)

Environments:  main → production
               develop → staging
```

### Key Rules

1. **Never commit directly** to main or develop
2. **Always use PRs** for code review
3. **Delete branches** after merge
4. **Tag only on main** after staging approval
5. **Hotfix from main**, merge to main + develop
6. **Feature from develop**, merge to develop only
7. **Release after staging**, merge to main + develop

### Resources

- [Git Workflow Guide](GIT_WORKFLOW.md) - Detailed workflow
- [CONTRIBUTING.md](../../CONTRIBUTING.md) - Contributor guidelines
- [CHANGELOG.md](../../CHANGELOG.md) - Version history

---

**Last Updated**: 2025-12-01
**Maintained By**: Paradocks Development Team

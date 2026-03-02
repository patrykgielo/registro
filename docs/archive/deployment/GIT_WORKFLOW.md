# Git Workflow & Branching Strategy

**Version**: 2.0.0
**Last Updated**: 2026-01-14
**Model**: Gitflow with Staging + Production Approval Gate
**Status**: Active

---

## Table of Contents

1. [Overview](#overview)
2. [Branch Structure](#branch-structure)
3. [Complete Workflow](#complete-workflow)
   - [Phase 1: Feature Development](#phase-1-feature-development)
   - [Phase 2: Deploy to Staging](#phase-2-deploy-to-staging-auto)
   - [Phase 3: Create Release Branch](#phase-3-create-release-branch)
   - [Phase 4: Pre-Release Tagging](#phase-4-pre-release-tagging-optional)
   - [Phase 5: Deploy Release to Production](#phase-5-deploy-release-to-production)
   - [Phase 6: Merge Back to Develop](#phase-6-merge-back-to-develop)
4. [Hotfix Workflow](#hotfix-workflow-emergency-production-fix)
5. [Emergency Hotfix Exception](#emergency-hotfix-exception-critical-security-vulnerabilities)
6. [Tagging Strategy](#tagging-strategy-semantic-versioning)
7. [Automated Release Script](#automated-release-script-usage)
8. [Branch Protection Rules](#branch-protection-rules-github-settings)
9. [Commit Message Conventions](#commit-message-conventions)
10. [Troubleshooting](#troubleshooting)
11. [Visual Workflow Diagram](#visual-workflow-diagram)
12. [Current Limitations & Workarounds](#current-limitations--workarounds)

---

## Overview

Paradocks uses **Gitflow** with staging environment and production approval gate.

### Key Principle

**Wersja jest przypisywana dopiero po weryfikacji na staging, nie wcześniej.**

Translation: *Version is assigned only after staging verification, not before.*

This is a **quality-first approach** where we:
1. Deploy features to staging automatically (push to `develop`)
2. Test thoroughly on staging (https://srv1203357.hstgr.cloud)
3. Only create release tags after staging verification
4. Production requires approval gate (5 min wait + reviewer)

### Flow Summary

```
feature/* → develop → STAGING (auto) → release/* → main (tag) → CI → APPROVAL → PRODUCTION
```

### Environments

| Environment | URL | Trigger | Approval |
|-------------|-----|---------|----------|
| Staging | https://srv1203357.hstgr.cloud | Push to `develop` | Auto |
| Production | https://paradocks.pl | Tag `v*.*.*` | 5 min + reviewer |

### Why This Approach?

- ✅ **Quality First**: Staging verification before production
- ✅ **Flexible**: Can combine multiple features in one release
- ✅ **Safe**: Staging + approval gate + quick rollback
- ✅ **Traceable**: Clear version history of what was deployed when
- ✅ **Emergency Response**: Bypass process for critical security fixes (see ADR-017)

---

## Branch Structure

### Primary Branches (Long-Lived)

#### `main` - Production

- **Purpose**: Production-ready code
- **Deployment**: Tagged versions (v0.3.0, v0.3.1, etc.)
- **Protection**: Requires PR + review
- **Lifetime**: Permanent
- **Merge from**: `release/*`, `hotfix/*`
- **Never merge from**: `feature/*`, `develop` (directly)

**Rules**:
- ✅ Always deployable
- ✅ Tagged with semantic versions
- ❌ No direct commits
- ❌ No force pushes

#### `develop` - Integration

- **Purpose**: Latest development state
- **Deployment**: Auto-deploys to staging
- **Protection**: Requires PR
- **Lifetime**: Permanent
- **Merge from**: `feature/*`, `release/*`, `hotfix/*`

**Rules**:
- ✅ Integration testing happens here
- ✅ All features merge here first
- ❌ No direct commits (use feature branches)
- ❌ No force pushes

#### `staging` - Staging Environment

✅ **ACTIVE (Since January 2026)**

- **Purpose**: QA testing environment before production deployment
- **Server**: 45.93.138.193 (Hostinger VPS)
- **Domain**: https://staging.paradocks.pl
- **Deployment**: Auto-updated from `develop` branch via GitHub Actions
- **Protection**: Auto-managed by CI/CD
- **Lifetime**: Permanent
- **Updated by**: GitHub Actions `.github/workflows/deploy-staging.yml`

**Rules**:
- ✅ Mirrors develop branch (auto-sync)
- ✅ All develop changes deploy automatically
- ❌ No direct commits
- ❌ No manual updates (except emergency fixes)

**QA Testing on Staging**:
- Test all features before creating release branch
- Verify migrations work correctly
- Check email notifications (Mailpit UI at :8025)
- Validate SMS test mode
- Confirm frontend builds correctly

**Emergency Staging Fixes**:
If staging environment breaks:
```bash
# SSH to staging server
ssh ubuntu@45.93.138.193

# Manual fix (only if automation fails)
cd /var/www/paradocks
docker compose -f docker-compose.staging.yml restart app
```

### Supporting Branches (Short-Lived)

#### `feature/*` - New Features

- **Branch from**: `develop`
- **Merge to**: `develop`
- **Naming**: `feature/short-descriptive-name`
- **Lifetime**: Until merged (auto-deleted)

**Examples**:
- `feature/customer-profile`
- `feature/booking-system`
- `feature/email-templates`

**Rules**:
- ✅ One feature per branch
- ✅ Regular commits with clear messages
- ✅ Delete after merge

#### `release/*` - Release Preparation

- **Branch from**: `develop` (after staging approval)
- **Merge to**: `main` + `develop`
- **Naming**: `release/vMAJOR.MINOR.PATCH`
- **Lifetime**: Until merged (then deleted)

**Examples**:
- `release/v0.3.0`
- `release/v1.0.0`
- `release/v0.3.1`

**Rules**:
- ✅ Only bug fixes, version bumps, changelog updates
- ❌ No new features
- ✅ Delete after merge

#### `hotfix/*` - Emergency Production Fixes

- **Branch from**: `main`
- **Merge to**: `main` + `develop`
- **Naming**: `hotfix/vMAJOR.MINOR.PATCH-description`
- **Lifetime**: Until merged (then deleted)

**Examples**:
- `hotfix/v0.3.1-security-patch`
- `hotfix/v0.3.2-booking-fix`

**Rules**:
- ✅ Critical production bugs only
- ✅ Quick turnaround (< 24 hours)
- ✅ Delete after merge

---

## Complete Workflow

### Phase 1: Feature Development

#### 1.1 Create Feature Branch

```bash
# Ensure you're on latest develop
git checkout develop
git pull origin develop

# Create feature branch
git checkout -b feature/customer-profile
```

**Naming Convention**:
- ✅ `feature/customer-profile` (descriptive)
- ✅ `feature/booking-cancellation` (clear purpose)
- ❌ `feature/john-work` (not descriptive)
- ❌ `customer-profile` (missing prefix)

#### 1.2 Develop Feature

Make atomic commits with clear messages:

```bash
# First iteration
git add app/Http/Controllers/ProfileController.php
git commit -m "feat(profile): add customer profile controller"

# Add views
git add resources/views/profile/
git commit -m "feat(profile): add profile page views"

# Add validation
git add app/Http/Requests/UpdateProfileRequest.php
git commit -m "feat(profile): add profile validation"

# Add tests
git add tests/Feature/ProfileTest.php
git commit -m "test(profile): add profile update tests"
```

**Best Practices**:
- ✅ Commit often (atomic commits)
- ✅ Clear commit messages
- ✅ Test locally before pushing
- ✅ Run `composer test` and `./vendor/bin/pint`

#### 1.3 Push Feature Branch

```bash
# Push to remote
git push -u origin feature/customer-profile
```

#### 1.4 Create Pull Request

**On GitHub**:
1. Navigate to repository
2. Click "Pull Requests" → "New Pull Request"
3. **Base**: `develop` ← **Compare**: `feature/customer-profile`
4. Fill in PR template:
   - **Title**: "Add customer profile management"
   - **Description**:
     ```markdown
     ## Summary
     Add customer profile management with personal info, vehicle, address, notifications, and security settings.

     ## Changes
     - Add ProfileController with 5 subpages
     - Add Google Maps address autocomplete
     - Add vehicle management CRUD
     - Add notification preferences
     - Add password change and account deletion

     ## Testing
     1. Navigate to /moje-konto
     2. Update personal info
     3. Add vehicle
     4. Test address autocomplete

     ## Screenshots
     [attach screenshots]
     ```
5. Request reviewers
6. Link related issues (if any)

#### 1.5 Code Review

**During review**:
```bash
# Address feedback
git add .
git commit -m "fix(profile): address code review feedback"
git push

# Request re-review after changes
```

**Reviewer checklist**:
- ✅ Code follows Laravel conventions
- ✅ Tests pass (`composer test`)
- ✅ No security vulnerabilities
- ✅ Documentation updated
- ✅ Commit messages follow conventions
- ✅ No hardcoded values (use config/env)

#### 1.6 Merge to Develop

**After approval**:
1. **Merge strategy**: Squash and merge (recommended)
   - Combines all commits into one clean commit
   - Keeps develop history clean
2. **Alternative**: Merge commit (preserves all commits)
3. **Auto-delete**: Feature branch auto-deleted ✅

**Result**: Feature code now on `develop` branch

---

### Phase 2: Deploy to Staging (Auto)

✅ **ACTIVE: Staging server configured and operational (Since January 2026)**

**Current State:**
- Staging environment: https://staging.paradocks.pl
- Server: 45.93.138.193 (Hostinger VPS)
- Auto-deploys when develop branch updated
- GitHub Actions workflow: `.github/workflows/deploy-staging.yml`

**Automatic Deployment Process:**
```bash
# Push to develop triggers automatic deployment
git push origin develop

# GitHub Actions runs:
# 1. PHPUnit tests (must pass)
# 2. Laravel Pint code style check
# 3. SSH to staging server
# 4. Pull latest code
# 5. Rebuild Docker containers
# 6. Run migrations
# 7. Clear caches
# 8. Restart services
```

**Deployment Workflow:**
- Triggered by: Push to `develop` branch
- Workflow file: `.github/workflows/deploy-staging.yml`
- Target: staging.paradocks.pl (45.93.138.193)
- Typical duration: 3-5 minutes
- Monitors: Container health, database connectivity

#### 2.2 Testing on Staging

**Access**: https://staging.paradocks.pl

**QA Checklist**:
- ✅ Feature works correctly
- ✅ No regressions (existing features still work)
- ✅ Performance acceptable (production-like OPcache enabled)
- ✅ Mobile responsive (test on real devices)
- ✅ No console errors (check browser DevTools)
- ✅ Database migrations ran successfully
- ✅ Email notifications working (check Mailpit at :8025)
- ✅ SMS test mode enabled (no real SMS sent)
- ✅ File uploads working (FILESYSTEM_DISK=public)
- ✅ Queue jobs processing (Horizon dashboard)
- ✅ Ready for production

**Staging-Specific Tools**:
- **Mailpit UI**: http://staging.paradocks.pl:8025 (view all test emails)
- **Horizon**: https://staging.paradocks.pl/admin/horizon (queue monitoring)
- **Logs**: `ssh ubuntu@45.93.138.193 "cd /var/www/paradocks && docker compose -f docker-compose.staging.yml logs -f app"`

#### 2.3 Decision Point

**If tests pass** ✅:
- Proceed to Phase 3 (create release)

**If tests fail** ❌:
1. Create new feature branch
2. Fix bugs
3. Merge to develop
4. Re-test on staging
5. Repeat until ready

**Example**:
```bash
# Bug found on staging
git checkout develop
git pull origin develop
git checkout -b feature/profile-fix-validation

# Fix bug
git commit -m "fix(profile): correct phone number validation"
git push -u origin feature/profile-fix-validation

# Create PR → develop
# After merge → staging auto-deploys
# Re-test
```

---

### Phase 3: Create Release Branch

#### 3.1 After Staging Approval

Once staging testing is successful:

```bash
# Ensure develop is up-to-date
git checkout develop
git pull origin develop

# Create release branch (semantic versioning)
git checkout -b release/v0.3.0
```

**Version Numbering**:
- **MAJOR.MINOR.PATCH** (e.g., v0.3.0)
- See [Tagging Strategy](#tagging-strategy-semantic-versioning) for details

#### 3.2 Release Preparation

**Tasks on release branch**:

1. **Update CHANGELOG.md**:
   ```markdown
   ## [0.3.0] - 2025-12-01

   ### Added
   - Customer profile management with 5 subpages
   - Google Maps address autocomplete
   - Vehicle management CRUD

   ### Fixed
   - User model mass assignment vulnerability
   - Session encryption configuration
   ```

2. **Bump version in files** (if applicable):
   ```bash
   # package.json
   "version": "0.3.0"

   # composer.json (optional)
   "version": "0.3.0"

   # VERSION file (if exists)
   echo "0.3.0" > VERSION
   ```

3. **Final testing**:
   ```bash
   composer test
   ./vendor/bin/pint --test
   ```

4. **Commit changes**:
   ```bash
   git add CHANGELOG.md package.json
   git commit -m "chore(release): prepare v0.3.0"
   ```

#### 3.3 Push Release Branch

```bash
git push -u origin release/v0.3.0
```

**Release Branch Purpose**:
- ✅ Freeze features (no new features added)
- ✅ Final QA testing
- ✅ Version bumping
- ✅ Changelog preparation
- ✅ Documentation updates
- ✅ Last-minute bug fixes only

---

### Phase 4: Pre-Release Tagging (Optional)

#### 4.1 Create Pre-Release Tag

```bash
# Optional: Tag release candidate
git checkout release/v0.3.0
git tag -a v0.3.0-rc1 -m "Release candidate 1 for v0.3.0"
git push origin v0.3.0-rc1
```

**Pre-Release Tags** (optional):
- `v0.3.0-rc1` - Release Candidate 1
- `v0.3.0-beta` - Beta version
- `v0.3.0-staging` - Deployed to staging

#### 4.2 Use Cases

**When to use pre-release tags**:
- Tracking which version is on staging
- Multiple release candidates before final release
- Beta testing with external users
- Internal version tracking

**Example**:
```bash
# RC1 has bugs
git tag -a v0.3.0-rc1 -m "RC1"
# Fix bugs
git commit -m "fix(profile): address RC1 feedback"
# RC2 ready
git tag -a v0.3.0-rc2 -m "RC2"
# RC2 approved
git tag -a v0.3.0 -m "Final release"
```

---

### Phase 5: Deploy Release to Production

#### 5.1 Merge Release to Main

```bash
# Checkout main
git checkout main
git pull origin main

# Merge release branch (no fast-forward)
git merge --no-ff release/v0.3.0 -m "Merge release v0.3.0"
```

**Why `--no-ff`?**
- Preserves release branch history
- Creates explicit merge commit
- Makes releases visible in git history

#### 5.2 Create Production Tag

```bash
git tag -a v0.3.0 -m "Release v0.3.0 - Customer Profile Feature

Added:
- Customer profile management with 5 subpages
- Google Maps address autocomplete
- Vehicle management CRUD
- Notification preferences
- Security settings (password change, account deletion)

Changed:
- Improved user model with first_name/last_name fields
- Enhanced address storage with place_id

Fixed:
- User model mass assignment vulnerability
- Session encryption configuration
- Booking wizard mobile responsiveness

See: CHANGELOG.md"
```

**Tag Message Best Practices**:
- ✅ Clear title with version and feature
- ✅ Categorized changes (Added/Changed/Fixed)
- ✅ Reference CHANGELOG.md
- ❌ Don't copy entire CHANGELOG
- ❌ Don't use generic messages

#### 5.3 Push to Production

```bash
# Push main branch
git push origin main

# Push production tag
git push origin v0.3.0
```

**Result**:
- Tag `v0.3.0` created on `main` branch
- GitHub Actions triggered (`.github/workflows/deploy-production.yml`)
- Production deployment started

**CI/CD Workflow** (`.github/workflows/deploy-production.yml`):
```yaml
on:
  push:
    tags:
      - 'v*.*.*'  # Triggered by production tags only
```

---

### Phase 6: Merge Back to Develop

#### 6.1 Sync Develop with Main

```bash
# Checkout develop
git checkout develop
git pull origin develop

# Merge release branch back to develop
git merge --no-ff release/v0.3.0 -m "Merge release v0.3.0 back to develop"

# Push to remote
git push origin develop
```

**Why merge back?**
- Any bug fixes made in `release/*` should return to `develop`
- Ensures `develop` includes all production changes
- Maintains branch consistency

#### 6.2 Delete Release Branch

```bash
# Delete local branch
git branch -d release/v0.3.0

# Delete remote branch
git push origin --delete release/v0.3.0
```

**Auto-Delete**:
- Can be configured in GitHub settings
- Recommended for clean repository

---

## Hotfix Workflow (Emergency Production Fix)

### When to Use Hotfix

**Use hotfix for**:
- ✅ Critical production bugs
- ✅ Security vulnerabilities
- ✅ Data integrity issues
- ✅ Urgent performance problems

**Don't use hotfix for**:
- ❌ Minor UI bugs (wait for next release)
- ❌ New features (use feature/* branch)
- ❌ Non-critical improvements

### Hotfix Steps

#### 1. Create Hotfix Branch

```bash
# Checkout main
git checkout main
git pull origin main

# Create hotfix branch
git checkout -b hotfix/v0.3.1-security-patch
```

#### 2. Fix Critical Bug

```bash
# Make fix
git add app/Http/Controllers/AuthController.php
git commit -m "fix(auth): patch SQL injection vulnerability"

# Add test
git add tests/Feature/AuthSecurityTest.php
git commit -m "test(auth): add SQL injection test"

# Update CHANGELOG
git add CHANGELOG.md
git commit -m "docs(changelog): add v0.3.1 security fix"
```

#### 3. Test Hotfix

```bash
# Run tests
composer test

# Manual testing
# Verify fix works
# Verify no regressions
```

#### 4. Merge to Main

```bash
# Checkout main
git checkout main

# Merge hotfix
git merge --no-ff hotfix/v0.3.1-security-patch

# Tag new version (patch bump)
git tag -a v0.3.1 -m "Hotfix v0.3.1 - Security patch

Fixed:
- SQL injection vulnerability in authentication
- Session fixation issue

Security advisory: CVE-2025-XXXX"

# Push to production
git push origin main
git push origin v0.3.1
```

#### 5. Merge Back to Develop

```bash
# Checkout develop
git checkout develop

# Merge hotfix
git merge --no-ff hotfix/v0.3.1-security-patch

# Push to remote
git push origin develop
```

#### 6. Delete Hotfix Branch

```bash
# Delete local branch
git branch -d hotfix/v0.3.1-security-patch

# Delete remote branch
git push origin --delete hotfix/v0.3.1-security-patch
```

### Hotfix Timeline

**Target**: < 24 hours from discovery to deployment

**Typical Timeline**:
- **0-2 hours**: Discovery, assessment, branch creation
- **2-6 hours**: Fix implementation, testing
- **6-8 hours**: Code review, approval
- **8-12 hours**: Merge to main, tag, deploy
- **12-24 hours**: Monitor, verify fix, merge to develop

---

## Emergency Hotfix Exception (Critical Security Vulnerabilities)

⚠️ **EXCEPTION TO STANDARD PROCESS**

For **CRITICAL/HIGH security vulnerabilities** and **production-breaking bugs**, the standard staging QA cycle (1-3 days) can be bypassed.

### When to Use Emergency Hotfix

**✅ Use Emergency Hotfix For:**

1. **Security Vulnerabilities (OWASP Top 10)**:
   - SQL Injection, XSS, CSRF bypasses
   - Authentication/Authorization failures
   - Content Security Policy violations
   - Session fixation vulnerabilities
   - Sensitive data exposure

2. **Production-Breaking Bugs**:
   - Application crash (500 errors)
   - Critical functionality completely broken
   - Data integrity issues
   - Payment processing failures

3. **Compliance Issues**:
   - GDPR data exposure
   - Missing consent mechanisms
   - Audit log failures

**❌ Do NOT Use Emergency Hotfix For:**
- Minor UI bugs (cosmetic issues)
- Non-critical performance degradation
- Feature enhancements (even if requested urgently)
- Refactoring without immediate security/stability impact
- Documentation updates

### Decision Criteria (All Must Be True)

1. **Severity**: CRITICAL or HIGH security/stability impact
2. **Blast Radius**: Limited change scope (< 100 lines of code)
3. **Rollback Plan**: Previous version available for instant revert
4. **Time Constraint**: Standard staging QA would take > 4 hours
5. **Verification**: Local testing confirms fix works

### Emergency Timeline Target: < 4 Hours

**Phases:**
1. **Discovery & Assessment** (0-15 min): Identify, assess severity, create advisory
2. **Fix Implementation** (15-30 min): Hotfix branch, implement fix, local testing
3. **Deployment** (30-45 min): Merge to main, tag, push to production
4. **Post-Verification** (45-60 min): Production health check, security verification

**Complete Documentation**: [ADR-017: Emergency Hotfix Process](ADR-017-emergency-hotfix-process.md)

**Real-World Example**: v4.7.1 CSP Hotfix (55 minutes from discovery to production)

---

## Tagging Strategy (Semantic Versioning)

### Version Format: `vMAJOR.MINOR.PATCH`

**Examples**:
- `v0.3.0` - Minor version (new features)
- `v0.3.1` - Patch version (bug fixes)
- `v1.0.0` - Major version (breaking changes or production-ready)

### When to Bump Version

#### MAJOR (v1.0.0)

**Increment when**:
- ✅ Breaking changes (incompatible API changes)
- ✅ Complete system rewrite
- ✅ **First production-ready release** (v0.x.x → v1.0.0)
- ✅ Major architectural changes
- ✅ Database schema breaking changes

**Examples**:
- v0.9.5 → v1.0.0 (production launch)
- v1.5.2 → v2.0.0 (API v1 → v2)
- v2.3.1 → v3.0.0 (Laravel 11 → 12 migration)

#### MINOR (v0.3.0)

**Increment when**:
- ✅ New features (backward-compatible)
- ✅ Feature additions
- ✅ Database schema changes (with migrations)
- ✅ New API endpoints
- ✅ Significant improvements

**Examples**:
- v0.2.11 → v0.3.0 (customer profile feature)
- v0.3.5 → v0.4.0 (booking cancellation feature)
- v1.2.3 → v1.3.0 (CMS system)

#### PATCH (v0.3.1)

**Increment when**:
- ✅ Bug fixes (backward-compatible)
- ✅ Security patches
- ✅ Performance improvements (no new features)
- ✅ Dependency updates
- ✅ Documentation fixes

**Examples**:
- v0.3.0 → v0.3.1 (security patch)
- v0.3.1 → v0.3.2 (booking bug fix)
- v1.2.3 → v1.2.4 (performance optimization)

### Pre-Release Tags (Optional)

**Format**: `vMAJOR.MINOR.PATCH-label`

**Labels**:
- `alpha` - Early development
- `beta` - Feature complete, testing
- `rc1`, `rc2` - Release candidates
- `staging` - Deployed to staging

**Examples**:
- `v0.3.0-rc1` - Release candidate 1
- `v0.3.0-beta` - Beta version
- `v0.3.0-staging` - Deployed to staging
- `v1.0.0-alpha` - Alpha version

**Use Case**: Tracking versions on staging before production tag

### Build Metadata (Optional)

**Format**: `vMAJOR.MINOR.PATCH+build`

**Examples**:
- `v0.3.0+20251201` - Build date
- `v0.3.0+build.123` - Build number
- `v0.3.0-rc1+exp.sha.5114f85` - Git hash

---

## Automated Release Script Usage

### Using `./scripts/release.sh`

**Prerequisites**:
- On `main` branch
- All changes committed
- No uncommitted files
- Remote up-to-date

#### Patch Version

```bash
# v0.3.0 → v0.3.1
./scripts/release.sh patch
```

**Use for**: Bug fixes, security patches

#### Minor Version

```bash
# v0.3.1 → v0.4.0
./scripts/release.sh minor
```

**Use for**: New features, feature additions

#### Major Version

```bash
# v0.4.0 → v1.0.0
./scripts/release.sh major
```

**Use for**: Breaking changes, production launch

### What the Script Does

1. **Validates git state**:
   - No uncommitted changes
   - On correct branch (main/master/develop)
   - Remote is reachable

2. **Fetches latest tags**:
   ```bash
   git fetch --tags
   ```

3. **Calculates new version**:
   - Parses current version
   - Bumps according to type (major/minor/patch)
   - Generates new version number

4. **Creates annotated tag**:
   ```bash
   git tag -a vX.Y.Z -m "Release vX.Y.Z"
   ```

5. **Pushes tag to origin**:
   ```bash
   git push origin vX.Y.Z
   ```

6. **Triggers GitHub Actions**:
   - Deployment workflow starts automatically

### Manual Tagging (Alternative)

If you prefer manual control:

```bash
# Create tag
git tag -a v0.3.0 -m "Release v0.3.0 - Customer Profile

Added:
- Customer profile management
- Google Maps integration

See: CHANGELOG.md"

# Push tag
git push origin v0.3.0
```

---

## Branch Protection Rules (GitHub Settings)

### `main` Branch Protection

**Settings** → **Branches** → **Add rule** → Branch name pattern: `main`

**Require**:
- ✅ Pull request before merging
- ✅ At least **1 approval** review
- ✅ Status checks must pass (CI/CD tests)
- ✅ Conversation resolution before merge
- ✅ Linear history (squash or rebase)
- ✅ Up-to-date before merge

**Restrictions**:
- ❌ Force push **disabled**
- ❌ Deletion **disabled**
- ✅ Require signed commits (recommended)
- ✅ Restrict who can push (admins only)

**Status Checks** (must pass):
- `test` - PHPUnit tests
- `lint` - Code formatting (Pint)
- `build` - Asset compilation

### `develop` Branch Protection

**Settings** → **Branches** → **Add rule** → Branch name pattern: `develop`

**Require**:
- ✅ Pull request before merging
- ✅ Status checks must pass
- ⚠️ Review **optional** (for faster iteration)

**Restrictions**:
- ❌ Force push **disabled**
- ❌ Deletion **disabled**

**Status Checks** (must pass):
- `test` - PHPUnit tests
- `lint` - Code formatting (Pint)

### `staging` Branch Strategy

⚠️ **NOT YET CONFIGURED (Planned for v5.0+)**

**Target State - Auto-Deploy Approach**:
- No branch protection needed
- Auto-updated via CI/CD when `develop` changes
- No direct commits allowed (enforced by workflow)

**Alternative Approach** (Manual):
- Same protections as `develop`
- Manual merges from `develop`

**Current State (v4.7.x)**:
- ❌ Branch does not exist
- ❌ No staging server configured
- ✅ See [Current Limitations](#current-limitations--workarounds) for workarounds

---

## Commit Message Conventions

### Format: `type(scope): subject`

#### Types

- `feat:` - New feature
- `fix:` - Bug fix
- `docs:` - Documentation changes
- `style:` - Code style changes (formatting, no logic)
- `refactor:` - Code refactoring (no feature change)
- `test:` - Adding or updating tests
- `chore:` - Build process, dependencies, tooling
- `perf:` - Performance improvements
- `ci:` - CI/CD configuration changes

#### Scope (Optional but Recommended)

**Feature areas**:
- `auth`, `booking`, `email`, `admin`, `cms`, `profile`

**Components**:
- `ui`, `api`, `db`, `docker`, `ci`

#### Subject

- Use imperative mood ("add" not "added")
- Don't capitalize first letter
- No period at the end
- Keep under 50 characters

### Examples

```bash
# Good
feat(booking): add appointment cancellation feature
fix(auth): resolve session fixation vulnerability
docs(readme): update installation instructions
refactor(services): extract email logic to service class
test(appointment): add integration tests for booking flow
chore(deps): upgrade Laravel to 12.32.5
perf(queries): optimize booking availability query
ci(deploy): add staging deployment workflow

# Bad
feat: add stuff (no scope, vague subject)
Fix bug (wrong type format, capitalized)
Updated documentation. (wrong tense, period)
WIP (not descriptive)
```

### Multi-line Commit Messages

For complex changes:

```bash
git commit -m "feat(booking): add multi-service appointment booking

Allow customers to book multiple services in a single appointment.

Changes:
- Add service_ids JSON column to appointments table
- Update booking wizard to support multiple selections
- Add validation for service compatibility
- Update pricing calculation

Breaking Changes:
- appointments.service_id column deprecated (use service_ids)

Closes #123
Refs #124"
```

**Structure**:
1. **Subject line** (50 chars)
2. **Blank line**
3. **Body** (72 chars per line)
   - Detailed explanation
   - What changed and why
   - Breaking changes
4. **Footer** (issue references)

---

## Troubleshooting

### Merge Conflicts

#### Problem: Conflict when merging feature to develop

**Solution**:
```bash
# Update develop locally
git checkout develop
git pull origin develop

# Rebase feature on develop
git checkout feature/my-feature
git rebase develop

# Resolve conflicts
# Edit conflicted files
git add .
git rebase --continue

# Force push (rewrite history)
git push -f origin feature/my-feature

# Then merge PR
```

**Alternative (merge instead of rebase)**:
```bash
git checkout feature/my-feature
git merge develop

# Resolve conflicts
git add .
git commit -m "merge: resolve conflicts with develop"
git push

# Then merge PR
```

### Forgot to Branch from Develop

#### Problem: Created feature branch from main instead of develop

**Solution**:
```bash
# Rebase onto develop
git checkout feature/my-feature
git rebase --onto develop main feature/my-feature

# Force push
git push -f origin feature/my-feature
```

### Need to Undo Last Commit

#### Soft Reset (keeps changes)

```bash
# Undo commit, keep changes staged
git reset --soft HEAD~1

# Make corrections
git add .
git commit -m "feat(profile): correct implementation"
```

#### Hard Reset (discards changes)

```bash
# Undo commit, discard all changes
git reset --hard HEAD~1

# Warning: This deletes your work!
```

#### Undo Multiple Commits

```bash
# Undo last 3 commits
git reset --soft HEAD~3

# Re-commit with corrected message
git add .
git commit -m "feat(profile): implement customer profile system"
```

### Accidentally Pushed to Main

#### Problem: Committed directly to main (shouldn't happen with protection)

**Solution**:
```bash
# Revert the commit
git revert <commit-hash>
git push origin main

# Or if commit is recent and not deployed
git reset --hard HEAD~1
git push -f origin main  # Requires admin access
```

### Wrong Tag Created

#### Problem: Tagged wrong version or branch

**Solution**:
```bash
# Delete local tag
git tag -d v0.3.0

# Delete remote tag
git push origin --delete v0.3.0

# Create correct tag
git tag -a v0.3.0 -m "Correct tag"
git push origin v0.3.0
```

**Warning**: Only delete tags that haven't been deployed!

### Release Branch Has Bugs

#### Problem: Found bugs after creating release branch

**Solution**:
```bash
# Fix on release branch
git checkout release/v0.3.0
git add .
git commit -m "fix(profile): correct validation logic"
git push

# Continue with release process
# Bugs will be merged back to develop in Phase 6
```

### Develop is Ahead of Main After Release

#### Problem: develop has commits not in main

**Solution**:
This is **normal** and **expected**!

```
main:    A---B---C (v0.3.0)
develop: A---B---C---D---E (new features for v0.4.0)
```

**When to worry**: If `main` has commits not in `develop`
**Solution**: Merge `main` to `develop`

```bash
git checkout develop
git merge main
git push origin develop
```

---

## Visual Workflow Diagram

### Complete Feature Development Cycle

```
┌─────────────────────────────────────────────────────────────┐
│                    FEATURE DEVELOPMENT                      │
└─────────────────────────────────────────────────────────────┘

1. Create feature branch from develop:
   git checkout -b feature/customer-profile develop

2. Develop feature (multiple commits):
   git commit -m "feat(profile): add profile page"
   git commit -m "feat(profile): add validation"
   git commit -m "test(profile): add tests"

3. Push and create PR:
   git push -u origin feature/customer-profile
   → Create PR: feature/customer-profile → develop

4. Code review → Approve → Merge (squash)
   → feature/customer-profile auto-deleted ✅

┌─────────────────────────────────────────────────────────────┐
│              ✅ STAGING AUTO-DEPLOY (Active)                │
│          https://srv1203357.hstgr.cloud                     │
└─────────────────────────────────────────────────────────────┘

5. **Staging Deployment**: Auto-deploy on push to develop
   → CI tests pass → Docker image built → Deploy to staging
   → https://srv1203357.hstgr.cloud

6. QA Testing on Staging:
   ✅ Feature works correctly
   ✅ No regressions
   ✅ Performance acceptable
   ✅ Ready for production

   Decision: ✅ APPROVE or ❌ FIX BUGS

┌─────────────────────────────────────────────────────────────┐
│                    RELEASE CREATION                         │
└─────────────────────────────────────────────────────────────┘

7. Create release branch (after staging approval):
   git checkout -b release/v0.3.0 develop

8. Update CHANGELOG.md, bump versions:
   git commit -m "chore(release): prepare v0.3.0"

9. Optional: Pre-release tag
   git tag v0.3.0-rc1

10. Merge to main:
    git checkout main
    git merge --no-ff release/v0.3.0

11. Create production tag (triggers deployment):
    git tag -a v0.3.0 -m "Release v0.3.0 - Customer Profile"
    git push origin main --tags

    → GitHub Actions deploys to production

12. Merge back to develop:
    git checkout develop
    git merge --no-ff release/v0.3.0

13. Delete release branch:
    git branch -d release/v0.3.0
    git push origin --delete release/v0.3.0

┌─────────────────────────────────────────────────────────────┐
│                        RESULT                               │
└─────────────────────────────────────────────────────────────┘

✅ Production: v0.3.0 deployed
✅ Tag created: v0.3.0 on main
✅ CHANGELOG.md updated
✅ Release branch deleted
✅ develop synced with main
```

### Branch Relationship Diagram

```
main (production)
  |
  *--- v0.3.0 (tag)
  |
  |\
  | \--- release/v0.3.0 (merged, deleted)
  |       |
  |       *--- chore(release): prepare v0.3.0
  |       |
  |       |--- (from develop after staging approval)
  |
develop (integration)
  |
  *--- Merge release/v0.3.0 back to develop
  |
  |\
  | \--- feature/customer-profile (merged, deleted)
  |       |
  |       *--- test(profile): add tests
  |       |
  |       *--- feat(profile): add validation
  |       |
  |       *--- feat(profile): add profile page
  |
  *--- feat(booking): previous feature
  |
  *--- Initial commit
```

### Hotfix Workflow Diagram

```
main (production)
  |
  *--- v0.3.1 (hotfix tag)
  |
  |\
  | \--- hotfix/v0.3.1-security-patch (merged, deleted)
  |       |
  |       *--- fix(auth): patch SQL injection
  |       |
  |       |--- (branched from main at v0.3.0)
  |
  *--- v0.3.0
  |
  |
develop (integration)
  |
  *--- Merge hotfix/v0.3.1-security-patch
  |
  *--- (other commits)
```

---

## Summary

### Quick Reference

**Feature Development**:
```bash
git checkout -b feature/my-feature develop
# ... develop ...
git push -u origin feature/my-feature
# Create PR → develop
```

**Release** ~~(after staging approval)~~ **Current: after local testing + CI/CD**:
```bash
git checkout -b release/v0.3.0 develop
# Update CHANGELOG, bump versions
./scripts/release.sh minor
# Merge to main (triggers deployment to production)
```

**Hotfix** (standard process):
```bash
git checkout -b hotfix/v0.3.1-patch main
# ... fix ...
./scripts/release.sh patch
```

**Emergency Hotfix** (critical security vulnerabilities):
```bash
git checkout -b hotfix/v4.7.1-csp-fix main
# ... fix (< 100 lines) ...
# Local testing + automated tests
./scripts/release.sh patch  # < 4 hour target
# See ADR-017 for complete process
```

### Key Principles

1. **Quality First**: Tag only after ~~staging~~ **verification** (local testing + CI/CD)
2. **Clear History**: Use `--no-ff` for merges
3. **Semantic Versioning**: Major.Minor.Patch
4. **Auto-Deploy**: ~~Staging from develop~~, production from tags
5. **Branch Protection**: Require PRs and reviews
6. **Commit Conventions**: `type(scope): subject`
7. **Quick Rollback**: Previous tags available for instant revert (< 5 min)
8. **Emergency Response**: Bypass for critical security fixes (ADR-017)

### Environment Separation (CRITICAL)

⚠️ **CRITICAL: Staging and Production are COMPLETELY SEPARATE environments**

**Never deploy to wrong environment!** Always verify IP address before SSH commands.

#### Staging Environment

**Details:**
- **IP Address:** 45.93.138.193
- **Domain:** srv1203357.hstgr.cloud
- **SSH User:** deploy
- **SSH Key:** `~/.ssh/id_rsa_staging`
- **Purpose:** Testing, QA, client preview
- **Deployment:** Auto on push to `develop` branch
- **Workflow:** `.github/workflows/deploy-staging.yml`

**Configuration:**
- `.env` managed manually on server (NEVER in repo)
- SSL Certificates: `/etc/letsencrypt/live/srv1203357.hstgr.cloud/`
- Different secrets than production (REDIS_PASSWORD, DB_PASSWORD, etc.)
- Mailpit for email testing (no real emails sent)

**Access:**
```bash
# SSH to staging
ssh deploy@45.93.138.193

# Verify you're on staging
hostname  # Should show: srv1203357.hstgr.cloud
```

#### Production Environment

**Details:**
- **IP Address:** 72.60.17.138
- **Domain:** paradocks.pl
- **SSH User:** deploy
- **SSH Key:** `~/.ssh/id_rsa_production`
- **Purpose:** Live application
- **Deployment:** Manual trigger on git tag (main branch)
- **Workflow:** `.github/workflows/deploy-production.yml`

**Configuration:**
- `.env` managed manually on server (NEVER in repo)
- SSL Certificates: `/etc/letsencrypt/live/paradocks.pl/`
- Different secrets than staging (CRITICAL: never copy .env between environments)
- Live email/SMS (real notifications sent)

**Access:**
```bash
# SSH to production
ssh deploy@72.60.17.138

# Verify you're on production
hostname  # Should show: paradocks.pl or similar
```

#### CRITICAL Rules for Environment Management

**NEVER do these:**
❌ Copy `.env` file between staging and production
❌ Use production SSH key for staging (or vice versa)
❌ Deploy to production using staging credentials
❌ Download `.env` from GitHub (doesn't exist, correctly excluded by .gitignore)
❌ Modify `.env` via SSH in workflows (deployments update CODE only)
❌ Copy-paste nginx config without updating SSL certificate paths

**ALWAYS do these:**
✅ Verify IP address before SSH: `echo "Deploying to: 45.93.138.193 (staging)"`
✅ Use correct SSH key for each environment
✅ Validate `.env` before deployment: `./scripts/validate-env.sh production`
✅ Check REDIS_PASSWORD is non-empty: `source .env && test -n "$REDIS_PASSWORD"`
✅ Use environment-specific SSL certificate paths in nginx config
✅ Test deployments on staging first, then production

#### Environment File Management (.env)

**CRITICAL: .env files are NEVER in version control**

**Initial Setup (once per environment):**
```bash
# SSH to server
ssh deploy@45.93.138.193  # or 72.60.17.138 for production

# Create .env from example
cd /var/www/paradocks
cp .env.example .env
nano .env  # Configure all variables manually

# Set permissions
chmod 640 .env
chown www-data:www-data .env
```

**When code adds new variables:**
1. Developer updates `.env.example` in repo (commit to develop)
2. Developer documents in PR: "New variable: FEATURE_X_ENABLED"
3. Admin manually adds to staging `.env` before deployment
4. Admin manually adds to production `.env` before production deployment
5. Deployment proceeds only after confirmation

**Pre-Deployment Validation (MANDATORY):**
```bash
# Validate environment before deployment
ssh deploy@72.60.17.138 "cd /var/www/paradocks && source .env && ./scripts/validate-env.sh production"

# Check critical variable specifically (incident prevention)
ssh deploy@72.60.17.138 "cd /var/www/paradocks && source .env && test -n \"\$REDIS_PASSWORD\" && echo 'OK' || echo 'FAIL'"
```

**See Also:**
- [ADR-019: Environment File Management Policy](ADR-019-env-file-management.md)
- [Deployment Rules](./.claude/rules/deployment.md)

### Current Limitations & Workarounds

⚠️ **Staging Server: ACTIVE (Since January 2026)**

**Current State (v4.8.x):**
- ✅ Staging environment: https://staging.paradocks.pl (45.93.138.193)
- ✅ Auto-deploys from `develop` branch
- ✅ Full QA testing before production releases
- ✅ Quick rollback via git tags (< 5 minutes)
- ⚠️ `.env` managed manually (documented in ADR-019)

**Workarounds in Place:**
1. **Manual .env Management**: Admins sync new variables manually (documented process)
2. **Automated Tests**: CI/CD runs PHPUnit tests before deployment
3. **Emergency Rollback**: Previous tags available for instant revert
4. **Monitoring**: Real-time logs, error tracking, health checks
5. **Pre-Deployment Validation**: `./scripts/validate-env.sh` checks critical variables

**Target State (Planned for v5.0+):**
- Blue-green deployment for zero-downtime
- Automated .env variable sync (with manual approval)
- Enhanced monitoring and alerting

**Recent Incidents:**
- **2026-01-07**: Production .env corruption (REDIS_PASSWORD empty)
  - **Cause:** Workflow attempted to download `.env.production` from GitHub (404)
  - **Fix:** Removed .env download from workflows (ADR-019)
  - **Prevention:** Pre-deployment validation now mandatory

**See Also:**
- [ADR-019: Environment File Management Policy](ADR-019-env-file-management.md)
- [ADR-017: Emergency Hotfix Process](ADR-017-emergency-hotfix-process.md)
- [Deployment Rules](./.claude/rules/deployment.md)

---

### Resources

- [CONTRIBUTING.md](../../CONTRIBUTING.md) - Quick start for contributors
- [CHANGELOG.md](../../CHANGELOG.md) - Version history
- [CLAUDE.md](../../CLAUDE.md) - Project overview
- [ADR-017: Emergency Hotfix Process](ADR-017-emergency-hotfix-process.md) - Critical security fixes
- [CI/CD Runbook](runbooks/ci-cd-deployment.md) - Deployment procedures

---

**Ready to contribute!** 🚀

# Release Process Guide

This document describes the updated CI/CD workflow after fixing the multiple deployment issue.

## Problem Solved

**Before**: Merging to `main` triggered both test workflow AND deployment workflow simultaneously, causing conflicts and failed deployments.

**After**: Deployments trigger ONLY on version tags (`v*.*.*`), not on push to `main`.

## Updated Workflow

### 1. Feature Development
```bash
git checkout -b feature/my-feature develop
# ... develop feature ...
git push -u origin feature/my-feature
# Create PR: feature/my-feature → develop
```

**CI Actions**:
- ✅ Tests run automatically on PR
- ✅ Tests run on push to develop after merge

### 2. Deploy to Staging (Automatic)
```bash
# After PR merged to develop
# Staging auto-deploys from develop branch
```

**CI Actions**:
- ✅ Tests run on develop
- ✅ Auto-deploy to staging (if configured)

### 3. Create Release Branch
```bash
git checkout -b release/v4.2.0 develop
# Update CHANGELOG.md
# Bump version
git commit -m "chore(release): prepare v4.2.0"
git push -u origin release/v4.2.0
```

**CI Actions**:
- ✅ Tests run on PR to main
- ❌ NO deployment yet

### 4. Manual Approval & Merge to Main
```bash
# After tests pass on staging
# Manually review and approve PR: release/v4.2.0 → main
# Use GitHub UI or:
gh pr create --base main --head release/v4.2.0
gh pr merge <PR_NUMBER> --merge  # Use merge commit, not squash
```

**CI Actions**:
- ✅ Tests run as part of PR checks
- ❌ NO deployment (push to main does NOT trigger deployment)

### 5. Create Production Tag (Triggers Deployment)
```bash
git checkout main
git pull origin main
git tag -a v4.2.0 -m "Release v4.2.0 - Feature Description"
git push origin v4.2.0
```

**CI Actions**:
- ✅ **DEPLOYMENT TRIGGERED** by tag push
- ✅ Tests run
- ✅ Docker image built
- ✅ Deployed to production

### 6. Merge Back to Develop
```bash
git checkout develop
git merge --no-ff release/v4.2.0
git push origin develop
# Delete release branch
git branch -d release/v4.2.0
git push origin --delete release/v4.2.0
```

**CI Actions**:
- ✅ Tests run on develop

## Key Changes

| Workflow File | Trigger Before | Trigger After | Why |
|---------------|----------------|---------------|-----|
| `test.yml` | Push to `main`, `develop`, `staging` | Push to `develop`, `staging` only | Avoid duplicate test runs on main |
| `deploy-production.yml` | Push to `main` | Push tags `v*.*.*` | Deploy only on explicit version tags |

## Trigger Matrix

| Event | test.yml | deploy-production.yml |
|-------|----------|----------------------|
| PR to develop | ✅ | ❌ |
| Push to develop | ✅ | ❌ |
| PR to main | ✅ | ❌ |
| Push to main | ❌ | ❌ |
| Push tag `v4.2.0` | ❌ | ✅ (test + build + deploy) |

## Manual Deployment (Emergency)

If you need to deploy without a tag (NOT recommended):

```bash
# Use workflow_dispatch in GitHub UI
gh workflow run deploy-production.yml
```

## Rollback Procedure

If deployment fails:

```bash
# Option 1: Re-tag previous version
git tag -d v4.2.0                    # Delete failed tag locally
git push origin --delete v4.2.0      # Delete failed tag remotely
git push origin v4.1.0               # Re-push previous good version (triggers deploy)

# Option 2: Create hotfix
git checkout -b hotfix/v4.2.1 main
# ... fix issue ...
git tag -a v4.2.1 -m "Hotfix: ..."
git push origin v4.2.1
```

## Best Practices

1. **Always test on staging** before creating production tag
2. **Use semantic versioning** for tags (v4.2.0, v4.2.1, v4.3.0)
3. **Write detailed tag messages** with changelog summary
4. **Wait for CI tests** to pass before creating tag
5. **Never force-push tags** - use new patch version instead

## Example Tag Message

```bash
git tag -a v4.2.0 -m "Release v4.2.0 - Google Maps Picker Fix

Added:
- Geographic service area restriction system
- Google Maps picker component for admin panel

Fixed:
- CRITICAL: Livewire/Alpine.js state conflict
- 95%+ improvement in map interaction

See: CHANGELOG.md"
```

## Troubleshooting

### Multiple Workflows Running
**Problem**: Tests and deployments running simultaneously

**Solution**: Check that you're using the updated workflow files (push tags only for deployment)

### Deployment Not Triggering
**Problem**: Pushed tag but deployment didn't run

**Solution**: Verify tag format matches `v*.*.*` (e.g., v4.2.0, NOT 4.2.0)

### Tests Failing on Main
**Problem**: Can't merge PR because tests fail

**Solution**: Fix tests on release branch, push changes, tests will re-run

#!/bin/bash
#
# Semantic Versioning Release Script
# Usage: ./scripts/release.sh [patch|minor|major]
#
# This script automates the release process:
# 1. Validates git repository state
# 2. Bumps version according to semantic versioning
# 3. Creates git tag
# 4. Pushes tag to trigger GitHub Actions deployment
#
# Examples:
#   ./scripts/release.sh patch  # v1.0.0 → v1.0.1 (bug fixes)
#   ./scripts/release.sh minor  # v1.0.1 → v1.1.0 (new features)
#   ./scripts/release.sh major  # v1.1.0 → v2.0.0 (breaking changes)
#

set -e  # Exit on error

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Functions
error() {
    echo -e "${RED}❌ Error: $1${NC}" >&2
    exit 1
}

success() {
    echo -e "${GREEN}✅ $1${NC}"
}

info() {
    echo -e "${BLUE}ℹ️  $1${NC}"
}

warning() {
    echo -e "${YELLOW}⚠️  $1${NC}"
}

# Validate input
if [ $# -eq 0 ]; then
    error "Missing version bump type. Usage: $0 [patch|minor|major]"
fi

BUMP_TYPE=$1

if [[ ! "$BUMP_TYPE" =~ ^(patch|minor|major)$ ]]; then
    error "Invalid version bump type: $BUMP_TYPE. Must be 'patch', 'minor', or 'major'"
fi

# Check if we're in a git repository
if ! git rev-parse --git-dir > /dev/null 2>&1; then
    error "Not in a git repository"
fi

# Check for uncommitted changes
if ! git diff-index --quiet HEAD --; then
    warning "You have uncommitted changes:"
    git status --short
    echo ""
    read -p "Continue anyway? (y/N) " -n 1 -r
    echo
    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
        error "Aborted. Commit your changes first."
    fi
fi

# Check if we're on main/master branch
CURRENT_BRANCH=$(git rev-parse --abbrev-ref HEAD)
if [[ ! "$CURRENT_BRANCH" =~ ^(main|master|develop)$ ]]; then
    warning "You're on branch '$CURRENT_BRANCH', not main/master/develop"
    read -p "Create release from this branch? (y/N) " -n 1 -r
    echo
    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
        error "Aborted. Switch to main/master/develop branch first."
    fi
fi

# Get current version from git tags
CURRENT_VERSION=$(git describe --tags --abbrev=0 2>/dev/null || echo "v0.0.0")

# Remove 'v' prefix if present
CURRENT_VERSION_NUMBER=${CURRENT_VERSION#v}

# Split version into parts
IFS='.' read -ra VERSION_PARTS <<< "$CURRENT_VERSION_NUMBER"
MAJOR=${VERSION_PARTS[0]:-0}
MINOR=${VERSION_PARTS[1]:-0}
PATCH=${VERSION_PARTS[2]:-0}

info "Current version: v${MAJOR}.${MINOR}.${PATCH}"

# Bump version based on type
case $BUMP_TYPE in
    patch)
        PATCH=$((PATCH + 1))
        ;;
    minor)
        MINOR=$((MINOR + 1))
        PATCH=0
        ;;
    major)
        MAJOR=$((MAJOR + 1))
        MINOR=0
        PATCH=0
        ;;
esac

NEW_VERSION="v${MAJOR}.${MINOR}.${PATCH}"

info "New version: $NEW_VERSION"

# Confirm before proceeding
echo ""
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "  Release Summary"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "  Bump type:      $BUMP_TYPE"
echo "  Current:        $CURRENT_VERSION"
echo "  New:            $NEW_VERSION"
echo "  Branch:         $CURRENT_BRANCH"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo ""

read -p "Create release $NEW_VERSION? (y/N) " -n 1 -r
echo
if [[ ! $REPLY =~ ^[Yy]$ ]]; then
    warning "Release cancelled"
    exit 0
fi

# Create git tag
info "Creating git tag: $NEW_VERSION"
git tag -a "$NEW_VERSION" -m "Release $NEW_VERSION

Release type: $BUMP_TYPE
Previous version: $CURRENT_VERSION
Branch: $CURRENT_BRANCH

This release will be automatically deployed to production via GitHub Actions.
"

success "Git tag created: $NEW_VERSION"

# Push tag to remote
info "Pushing tag to origin..."
if git push origin "$NEW_VERSION"; then
    success "Tag pushed to origin"
else
    error "Failed to push tag. You may need to push manually: git push origin $NEW_VERSION"
fi

# Show next steps
echo ""
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "  🎉 Release Created Successfully!"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo ""
echo "  Version: $NEW_VERSION"
echo "  Tag:     $NEW_VERSION"
echo ""
echo "  Next steps:"
echo "  1. GitHub Actions will automatically:"
echo "     • Build Docker image"
echo "     • Run tests"
echo "     • Wait for manual approval"
echo "     • Deploy to production"
echo ""
echo "  2. Monitor deployment:"
echo "     https://github.com/patrykgielo/registro/actions"
echo ""
echo "  3. Approve deployment when ready:"
echo "     Go to Actions → Deploy to Production → Review deployments"
echo ""
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo ""

# Show recent tags
info "Recent tags:"
git tag -l --sort=-version:refname | head -n 5

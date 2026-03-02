# Contributing to Registro

Thank you for considering contributing to Registro! This document provides guidelines and instructions for contributing to the project.

## Quick Start

1. **Fork the repository** on GitHub
2. **Clone your fork**:
   ```bash
   git clone git@github.com:your-username/registro.git
   cd registro/app
   ```
3. **Install dependencies**:
   ```bash
   ./docker-init.sh
   sudo ./add-hosts-entry.sh
   ```
4. **Create feature branch** from `develop`:
   ```bash
   git checkout develop
   git pull origin develop
   git checkout -b feature/my-feature
   ```

## Branching Strategy

We use **Gitflow** with staging-based release approval:

```
main (production, tagged with versions)
  ↑
  └─ release/v0.3.0 ← created after staging approval
      ↑
      └─ develop (integration, auto-deploys to staging)
          ↑
          ├─ feature/my-feature
          ├─ feature/another-feature
          └─ hotfix/emergency-fix
```

### Branch Hierarchy

**Primary Branches** (long-lived, protected):
- `main` - Production-ready code (requires PR + review)
- `develop` - Integration branch (requires PR)
- `staging` - Auto-deploys from develop

**Supporting Branches** (short-lived, auto-deleted):
- `feature/*` - New features (from develop)
- `release/*` - Release preparation (from develop, after staging approval)
- `hotfix/*` - Emergency production fixes (from main)

**Detailed Workflow**: See [docs/deployment/GIT_WORKFLOW.md](docs/deployment/GIT_WORKFLOW.md)

## Feature Development Workflow

### 1. Create Feature Branch

```bash
git checkout develop
git pull origin develop
git checkout -b feature/customer-profile
```

**Naming Convention**: `feature/short-descriptive-name`
- ✅ `feature/customer-profile`
- ✅ `feature/booking-system`
- ❌ `feature/john-work` (not descriptive)
- ❌ `customer-profile` (missing prefix)

### 2. Develop Feature

Make your changes with clear, atomic commits:

```bash
# Make changes
git add .
git commit -m "feat(profile): add customer profile page"

# Continue development
git add .
git commit -m "feat(profile): add profile validation"

# Add tests
git add .
git commit -m "test(profile): add profile validation tests"
```

### 3. Push & Create Pull Request

```bash
# Push feature branch
git push -u origin feature/customer-profile
```

**Create Pull Request** on GitHub:
- **From**: `feature/customer-profile`
- **To**: `develop`
- **Title**: Clear description (e.g., "Add customer profile management")
- **Description**:
  - What changed
  - Why it changed
  - How to test

### 4. Code Review

- Address review comments promptly
- Push additional commits to the same branch
- Request re-review after changes
- Wait for approval (at least 1 reviewer)

### 5. Merge to Develop

After approval:
- **Merge strategy**: Squash and merge (recommended) or merge commit
- Feature branch auto-deleted after merge ✅

### 6. Testing on Staging

- `develop` auto-deploys to staging
- Test your feature on `https://staging.registro.com`
- Verify no regressions
- Confirm ready for production

## Commit Message Conventions

We follow **Conventional Commits** format:

### Format: `type(scope): subject`

**Types**:
- `feat:` - New feature
- `fix:` - Bug fix
- `docs:` - Documentation changes
- `style:` - Code style changes (formatting, no logic change)
- `refactor:` - Code refactoring (no feature change)
- `test:` - Adding or updating tests
- `chore:` - Build process, dependencies, tooling
- `perf:` - Performance improvements
- `ci:` - CI/CD configuration changes

**Scope** (optional but recommended):
- Feature area: `auth`, `booking`, `email`, `admin`, `cms`
- Component: `ui`, `api`, `db`, `docker`, `ci`

### Examples

```bash
feat(booking): add appointment cancellation feature
fix(auth): resolve session fixation vulnerability
docs(readme): update installation instructions
refactor(services): extract email logic to service class
test(appointment): add integration tests for booking flow
chore(deps): upgrade Laravel to 12.32.5
perf(queries): optimize booking availability query
ci(deploy): add staging deployment workflow
```

### Multi-line Commit Messages

For complex changes, provide additional context:

```bash
git commit -m "feat(booking): add multi-service appointment booking

Allow customers to book multiple services in a single appointment.

Changes:
- Add service_ids JSON column to appointments table
- Update booking wizard to support multiple selections
- Add validation for service compatibility
- Update pricing calculation

Closes #123"
```

## Release Process

**After staging approval**:

```bash
# 1. Create release branch from develop
git checkout develop
git pull origin develop
git checkout -b release/v0.3.0

# 2. Update CHANGELOG.md
# Add release notes under [0.3.0] section

# 3. Push release branch
git push -u origin release/v0.3.0

# 4. Create PR: release/v0.3.0 → main
# After approval and merge:

# 5. Tag production release (triggers deployment)
git checkout main
git pull origin main
git tag -a v0.3.0 -m "Release v0.3.0 - Customer Profile Feature

Added:
- Customer profile page
- Profile validation
- Address autocomplete

Fixed:
- User model mass assignment issue
- Session encryption enabled

See: CHANGELOG.md"

git push origin v0.3.0

# 6. Merge back to develop
git checkout develop
git merge --no-ff release/v0.3.0
git push origin develop

# 7. Delete release branch
git branch -d release/v0.3.0
git push origin --delete release/v0.3.0
```

**Automated Tagging** (alternative):
```bash
# Use release script
./scripts/release.sh minor  # v0.2.11 → v0.3.0
./scripts/release.sh patch  # v0.3.0 → v0.3.1
./scripts/release.sh major  # v0.3.1 → v1.0.0
```

**See**: [CI/CD Deployment Runbook](docs/deployment/runbooks/ci-cd-deployment.md)

## Code Review Guidelines

### As a Reviewer

- Review within **24 hours** when possible
- Be **constructive** and **respectful**
- Focus on:
  - ✅ Code follows Laravel conventions
  - ✅ Tests pass
  - ✅ No security vulnerabilities
  - ✅ Documentation updated
  - ✅ No obvious performance issues
  - ✅ Commit messages follow conventions

**Approve** if:
- Code meets quality standards
- Tests are adequate
- No blocking issues

**Request changes** if:
- Security vulnerabilities detected
- Tests are missing or failing
- Code violates project standards
- Logic errors or bugs found

### As a Contributor

- Respond to review comments promptly
- Ask clarifying questions if feedback is unclear
- Be open to suggestions and improvements
- Update code based on feedback
- Request re-review after changes

## Testing Requirements

### Running Tests

```bash
# Run all tests
cd app && composer test

# Run specific test suite
php artisan test --testsuite=Feature
php artisan test --testsuite=Unit

# Run specific test file
php artisan test tests/Feature/BookingTest.php

# Run with coverage
php artisan test --coverage
```

### Test Coverage Requirements

- **New features**: Must include feature tests
- **Bug fixes**: Must include regression tests
- **Business logic**: Must include unit tests
- **API endpoints**: Must include integration tests

### Before Submitting PR

```bash
# 1. Run tests
composer test

# 2. Run code formatting
./vendor/bin/pint

# 3. Check for errors
php artisan optimize:clear
composer validate
```

## Code Style

We follow **Laravel conventions** and use **Laravel Pint** for code formatting.

### Running Pint

```bash
# Format all files
./vendor/bin/pint

# Format specific files
./vendor/bin/pint app/Models/User.php

# Preview changes without applying
./vendor/bin/pint --test
```

### Key Conventions

- **PSR-12** code style
- **4 spaces** for indentation (not tabs)
- **DocBlocks** for classes and public methods
- **Type hints** for parameters and return types
- **Descriptive variable names** (not single letters)

### Example

```php
<?php

namespace App\Services;

use App\Models\Appointment;
use Illuminate\Support\Collection;

class AppointmentService
{
    /**
     * Get available time slots for a service.
     *
     * @param  int  $serviceId
     * @param  string  $date
     * @return Collection
     */
    public function getAvailableSlots(int $serviceId, string $date): Collection
    {
        // Implementation
    }
}
```

## Security

### Reporting Security Vulnerabilities

**DO NOT** create public GitHub issues for security vulnerabilities.

Instead, email: **security@registro.com**

Include:
- Description of the vulnerability
- Steps to reproduce
- Potential impact
- Suggested fix (if any)

We will respond within **48 hours**.

### Security Best Practices

- **Never commit secrets** (.env files, API keys, passwords)
- **Validate all user input** (use Form Requests)
- **Use parameterized queries** (Eloquent ORM)
- **Escape output** (Blade `{{ }}` auto-escapes)
- **Enable CSRF protection** (default in Laravel)
- **Use HTTPS** in production
- **Follow OWASP Top 10** guidelines

## Documentation

### When to Update Documentation

- **New features**: Add feature documentation in `app/docs/features/`
- **API changes**: Update API documentation
- **Configuration changes**: Update relevant config docs
- **Breaking changes**: Highlight in CHANGELOG.md
- **Deployment changes**: Update deployment guides

### Documentation Structure

```
app/docs/
├── README.md                    # Documentation hub
├── architecture/                # System architecture
├── deployment/                  # Deployment guides
│   └── GIT_WORKFLOW.md         # Git workflow (detailed)
├── features/                    # Feature-specific docs
├── guides/                      # How-to guides
└── decisions/                   # Architecture Decision Records
```

### Writing Documentation

- **Clear and concise**: Avoid jargon
- **Examples**: Include code examples
- **Up-to-date**: Keep docs synchronized with code
- **Accessible**: Use proper headings and structure

## Questions and Support

### Getting Help

1. **Check documentation**: [app/docs/README.md](docs/README.md)
2. **Search existing issues**: GitHub Issues
3. **Ask in discussions**: GitHub Discussions
4. **Team chat**: Slack #registro-dev (for team members)

### Creating Issues

**Good Issue**:
```markdown
**Bug Report**: Appointment booking fails for multi-service selection

**Steps to Reproduce**:
1. Navigate to /rezerwacja
2. Select 2 services
3. Click "Next"
4. Error: "Invalid service selection"

**Expected**: Should proceed to datetime selection
**Actual**: Error message displayed

**Environment**:
- Laravel 12.32.5
- PHP 8.2.29
- Browser: Chrome 120

**Screenshots**: [attached]
```

**Bad Issue**:
```markdown
Booking doesn't work
```

## Development Environment

### Docker Services

```bash
# Start all services
docker compose up -d

# View logs
docker compose logs -f [service]

# Run artisan commands
docker compose exec app php artisan <command>

# Access MySQL shell
docker compose exec mysql mysql -u registro -ppassword registro

# Stop containers
docker compose down
```

### Environment Configuration

Copy `.env.example` to `.env` and configure:

```bash
cp .env.example .env

# Critical settings:
APP_ENV=local
APP_DEBUG=true
DB_HOST=registro-mysql
REDIS_HOST=redis
```

### Troubleshooting

Common issues and solutions:

**Permission Denied**:
```bash
docker compose build --no-cache app
docker compose restart app
```

**Port Already in Use**:
```bash
# Check what's using port 8444
sudo lsof -i :8444

# Change port in docker-compose.yml
```

**Database Connection Refused**:
```bash
# Verify DB_HOST in .env
DB_HOST=registro-mysql  # NOT localhost!

docker compose restart mysql
```

**See**: [Troubleshooting Guide](docs/guides/troubleshooting.md)

## License

This project is proprietary software. By contributing, you agree that your contributions will be licensed under the same license as the project.

## Code of Conduct

### Our Standards

- **Be respectful**: Treat everyone with respect
- **Be collaborative**: Work together constructively
- **Be inclusive**: Welcome diverse perspectives
- **Be professional**: Maintain professionalism in all interactions

### Unacceptable Behavior

- Harassment or discrimination
- Trolling or inflammatory comments
- Personal attacks
- Publishing private information
- Any conduct that could be considered inappropriate

### Reporting

Report violations to: **conduct@registro.com**

---

## Summary

**Quick Checklist**:
- ✅ Fork repository
- ✅ Create feature branch from `develop`
- ✅ Follow commit conventions (`feat:`, `fix:`, etc.)
- ✅ Write tests for new features
- ✅ Run `composer test` and `./vendor/bin/pint`
- ✅ Create PR to `develop`
- ✅ Address code review feedback
- ✅ Verify on staging before release

**Resources**:
- [Git Workflow Guide](docs/deployment/GIT_WORKFLOW.md) - Detailed workflow
- [Quick Start Guide](docs/guides/quick-start.md) - Setup instructions
- [CHANGELOG.md](CHANGELOG.md) - Version history
- [CLAUDE.md](CLAUDE.md) - Project overview for Claude Code

**Thank you for contributing!** 🚀

# Admin-Created User Welcome Email

**Version:** v0.3.0
**Status:** Completed
**Author:** Development Team
**Date:** 2025-12-02

## Overview

When admins create new users through the Filament admin panel, this feature automatically sends a welcome email with a secure link to set up their password. This improves user onboarding by eliminating the need for admins to manually communicate temporary passwords.

**Business Value:**
- Improved security: Users set their own passwords (never transmitted to admin)
- Better UX: Automated onboarding eliminates manual steps
- Reduced support: Clear instructions in welcome email

**User Benefit:**
- Secure password setup process (24-hour time-limited tokens)
- Professional welcome experience
- Clear instructions for account activation

**Scope:**
- ✅ **Included:** Welcome email, password setup flow, email templates (PL/EN)
- ❌ **Excluded:** User self-registration, social login, two-factor authentication

---

## Database Changes

### Migrations

**⚠️ REQUIRED FOR DEPLOYMENT**

#### Migration Files

1. **2025_12_02_081001_add_password_setup_to_users_table.php**
   - **Purpose:** Add password setup token columns for secure user activation
   - **Tables affected:** users
   - **Columns added:** password_setup_token (VARCHAR 64), password_setup_expires_at (TIMESTAMP)
   - **Indexes added:** idx_password_setup_token (password_setup_token)
   - **Rollback safe:** ✅ Yes (drops columns and index in down() method)

**Schema Changes:**
```sql
ALTER TABLE users ADD COLUMN password_setup_token VARCHAR(64) NULLABLE;
ALTER TABLE users ADD COLUMN password_setup_expires_at TIMESTAMP NULLABLE;
CREATE INDEX idx_password_setup_token ON users(password_setup_token);
```

**Verification Commands:**
```bash
# Check migration applied
docker compose exec app php artisan migrate:status | grep add_password_setup_to_users_table

# Check columns exist
docker compose exec mysql mysql -u registro -ppassword registro -e "DESCRIBE users;" | grep password_setup

# Check index exists
docker compose exec mysql mysql -u registro -ppassword registro -e "SHOW INDEXES FROM users WHERE Key_name='idx_password_setup_token';"
```

### Seeders

**⚠️ CRITICAL FOR DEPLOYMENT**

#### Seeder Files

1. **EmailTemplateSeeder.php** (updated with 2 new templates)
   - **Purpose:** Seed email templates for admin-created user notifications
   - **Idempotent:** ✅ Yes (uses updateOrCreate with composite key)
   - **Production safe:** ✅ Yes (no test data, updates existing records)
   - **Record count:** 30 templates total (15 types × 2 languages)
   - **Dependencies:** None

**New Templates Added:**
1. `admin-user-created` (Polish)
2. `admin-user-created` (English)

**Unique Constraints** (for idempotency):
```php
EmailTemplate::updateOrCreate(
    [
        'key' => 'admin-user-created',      // Unique key
        'language' => 'pl',                 // Language code
    ],
    [
        'subject' => 'Twoje konto zostało utworzone',
        'html_body' => '...',
        'text_body' => '...',
        'variables' => ['user_name', 'app_name', 'user_email', 'setup_url', 'expires_at'],
        'active' => true,
    ]
);
```

**Deploy Script Recognition:**
- ✅ **First deployment:** Runs via DatabaseSeeder
- ✅ **Subsequent deployments:** EVERY deployment (EmailTemplateSeeder in `SUBSEQUENT_DEPLOYMENT_SEEDERS`)

**Verification Commands:**
```bash
# Check template count
docker compose exec app php artisan tinker --execute="echo App\\Models\\EmailTemplate::count()"
# Expected: 30

# Check specific templates exist
docker compose exec app php artisan tinker --execute="App\\Models\\EmailTemplate::where('key', 'admin-user-created')->get(['key', 'language', 'subject'])"
# Expected: 2 records (PL, EN)

# Verify no duplicates
docker compose exec app php artisan tinker --execute="App\\Models\\EmailTemplate::select('key', 'language')->groupBy('key', 'language')->havingRaw('COUNT(*) > 1')->get()"
# Expected: empty collection
```

---

## Configuration Changes

### Environment Variables

**No new environment variables required** (uses existing email configuration)

**Existing variables used:**
```bash
# Example SMTP configuration (replace with your actual values)
MAIL_MAILER=smtp
MAIL_HOST=smtp.example.com
MAIL_PORT=587
MAIL_USERNAME=your-email@example.com
MAIL_PASSWORD=your-app-specific-password-here
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=noreply@registro.com
MAIL_FROM_NAME="${APP_NAME}"
```

---

## Implementation Details

### Key Files

**Models:**
- `app/Models/User.php` - Added password setup methods:
  - `initiatePasswordSetup(): string` - Generates 24h token
  - `completePasswordSetup(string $token, string $password): bool` - Validates and sets password

**Events:**
- `app/Events/AdminCreatedUser.php` - Dispatched when admin creates user

**Notifications:**
- `app/Notifications/AdminCreatedUserNotification.php` - Queued email notification

**Controllers:**
- `app/Http/Controllers/Auth/SetPasswordController.php` - Handles password setup form

**Views:**
- `resources/views/auth/passwords/setup.blade.php` - Password setup form
- `resources/views/auth/passwords/token-expired.blade.php` - Expired token page

**Routes:**
```php
Route::get('/password/setup/{token}', [SetPasswordController::class, 'show'])
    ->name('password.setup');
Route::post('/password/setup', [SetPasswordController::class, 'store'])
    ->name('password.setup.store')
    ->middleware('throttle:6,1'); // Rate limit: 6 attempts/minute
```

### Workflow

1. **Admin creates user** (Filament panel, password field left empty)
2. **CreateUser hook** → `initiatePasswordSetup()` → generates token (24h expiry)
3. **Event dispatched** → AdminCreatedUser
4. **Notification queued** → AdminCreatedUserNotification
5. **Email sent** → Welcome email with setup link (`/password/setup/{token}`)
6. **User clicks link** → Setup form displayed (if token valid)
7. **User submits password** → `completePasswordSetup()` → password set, token cleared
8. **User redirected** → Login page with success message

---

## Deployment Steps

### Pre-Deployment Checklist

- [x] Merged feature branch to develop
- [x] Deployed to staging environment
- [x] Ran full regression tests
- [x] Verified seeder idempotency (ran EmailTemplateSeeder twice, no duplicates)
- [x] Updated CHANGELOG.md with v0.3.0 changes
- [x] Created release branch (release/v0.3.0)
- [x] Updated version numbers (composer.json)
- [x] Notified stakeholders of deployment

### Deployment Sequence

1. **GitHub Actions triggered** (tag push v0.3.0)
2. **Build Docker image** (~8 minutes)
3. **Manual approval** (production environment)
4. **SSH to VPS** (automated)
5. **Pull new image** (~2 minutes)
6. **Start new container** (zero-downtime strategy)
7. **Run migrations** (~15s downtime)
   ```bash
   docker exec app php artisan migrate --force
   ```
8. **Run seeders** (~6s additional downtime)
   ```bash
   docker exec app php artisan deploy:seed
   # Output: "Subsequent deployment detected, running EmailTemplateSeeder + SmsTemplateSeeder"
   ```
9. **Switch traffic** (new container serves requests)
10. **Clear caches** (optimize, filament)
11. **Health check** (verify /up endpoint)

**Total downtime:** ~21s (migrations + seeders)

### Post-Deployment Verification

```bash
# 1. Check migration applied
docker compose exec app php artisan migrate:status | grep add_password_setup_to_users_table
# Expected: "Ran"

# 2. Check templates seeded
docker compose exec app php artisan tinker --execute="App\\Models\\EmailTemplate::where('key', 'admin-user-created')->count()"
# Expected: 2

# 3. Create test user via admin panel (password field empty)
# Navigate to: https://registro.com/admin/users/create
# Fill: Name, Email (leave password empty), Role (staff)
# Click: Create

# 4. Check email sent
docker compose logs horizon | grep "AdminCreatedUserNotification"
# Expected: "Processing: App\Notifications\AdminCreatedUserNotification"

# 5. Check email in Mailpit (local) or Gmail sent items (production)
# Verify: Subject, setup link, expiry time

# 6. Test password setup flow
# Click setup link in email → Enter password → Submit
# Expected: Redirect to login with success message
```

---

## Testing

### Unit Tests

**Files:**
- `tests/Unit/Models/UserTest.php`

**Key test cases:**
```php
test('initiatePasswordSetup_generates_valid_token')
{
    $user = User::factory()->create();
    $token = $user->initiatePasswordSetup();

    $this->assertNotNull($user->password_setup_token);
    $this->assertEquals(64, strlen($user->password_setup_token));
    $this->assertNotNull($user->password_setup_expires_at);
    $this->assertTrue($user->password_setup_expires_at->isFuture());
}

test('completePasswordSetup_sets_password_and_clears_token')
{
    $user = User::factory()->create();
    $token = $user->initiatePasswordSetup();

    $result = $user->completePasswordSetup($token, 'NewPassword123!');

    $this->assertTrue($result);
    $this->assertNull($user->password_setup_token);
    $this->assertNull($user->password_setup_expires_at);
    $this->assertTrue(Hash::check('NewPassword123!', $user->password));
}

test('completePasswordSetup_fails_with_expired_token')
{
    $user = User::factory()->create([
        'password_setup_token' => 'expired-token',
        'password_setup_expires_at' => now()->subHours(25), // Expired
    ]);

    $result = $user->completePasswordSetup('expired-token', 'NewPassword123!');

    $this->assertFalse($result);
}
```

### Integration Tests

**Files:**
- `tests/Feature/Auth/SetPasswordControllerTest.php`

**Key test cases:**
```php
test('password_setup_form_displays_with_valid_token')
{
    $user = User::factory()->create();
    $token = $user->initiatePasswordSetup();

    $response = $this->get(route('password.setup', ['token' => $token]));

    $response->assertOk();
    $response->assertViewIs('auth.passwords.setup');
    $response->assertViewHas('token', $token);
}

test('password_setup_fails_with_expired_token')
{
    $user = User::factory()->create([
        'password_setup_token' => 'expired',
        'password_setup_expires_at' => now()->subHours(25),
    ]);

    $response = $this->get(route('password.setup', ['token' => 'expired']));

    $response->assertViewIs('auth.passwords.token-expired');
}

test('password_setup_submits_successfully')
{
    $user = User::factory()->create(['email' => 'test@example.com']);
    $token = $user->initiatePasswordSetup();

    $response = $this->post(route('password.setup.store'), [
        'token' => $token,
        'password' => 'NewPassword123!',
        'password_confirmation' => 'NewPassword123!',
    ]);

    $response->assertRedirect(route('login'));
    $response->assertSessionHas('status', 'Hasło ustawione pomyślnie. Możesz się teraz zalogować.');
    $this->assertTrue(Hash::check('NewPassword123!', $user->fresh()->password));
}
```

### Manual Testing Checklist

#### Happy Path
- [x] Admin creates user without password → Email sent
- [x] User receives email within 1 minute
- [x] User clicks setup link → Form displays
- [x] User enters valid password → Password saved
- [x] User redirected to login → Can log in successfully

#### Edge Cases
- [x] Token expires after 24 hours → Expired page shown
- [x] User tries to use token twice → Second attempt fails
- [x] Invalid token provided → 404 or expired page
- [x] Weak password submitted → Validation errors shown

#### Error Handling
- [x] Email queue failure → Logged, admin notified
- [x] Database connection failure during setup → Error message shown
- [x] Rate limiting triggered (>6 attempts/min) → 429 error

---

## Troubleshooting

### Issue 1: Welcome Email Not Sent

**Symptoms:**
Admin creates user without password, but no email arrives

**Diagnosis:**
```bash
# Check if event dispatched
docker compose logs app | grep "AdminCreatedUser"

# Check queue processing
docker compose logs horizon | grep "AdminCreatedUserNotification"

# Check failed jobs
docker compose exec app php artisan queue:failed
```

**Cause:**
- Queue worker not running
- Email configuration incorrect
- Network connectivity issue

**Solution:**
```bash
# Restart queue workers
docker compose restart horizon

# Test email configuration
docker compose exec app php artisan tinker
>>> \Mail::raw('Test', function($msg) { $msg->to('test@example.com')->subject('Test'); })

# Retry failed jobs
docker compose exec app php artisan queue:retry all
```

### Issue 2: Password Setup Token Expired

**Symptoms:**
User clicks setup link, sees "Link wygasł lub jest nieprawidłowy"

**Cause:**
Token expires after 24 hours (by design)

**Solution:**
```bash
# Admin must create new password setup link
# Option 1: Delete user and recreate (not recommended if user has data)
# Option 2: Manually generate new token

docker compose exec app php artisan tinker
>>> $user = App\Models\User::where('email', 'user@example.com')->first()
>>> $token = $user->initiatePasswordSetup()
>>> $url = url(route('password.setup', ['token' => $token]))
>>> echo $url
# Send this URL to user manually
```

### Issue 3: Seeder Failed During Deployment

**Symptoms:**
Deployment aborted with "Seeder execution failed - deployment aborted"

**Diagnosis:**
```bash
# Check deploy:seed logs
docker compose logs app | grep -A 20 "deploy:seed"

# Check EmailTemplateSeeder specifically
docker compose logs app | grep "EmailTemplateSeeder"

# Check for database errors
docker compose logs app | grep "SQLSTATE"
```

**Cause:**
- Unique constraint violation (duplicate key)
- Database connection timeout
- Migration not run before seeder

**Solution:**
```bash
# If migration missing
docker compose exec app php artisan migrate:status
docker compose exec app php artisan migrate --force

# If duplicate key error (investigate data corruption)
docker compose exec mysql mysql -u registro -ppassword registro -e "SELECT key, language, COUNT(*) FROM email_templates GROUP BY key, language HAVING COUNT(*) > 1;"

# If seeder failed, run manually
docker compose exec app php artisan db:seed --class=EmailTemplateSeeder --force

# Verify templates exist
docker compose exec app php artisan tinker --execute="App\\Models\\EmailTemplate::where('key', 'admin-user-created')->count()"
# Expected: 2
```

---

## Architecture Decision Records

### ADR-001: Custom 24-Hour Tokens vs Laravel PasswordBroker

**Date:** 2025-12-02

**Context:**
Laravel's built-in password reset uses 60-minute token expiry. For admin-created users, we need longer validity (24 hours) to accommodate users in different timezones or who check email infrequently.

**Decision:**
Implement custom `password_setup_token` and `password_setup_expires_at` columns with 24-hour expiry.

**Consequences:**
- **Benefit:** Better UX (users have full day to activate)
- **Benefit:** No dependency on Laravel's PasswordBroker (simpler architecture)
- **Drawback:** Custom implementation to maintain (token generation, validation)
- **Drawback:** Additional database columns required

**Alternatives Considered:**
1. **Laravel PasswordBroker** - Rejected due to 60-minute expiry (too short)
2. **Magic links (no password)** - Rejected due to security concerns (permanent access)

**Status:** Accepted

---

## Related Documentation

- [Email System Documentation](../email-system/README.md)
- [User Model Documentation](../../architecture/user-model.md)
- [Filament Resources Guide](../../guides/filament-resources.md)
- [ADR-011: Healthcheck Deployment Strategy](../../deployment/ADR-011-healthcheck-deployment-strategy.md)

---

## Changelog

### v0.3.0 - 2025-12-02

**Added:**
- Password setup token system for admin-created users
- AdminCreatedUser event and notification
- SetPasswordController with setup/store actions
- Password setup views (setup.blade.php, token-expired.blade.php)
- 2 new email templates (admin-user-created PL/EN)
- Routes for password setup flow with rate limiting

**Changed:**
- Filament UserResource password field made optional
- User model extended with password setup helper methods
- EmailTemplateSeeder updated (28 → 30 templates)

**Security:**
- Password setup tokens use 256-bit entropy (Str::random(64))
- Token expiration enforced at 24 hours
- Rate limiting on password setup endpoint (6 attempts/minute)

---

**Last Updated:** 2025-12-02
**Maintained By:** Development Team
**Review Cycle:** As Needed

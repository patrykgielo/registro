# Staging Environment - Credentials

```
⚠️⚠️⚠️ CRITICAL SECURITY WARNING ⚠️⚠️⚠️

THIS FILE CONTAINS SENSITIVE CREDENTIALS AND MUST NEVER BE COMMITTED TO GIT!

This file is listed in .gitignore as: docs/environments/*/03-CREDENTIALS.md

If you see this file in Git, IMMEDIATELY:
1. Remove it from the repository: git rm --cached docs/environments/staging/03-CREDENTIALS.md
2. Rotate ALL credentials listed below
3. Review Git history to ensure credentials weren't exposed

DO NOT share this file via email, Slack, or any unencrypted channel.
Store backups in a secure password manager or encrypted storage only.
```

---

**Environment**: Staging VPS
**Server**: 72.60.17.138 (paradocks.pl, old: srv1117368.hstgr.cloud)
**Last Updated**: 2025-11-11

---

## System Access

### SSH Access

**Server**: 72.60.17.138
**Port**: 22
**User**: ubuntu
**Authentication**: SSH key only (password authentication disabled)

```bash
# SSH command
ssh ubuntu@72.60.17.138

# Or using explicit key
ssh -i ~/.ssh/your_private_key ubuntu@72.60.17.138
```

**Notes**:
- Root login disabled for security
- Password authentication disabled
- Only key-based authentication allowed
- Authorized keys stored in: `/home/ubuntu/.ssh/authorized_keys`

---

## Application Credentials

### Laravel Application Key

**Purpose**: Encryption and security (sessions, cookies, encrypted data)

```env
APP_KEY=base64:... (generated via php artisan key:generate)
```

**Location**: `/var/www/paradocks/.env`

**IMPORTANT**:
- Never share this key
- Never commit to Git
- Regenerating will invalidate all encrypted data and sessions
- If compromised, must regenerate and all users must re-login

**Regenerate if compromised**:
```bash
docker-compose -f docker-compose.prod.yml exec app php artisan key:generate
docker-compose -f docker-compose.prod.yml restart app
```

---

## Database Credentials

### MySQL Database Access

**Host**: mysql (internal Docker) / 72.60.17.138 (external)
**Port**: 3306
**Database**: paradocks

#### Application User (paradocks)

**Username**: `paradocks`
**Password**: `ENDnAJtD+RtLl88WE0K1UUT/lSlQ6YYk`

**Privileges**: All privileges on `paradocks` database only

**Connection Examples**:

```bash
# From within Docker network
mysql -h mysql -u paradocks -p paradocks
# Password: ENDnAJtD+RtLl88WE0K1UUT/lSlQ6YYk

# From external machine (e.g., MySQL Workbench)
mysql -h 72.60.17.138 -P 3306 -u paradocks -p paradocks
# Password: ENDnAJtD+RtLl88WE0K1UUT/lSlQ6YYk
```

**.env Configuration**:
```env
DB_CONNECTION=mysql
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=paradocks
DB_USERNAME=paradocks
DB_PASSWORD=ENDnAJtD+RtLl88WE0K1UUT/lSlQ6YYk
```

#### Root User

**Username**: `root`
**Password**: `SO0I1JLL4/Sgn5NIpSyf0N0VKYB9EsHq`

**Privileges**: Full administrative access (all databases)

**Use Cases**:
- Database maintenance
- User management
- Emergency access
- Backup/restore operations

**Connection**:

```bash
# From server (via Docker)
docker-compose -f docker-compose.prod.yml exec mysql mysql -u root -p
# Password: SO0I1JLL4/Sgn5NIpSyf0N0VKYB9EsHq

# From external machine (NOT RECOMMENDED - use SSH tunnel)
mysql -h 72.60.17.138 -P 3306 -u root -p
# Password: SO0I1JLL4/Sgn5NIpSyf0N0VKYB9EsHq
```

**.env Configuration**:
```env
DB_ROOT_PASSWORD=SO0I1JLL4/Sgn5NIpSyf0N0VKYB9EsHq
```

**IMPORTANT**:
- Root access should be used sparingly
- Never use root credentials in application code
- Consider disabling remote root access for production

**Change Root Password** (if needed):
```bash
docker-compose -f docker-compose.prod.yml exec mysql mysql -u root -p
ALTER USER 'root'@'%' IDENTIFIED BY 'new_password_here';
FLUSH PRIVILEGES;
```

---

## Redis Credentials

### Redis Cache/Queue/Session

**Host**: redis (internal Docker) / 72.60.17.138 (external)
**Port**: 6379
**Password**: `bt3mHr07Im0AVS3Jau851E1KsimlHf02`

**Connection Examples**:

```bash
# From within Docker network
redis-cli -h redis -a bt3mHr07Im0AVS3Jau851E1KsimlHf02

# From external machine
redis-cli -h 72.60.17.138 -p 6379 -a bt3mHr07Im0AVS3Jau851E1KsimlHf02

# Or authenticate after connecting
redis-cli -h 72.60.17.138 -p 6379
127.0.0.1:6379> AUTH bt3mHr07Im0AVS3Jau851E1KsimlHf02
OK
```

**.env Configuration**:
```env
REDIS_CLIENT=phpredis
REDIS_HOST=redis
REDIS_PORT=6379
REDIS_PASSWORD=bt3mHr07Im0AVS3Jau851E1KsimlHf02
```

**PHP Session Configuration** (`docker/php/php.ini`):
```ini
session.save_handler = redis
session.save_path = "tcp://redis:6379?auth=bt3mHr07Im0AVS3Jau851E1KsimlHf02"
```

**Logical Databases**:
- DB 0: Default/Queue
- DB 1: Cache
- DB 2: Sessions (via PHP config)

**Change Redis Password** (requires restart):
```bash
# Update .env
REDIS_PASSWORD=new_password_here

# Update docker/php/php.ini
session.save_path = "tcp://redis:6379?auth=new_password_here"

# Restart services
docker-compose -f docker-compose.prod.yml down
docker-compose -f docker-compose.prod.yml up -d
```

---

## Application Admin Credentials

### Filament Admin Panel

**Access URL**: http://72.60.17.138/admin

**Email**: `admin@paradocks.com`
**Password**: `Admin123!`

```
⚠️ CRITICAL - TEMPORARY PASSWORD - MUST BE CHANGED IMMEDIATELY ⚠️

This is a temporary password set during initial deployment.
You MUST change this password as soon as possible.
```

**Change Password**:

**Option 1: Via Admin Panel**
1. Login to http://72.60.17.138/admin
2. Click on user menu (top right)
3. Click "Profile" or "Settings"
4. Change password
5. Logout and login with new password

**Option 2: Via Tinker**
```bash
docker-compose -f docker-compose.prod.yml exec app php artisan tinker

>>> $admin = \App\Models\User::where('email', 'admin@paradocks.com')->first();
>>> $admin->password = bcrypt('YourNewSecurePassword123!@#');
>>> $admin->save();
>>> exit
```

**Option 3: Via Database**
```bash
# Generate bcrypt hash (use online tool or tinker)
docker-compose -f docker-compose.prod.yml exec app php artisan tinker
>>> bcrypt('YourNewSecurePassword123!@#');
# Copy the hash

# Update in database
docker-compose -f docker-compose.prod.yml exec mysql mysql -u paradocks -p paradocks
UPDATE users SET password='$2y$12$...' WHERE email='admin@paradocks.com';
exit;
```

**Password Requirements** (Recommended):
- Minimum 12 characters
- Mix of uppercase, lowercase, numbers, symbols
- No dictionary words
- Unique password (not reused elsewhere)
- Store in password manager

---

## Email Credentials

### Current Configuration

**Mail Driver**: `log` (emails written to logs, not sent)

**.env Configuration**:
```env
MAIL_MAILER=log
MAIL_FROM_ADDRESS="noreply@paradocks.com"
MAIL_FROM_NAME="ParaDocks"
```

**Emails are currently logged to**: `storage/logs/laravel.log`

### Pending Gmail SMTP Configuration

```
⚠️ PENDING CONFIGURATION - CREDENTIALS NEEDED ⚠️

Gmail SMTP has not been configured yet.
You will need to generate a Gmail App Password.
```

**When Ready to Configure**:

1. **Generate Gmail App Password**:
   - Go to https://myaccount.google.com/security
   - Enable 2-Factor Authentication (if not already enabled)
   - Go to "App passwords"
   - Generate new app password for "Mail"
   - Copy the 16-character password

2. **Update .env**:
   ```env
   MAIL_MAILER=smtp
   MAIL_HOST=smtp.gmail.com
   MAIL_PORT=587
   MAIL_USERNAME=your-email@gmail.com
   MAIL_PASSWORD=your-16-char-app-password
   MAIL_ENCRYPTION=tls
   MAIL_FROM_ADDRESS="noreply@paradocks.com"
   MAIL_FROM_NAME="ParaDocks"
   ```

3. **Clear Configuration Cache**:
   ```bash
   docker-compose -f docker-compose.prod.yml exec app php artisan config:clear
   docker-compose -f docker-compose.prod.yml exec app php artisan config:cache
   ```

4. **Test Email**:
   ```bash
   docker-compose -f docker-compose.prod.yml exec app php artisan tinker
   >>> \Mail::raw('Test email', function($msg) {
       $msg->to('your-test-email@example.com')->subject('Test');
   });
   ```

**Placeholder Credentials** (update when configured):
```env
# Gmail SMTP (NOT CONFIGURED YET)
MAIL_USERNAME=
MAIL_PASSWORD=
```

---

## External Service API Keys

### Pusher (Broadcasting)

**Status**: Not configured (using log driver)

**.env Configuration**:
```env
BROADCAST_CONNECTION=log
PUSHER_APP_ID=
PUSHER_APP_KEY=
PUSHER_APP_SECRET=
PUSHER_APP_CLUSTER=
```

**When Ready to Configure**:
1. Sign up at https://pusher.com
2. Create new app
3. Copy credentials to .env
4. Change `BROADCAST_CONNECTION=pusher`
5. Install pusher-php-server package if not already installed

---

## Third-Party Integrations

### Status

**Currently**: No third-party integrations configured

**Future Integrations May Include**:
- Payment gateways (Stripe, PayPal)
- SMS providers (Twilio, Vonage)
- Cloud storage (AWS S3, DigitalOcean Spaces)
- Analytics (Google Analytics, Mixpanel)
- Error tracking (Sentry, Bugsnag)
- Monitoring (New Relic, Datadog)

**When Adding Credentials**:
1. Add to this document
2. Add to .env (never commit)
3. Update .env.example with placeholder (without actual value)
4. Document in [02-CONFIGURATIONS.md](02-CONFIGURATIONS.md)

---

## SSL/TLS Certificates

### Status

**Certbot Installed**: Yes (version 2.x)
**Certificates Configured**: No (pending)

**When Configuring**:

1. **Obtain Certificate**:
   ```bash
   # For domain
   sudo certbot certonly --nginx -d your-domain.com -d www.your-domain.com

   # Or for standalone (nginx not running)
   sudo certbot certonly --standalone -d your-domain.com
   ```

2. **Certificate Locations**:
   ```
   Certificate: /etc/letsencrypt/live/your-domain.com/fullchain.pem
   Private Key: /etc/letsencrypt/live/your-domain.com/privkey.pem
   ```

3. **Auto-Renewal**:
   ```bash
   # Test renewal
   sudo certbot renew --dry-run

   # Certbot sets up auto-renewal via systemd timer
   systemctl status certbot.timer
   ```

4. **Update Nginx Configuration**:
   - Edit `docker/nginx/app.prod.conf`
   - Uncomment HTTPS server block
   - Update paths to certificates
   - Restart nginx: `docker-compose -f docker-compose.prod.yml restart nginx`

**Certificate Credentials**: None (Let's Encrypt is free and automated)

---

## Docker Registry Credentials

### Status

**Currently**: Using public Docker Hub images (no authentication needed)

**Images Used**:
- `php:8.2-fpm-alpine` - Official PHP image
- `nginx:1.25-alpine` - Official Nginx image
- `mysql:8.0` - Official MySQL image
- `redis:7.2-alpine` - Official Redis image

**Custom Image**:
- `paradocks-app:latest` - Built locally (no registry push)

**If Using Private Registry** (future):
```bash
# Login to private registry
docker login registry.example.com
# Username: your-username
# Password: your-password

# Update docker-compose.prod.yml image paths
# image: registry.example.com/paradocks-app:latest
```

---

## Backup Credentials

### Status

**Backup System**: Not implemented yet (pending)

**Future Backup Configuration**:

When implementing backups, you may need credentials for:

1. **Cloud Storage** (AWS S3, Backblaze B2, etc.)
   ```env
   AWS_ACCESS_KEY_ID=
   AWS_SECRET_ACCESS_KEY=
   AWS_DEFAULT_REGION=
   AWS_BUCKET=
   ```

2. **External Backup Server** (SFTP, FTP, etc.)
   ```
   Backup Server: backup.example.com
   Username: paradocks-backup
   Password: (to be generated)
   ```

3. **Encryption** (if encrypting backups)
   ```
   GPG Key ID: (to be generated)
   Passphrase: (to be stored securely)
   ```

See: [07-NEXT-STEPS.md](07-NEXT-STEPS.md) for backup implementation plan

---

## Credential Management Best Practices

### Password Generation

**Recommended Method**:
```bash
# Generate 32-character random password
openssl rand -base64 32

# Or using pwgen (if installed)
pwgen -s 32 1

# Or using LastPass/1Password/Bitwarden password generator
```

**Password Strength Requirements**:
- Minimum 16 characters for service passwords
- Minimum 12 characters for user passwords
- Mix of uppercase, lowercase, numbers, symbols
- No dictionary words
- Unique (not reused across services)

### Credential Rotation Schedule

**Recommended Rotation**:
| Credential Type | Rotation Frequency | Priority |
|----------------|-------------------|----------|
| Admin passwords | 90 days | High |
| Database passwords | 180 days | Medium |
| Redis password | 180 days | Medium |
| API keys | 180 days or on breach | High |
| SSH keys | Annually or on breach | High |
| SSL certificates | Auto-renewed (90 days) | Critical |

### Credential Storage

**DO**:
- Store in secure password manager (LastPass, 1Password, Bitwarden)
- Use this file (not committed to Git) for server reference
- Encrypt backups of this file
- Use SSH keys instead of passwords where possible
- Use environment variables for application secrets

**DON'T**:
- Commit credentials to Git
- Share via email or unencrypted chat
- Hardcode in application code
- Write on sticky notes or unencrypted text files
- Use same password across multiple services

### In Case of Credential Compromise

**Immediate Actions**:

1. **Change the compromised credential immediately**
2. **Review access logs** for unauthorized access
3. **Notify team members**
4. **Rotate related credentials** (defense in depth)
5. **Document the incident** in deployment log
6. **Review security procedures** to prevent recurrence

**Example - Database Password Compromised**:

```bash
# 1. Generate new password
NEW_PASSWORD=$(openssl rand -base64 32)

# 2. Change in MySQL
docker-compose -f docker-compose.prod.yml exec mysql mysql -u root -p
ALTER USER 'paradocks'@'%' IDENTIFIED BY '$NEW_PASSWORD';
FLUSH PRIVILEGES;

# 3. Update .env
vim .env
# Update DB_PASSWORD=

# 4. Restart application
docker-compose -f docker-compose.prod.yml restart app horizon scheduler

# 5. Document in this file and deployment log
# 6. Review MySQL access logs
docker-compose -f docker-compose.prod.yml exec mysql mysql -u root -p
SELECT * FROM mysql.general_log WHERE user_host LIKE '%paradocks%' ORDER BY event_time DESC LIMIT 100;
```

---

## Credential Change History

| Date | Credential | Action | Reason |
|------|-----------|--------|--------|
| 2025-11-11 | All | Initial generation | First deployment |
| 2025-11-11 | MySQL paradocks user | Manual password reset | Authentication failure after container creation |

---

## Quick Reference Card

**Copy this section to your password manager**

```
Server: 72.60.17.138 (paradocks.pl, old: srv1117368.hstgr.cloud)
SSH: ssh ubuntu@72.60.17.138

MySQL paradocks user: ENDnAJtD+RtLl88WE0K1UUT/lSlQ6YYk
MySQL root: SO0I1JLL4/Sgn5NIpSyf0N0VKYB9EsHq
Redis: bt3mHr07Im0AVS3Jau851E1KsimlHf02

Admin Email: admin@paradocks.com
Admin Password: Admin123! (CHANGE IMMEDIATELY)

Project Path: /var/www/paradocks
.env Location: /var/www/paradocks/.env
```

---

**Document Maintainer**: DevOps/Security Team
**Last Updated**: 2025-11-11
**Next Review**: 2025-12-11 (or immediately after credential rotation)

```
⚠️ REMINDER: THIS FILE MUST NEVER BE COMMITTED TO GIT ⚠️

Verify it's in .gitignore:
git check-ignore -v docs/environments/staging/03-CREDENTIALS.md

If not ignored, add to .gitignore immediately:
echo "docs/environments/*/03-CREDENTIALS.md" >> .gitignore
```

# ADR-015: Environment Configuration Separation (Local vs Production)

**Date:** 2025-12-18  
**Status:** ✅ Accepted  
**Context:** Fixed file upload issue caused by `FILESYSTEM_DISK=local` pointing to private storage

---

## Problem

File uploads were failing because:
1. `.env` had `FILESYSTEM_DISK=local`
2. `config/filesystems.php` defines `local` disk as `storage/app/private` (not publicly accessible)
3. Filament FileUpload components used default disk, so uploads went to `/private` instead of `/public`
4. Uploaded files returned 404 because they weren't in public symlink

**Error in browser console:**
```
GET https://registro.local:8444/storage/pages/hero/xyz.jpg → 404 Not Found
```

**Root cause:**
- Default disk `local` → `storage/app/private` ❌
- Should use `public` disk → `storage/app/public` ✓

---

## Decision

### 1. Fixed Immediate Issue

Changed `.env`:
```bash
# Before
FILESYSTEM_DISK=local

# After
FILESYSTEM_DISK=public
```

### 2. Created Environment-Specific Templates

**`.env.local.example`** (development):
- `APP_ENV=local`
- `APP_DEBUG=true`
- `FILESYSTEM_DISK=public`
- `MAIL_MAILER=log` (no real emails sent)
- `CACHE_STORE=database` (simpler for dev)
- `LOG_LEVEL=debug`

**`.env.production.example`** (production):
- `APP_ENV=production`
- `APP_DEBUG=false`
- `FILESYSTEM_DISK=public` (or `s3` for cloud storage)
- `MAIL_MAILER=smtp` (real Gmail SMTP)
- `CACHE_STORE=redis` (faster)
- `LOG_LEVEL=error`
- `SESSION_DOMAIN=.hstgr.cloud`

### 3. Filesystem Disk Configuration

`config/filesystems.php` has 3 disks:

| Disk | Root | Visibility | Use Case |
|------|------|------------|----------|
| `local` | `storage/app/private` | Private | User data, exports, temp files |
| `public` | `storage/app/public` | Public | Uploaded images, avatars, files |
| `s3` | AWS S3 bucket | Public | Cloud storage (optional) |

**Default disk:** Set via `FILESYSTEM_DISK` in `.env`

---

## Implementation

### Local Development Setup

```bash
# Use local storage (public disk)
cp .env.local.example .env
php artisan key:generate
php artisan storage:link
```

### Production Setup (Option 1: Local Storage)

```bash
cp .env.production.example .env
# Edit .env with production credentials
php artisan key:generate
php artisan storage:link
```

### Production Setup (Option 2: AWS S3)

```bash
cp .env.production.example .env
# Configure AWS credentials
FILESYSTEM_DISK=s3
AWS_ACCESS_KEY_ID=your-key
AWS_SECRET_ACCESS_KEY=your-secret
AWS_DEFAULT_REGION=eu-central-1
AWS_BUCKET=registro-uploads
```

Install S3 driver:
```bash
composer require league/flysystem-aws-s3-v3 "^3.0"
```

---

## Consequences

### ✅ Benefits

1. **File uploads work correctly** - images accessible via `/storage` URL
2. **Environment separation** - clear distinction between local/production configs
3. **Flexibility** - can switch to S3 on production without code changes
4. **Security** - private files stay in `storage/app/private`, public in `storage/app/public`
5. **Documentation** - `.env.*.example` files serve as configuration reference

### ⚠️ Considerations

1. **Manual sync required** - when adding new env vars, update both `.example` files
2. **Migration needed** - existing production servers need `.env` update
3. **S3 costs** - if using cloud storage, monitor AWS billing
4. **Backup strategy** - `storage/app/public` must be backed up if using local disk

---

## Testing

```bash
# Test file upload (local)
1. Go to /admin/pages/1/edit
2. Upload image in hero block
3. Save page
4. Visit / and verify image displays

# Test in production
1. Upload file via Filament
2. Check URL: https://srv1117368.hstgr.cloud/storage/pages/hero/xyz.jpg
3. Verify 200 response (not 404)
```

---

## Related Files

- `.env.local.example` - Local development template
- `.env.production.example` - Production deployment template
- `config/filesystems.php` - Disk configuration
- `app/Providers/AppServiceProvider.php` - Service container bindings

---

## References

- Laravel Filesystems: https://laravel.com/docs/12.x/filesystem
- Filament FileUpload: https://filamentphp.com/docs/4.x/forms/fields/file-upload
- AWS S3 Laravel Integration: https://laravel.com/docs/12.x/filesystem#s3-driver-configuration

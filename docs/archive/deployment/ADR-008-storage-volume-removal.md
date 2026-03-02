# ADR-008: Storage Volume Removal - Use Bind Mounts Instead

**Status**: Accepted and Implemented
**Date**: 2025-11-11
**Decision Makers**: DevOps Team
**Environment**: Staging VPS (72.60.17.138)
**Technical Story**: Permission issues preventing application from writing to storage directory

---

## Context

During the initial deployment, the application was unable to write to the `storage/` directory, resulting in errors when trying to write logs, cache files, sessions, and uploaded files.

### The Problem

**Initial docker-compose.prod.yml Configuration**:

```yaml
services:
  app:
    volumes:
      - ./:/var/www/html
      - paradocks_storage:/var/www/html/storage  # Docker-managed volume
      - ./bootstrap/cache:/var/www/html/bootstrap/cache

volumes:
  paradocks_storage:
    driver: local
```

**Symptoms**:

```bash
# Application logs showed permission errors
[2025-11-11 11:00:00] production.ERROR: file_put_contents(/var/www/html/storage/logs/laravel.log): Failed to open stream: Permission denied

# Storage directory errors
Unable to create file: /var/www/html/storage/framework/cache/data/xx/xx/xxxxxxx
Permission denied: /var/www/html/storage/app/public/uploads/image.jpg

# File upload failures
Could not write to storage path
```

**Investigation Results**:

```bash
# Inside the app container (as www-data)
$ ls -la /var/www/html/storage
drwxr-xr-x 5 root root 4096 Nov 11 10:00 storage

# The volume was created with root:root ownership
# PHP-FPM runs as www-data (UID 82 in Alpine)
# www-data cannot write to root-owned directories
```

### Root Cause Analysis

**UID/GID Mismatch**:

1. **Host System**:
   - User: `ubuntu`
   - UID/GID: 1000:1000
   - `storage/` directory owned by ubuntu:ubuntu

2. **Docker Volume**:
   - Created by Docker daemon
   - Owned by root:root (0:0)
   - Permissions: 755 (drwxr-xr-x)

3. **PHP-FPM Container** (php:8.2-fpm-alpine):
   - Runs as: `www-data`
   - UID/GID: 82:82
   - Cannot write to root-owned (0:0) or ubuntu-owned (1000:1000) directories

**Why Docker Volumes Had This Issue**:

- Docker volumes are initialized empty on first creation
- Files are not copied from the image or bind mount
- Volume is created with root ownership
- No automatic UID mapping occurs
- Container user (www-data, UID 82) cannot write to root-owned files

**Why This Didn't Affect Other Directories**:

- `./:/var/www/html` - Bind mount preserves host ownership (ubuntu:ubuntu 1000:1000)
- `./bootstrap/cache:/var/www/html/bootstrap/cache` - Bind mount, same reason
- But `paradocks_storage:/var/www/html/storage` - Docker volume, root-owned

---

## Decision

**Remove Docker-managed volumes for storage directories. Use bind mounts with proper host permissions instead.**

### Final Configuration

```yaml
services:
  app:
    volumes:
      - ./:/var/www/html
      - ./storage:/var/www/html/storage              # Changed to bind mount
      - ./bootstrap/cache:/var/www/html/bootstrap/cache

# Removed volume definition entirely:
# volumes:
#   paradocks_storage:  # REMOVED
```

**Host Permissions**:

```bash
# Set correct permissions on host
chmod -R 775 storage bootstrap/cache
chown -R ubuntu:ubuntu storage bootstrap/cache
```

### Why This Solution?

1. **Simplicity**: No complex UID mapping needed
2. **Visibility**: Storage files visible and manageable on host system
3. **Backup-Friendly**: Easy to backup/restore (just copy directories)
4. **Development Parity**: Same approach works in dev and production
5. **Standard Practice**: Common pattern for Laravel in Docker

---

## Alternatives Considered

### Option A: Change Container User to UID 1000

**Description**: Modify Dockerfile to run PHP-FPM as UID 1000 instead of www-data (82).

```dockerfile
# In docker/php/Dockerfile
RUN addgroup -g 1000 appuser && adduser -u 1000 -G appuser -s /bin/sh -D appuser
USER appuser
```

**Pros**:
- ✅ Matches host UID, solves permission issue
- ✅ Can use Docker volumes
- ✅ No host permission changes needed

**Cons**:
- ❌ Non-standard (breaks Alpine PHP-FPM conventions)
- ❌ Requires custom Dockerfile modifications
- ❌ May cause issues with PHP-FPM socket permissions
- ❌ Complicates debugging (unexpected user)
- ❌ Not portable (UID 1000 might differ on other hosts)
- ❌ Requires rebuilding image for each environment

**Verdict**: ❌ Rejected - Too much customization, not portable

---

### Option B: Entrypoint Script with chown

**Description**: Add entrypoint script that fixes permissions on container startup.

```bash
#!/bin/sh
# entrypoint.sh

# Fix permissions on every start
chown -R www-data:www-data /var/www/html/storage
chown -R www-data:www-data /var/www/html/bootstrap/cache

# Start PHP-FPM
php-fpm
```

```yaml
# docker-compose.prod.yml
app:
  entrypoint: ["/entrypoint.sh"]
  volumes:
    - ./entrypoint.sh:/entrypoint.sh
    - paradocks_storage:/var/www/html/storage
```

**Pros**:
- ✅ Can use Docker volumes
- ✅ Automated permission fix
- ✅ Works with any UID mismatch

**Cons**:
- ❌ Runs chown on every container start (slow for many files)
- ❌ Adds complexity (entrypoint script to maintain)
- ❌ Delays container startup
- ❌ Still requires volume for persistence
- ❌ Wastes I/O on every restart
- ❌ Doesn't fix the root cause

**Verdict**: ❌ Rejected - Workaround, not a solution. Performance impact.

---

### Option C: Use Bind Mounts with Host Permissions (CHOSEN)

**Description**: Remove Docker volumes, use bind mounts, set proper permissions on host.

```yaml
# docker-compose.prod.yml
app:
  volumes:
    - ./storage:/var/www/html/storage
    - ./bootstrap/cache:/var/www/html/bootstrap/cache
```

```bash
# On host
chmod -R 775 storage bootstrap/cache
chown -R ubuntu:ubuntu storage bootstrap/cache
```

**Container Access**:
- Container sees files as owned by ubuntu:ubuntu (1000:1000)
- PHP-FPM (www-data, UID 82) can write because:
  - Permissions are 775 (world-writable group)
  - Docker bind mounts allow access

**Pros**:
- ✅ Simple and straightforward
- ✅ No Dockerfile changes needed
- ✅ No entrypoint scripts needed
- ✅ Fast (no chown on startup)
- ✅ Files visible on host for debugging
- ✅ Easy to backup (just copy directories)
- ✅ Works in dev and production
- ✅ Standard Laravel Docker pattern
- ✅ No performance overhead

**Cons**:
- ⚠️ Requires setting host permissions (one-time setup)
- ⚠️ Host user must have write access to storage/
- ⚠️ Less isolation (files accessible from host)

**Verdict**: ✅ ACCEPTED - Best balance of simplicity and functionality

---

### Option D: Named Volumes with Custom Initialization

**Description**: Use Docker volumes but initialize them with correct permissions.

```yaml
volumes:
  paradocks_storage:
    driver: local
    driver_opts:
      type: none
      o: bind
      device: /var/www/paradocks/storage
```

**Pros**:
- ✅ Uses named volumes (more Docker-native)
- ✅ Explicit volume management

**Cons**:
- ❌ Still requires host permissions
- ❌ More complex configuration
- ❌ No real advantage over bind mounts
- ❌ Less portable (hardcoded paths)

**Verdict**: ❌ Rejected - Added complexity without benefits

---

### Option E: Copy Files to Volume in Dockerfile

**Description**: Copy storage structure to volume during image build.

```dockerfile
# Dockerfile
COPY --chown=www-data:www-data storage /var/www/html/storage
```

**Pros**:
- ✅ Bakes structure into image
- ✅ Ownership set correctly in image

**Cons**:
- ❌ Doesn't work with volumes (volumes override image contents)
- ❌ Would need to copy on every container start (back to Option B)
- ❌ Log files in image (bad practice)
- ❌ Cannot rebuild image without losing data

**Verdict**: ❌ Rejected - Doesn't work with volumes

---

## Implementation

### Changes Made

**1. Modified docker-compose.prod.yml**:

```yaml
# BEFORE
services:
  app:
    volumes:
      - ./:/var/www/html
      - paradocks_storage:/var/www/html/storage
      - ./bootstrap/cache:/var/www/html/bootstrap/cache

volumes:
  paradocks_storage:
    driver: local

# AFTER
services:
  app:
    volumes:
      - ./:/var/www/html
      - ./storage:/var/www/html/storage              # Changed to bind mount
      - ./bootstrap/cache:/var/www/html/bootstrap/cache

# Removed volumes section entirely
```

**2. Set Host Permissions**:

```bash
cd /var/www/paradocks

# Set permissions for storage directories
chmod -R 775 storage
chmod -R 775 bootstrap/cache

# Set ownership to host user
chown -R ubuntu:ubuntu storage
chown -R ubuntu:ubuntu bootstrap/cache

# Verify
ls -la storage/
# drwxrwxr-x 8 ubuntu ubuntu 4096 Nov 11 12:00 storage
```

**3. Recreated Containers**:

```bash
# Stop and remove old containers
docker-compose -f docker-compose.prod.yml down

# Remove old volume (if it exists)
docker volume rm paradocks_storage || true

# Start with new configuration
docker-compose -f docker-compose.prod.yml up -d
```

### Verification

```bash
# Test log writing
docker-compose -f docker-compose.prod.yml exec app php artisan tinker
>>> \Log::info('Test log entry after fix');
>>> exit

# Verify log file created
cat storage/logs/laravel.log | tail -1
# [2025-11-11 12:15:00] production.INFO: Test log entry after fix

# Check permissions inside container
docker-compose -f docker-compose.prod.yml exec app ls -la /var/www/html/storage
# drwxrwxr-x 8 1000 1000 4096 Nov 11 12:00 storage

# Test cache writing
docker-compose -f docker-compose.prod.yml exec app php artisan cache:clear
# Cache cleared successfully

# Test file upload (via application)
# Upload test file via admin panel
# Verify file appears in storage/app/public/
ls -la storage/app/public/
# -rw-rw-r-- 1 ubuntu ubuntu 12345 Nov 11 12:20 test-upload.jpg
```

---

## Consequences

### Positive Consequences

1. **Problem Solved**:
   - ✅ Application can write logs
   - ✅ Cache works correctly
   - ✅ File uploads work
   - ✅ Sessions work (Redis-based, but could use file)

2. **Operational Benefits**:
   - ✅ Easy to inspect files on host
   - ✅ Simple to backup (just copy directories)
   - ✅ Easy to restore from backup
   - ✅ No container rebuild needed for storage
   - ✅ Debugging easier (tail logs from host)

3. **Development Parity**:
   - ✅ Same approach works locally and in production
   - ✅ No environment-specific configurations
   - ✅ Developers understand the setup

4. **Simplicity**:
   - ✅ No custom Dockerfile needed
   - ✅ No entrypoint scripts
   - ✅ No UID mapping complexity
   - ✅ Standard Docker Compose configuration

### Negative Consequences

1. **Less Isolation**:
   - ⚠️ Storage files accessible from host
   - ⚠️ Host user can modify application files
   - **Mitigation**: This is staging/development, not a major concern. For production, can implement stricter controls.

2. **Permission Management**:
   - ⚠️ Must set permissions correctly on host
   - ⚠️ After Git clone, must run chmod/chown
   - ⚠️ Permission issues if wrong user manipulates files
   - **Mitigation**: Document in deployment checklist, add to setup scripts

3. **Portability Considerations**:
   - ⚠️ Assumes UID 1000 exists on host
   - ⚠️ Might need adjustment for different host users
   - **Mitigation**: Standard UID on Ubuntu. Document for other systems.

### Neutral Consequences

1. **Deployment Process**:
   - Need to ensure storage/ exists and has correct permissions
   - One-time setup step per environment
   - Already part of Laravel deployment best practices

2. **Backup Strategy**:
   - Storage directory must be included in backups
   - Simple directory copy sufficient
   - No Docker volume backup complexity

---

## Best Practices Established

### For New Environments

When deploying to a new environment:

```bash
# 1. Clone repository
git clone <repo> /var/www/paradocks
cd /var/www/paradocks

# 2. Set permissions BEFORE starting containers
chmod -R 775 storage bootstrap/cache
chown -R $USER:$USER storage bootstrap/cache

# 3. Start containers
docker-compose -f docker-compose.prod.yml up -d

# 4. Verify permissions work
docker-compose -f docker-compose.prod.yml exec app php artisan about
# Should show no errors
```

### Storage Directory Structure

Ensure these directories exist with correct permissions:

```
storage/
├── app/
│   └── public/          # 775, ubuntu:ubuntu
├── framework/
│   ├── cache/          # 775, ubuntu:ubuntu
│   ├── sessions/       # 775, ubuntu:ubuntu (if using file sessions)
│   ├── testing/        # 775, ubuntu:ubuntu
│   └── views/          # 775, ubuntu:ubuntu
└── logs/               # 775, ubuntu:ubuntu
```

### Maintenance

```bash
# Periodically check permissions haven't changed
ls -la storage/
# Should show: drwxrwxr-x ubuntu ubuntu

# Fix if needed
chmod -R 775 storage bootstrap/cache
chown -R ubuntu:ubuntu storage bootstrap/cache
```

---

## Lessons Learned

1. **Docker Volumes vs Bind Mounts**:
   - Volumes: Better for database data (managed by Docker)
   - Bind Mounts: Better for application data (needs host access)
   - Choice depends on use case, not one-size-fits-all

2. **Permission Planning**:
   - Always consider UID/GID mismatches in containers
   - Test write access before deployment
   - Document permission requirements

3. **Simplicity First**:
   - Don't over-engineer solutions
   - Bind mounts are simpler than volume + permission fixes
   - Standard patterns exist for a reason

4. **Development/Production Parity**:
   - Use same volume strategy in dev and prod
   - Reduces surprises during deployment
   - Makes debugging easier

---

## Future Considerations

### For Production

**Current approach is fine for production**, but consider:

1. **S3 for Uploads** (future):
   - Move uploaded files to S3 or similar
   - Reduces local storage needs
   - Better scalability
   - Don't need to backup uploads separately

2. **Separate Logs** (future):
   - Send logs to centralized logging (ELK, Papertrail)
   - Reduces local storage usage
   - Better log analysis

3. **Ephemeral Containers** (advanced):
   - If truly stateless, could use tmpfs for cache
   - Logs go to stdout/stderr → Docker logs
   - Sessions in Redis (already done)
   - Uploads in S3

### For Multi-Server Setup

If scaling to multiple servers:

1. **Shared Storage**:
   - Use NFS mount for storage/app/public/
   - Or move entirely to S3
   - Can't use local bind mounts

2. **Session Management**:
   - Already using Redis (good!)
   - No file-based sessions needed

---

## References

- **Docker Volumes Documentation**: https://docs.docker.com/storage/volumes/
- **Docker Bind Mounts**: https://docs.docker.com/storage/bind-mounts/
- **Laravel Storage**: https://laravel.com/docs/filesystem
- **Docker Compose Volumes**: https://docs.docker.com/compose/compose-file/#volumes
- **Deployment Log**: [../../environments/staging/01-DEPLOYMENT-LOG.md](../../environments/staging/01-DEPLOYMENT-LOG.md#issue-2-storage-volume-permission-issues)
- **Issues & Workarounds**: [../../environments/staging/05-ISSUES-WORKAROUNDS.md](../../environments/staging/05-ISSUES-WORKAROUNDS.md#issue-2-storage-volume-permission-issues)

---

## Related ADRs

- Related to future decisions about S3 storage (not yet created)
- Related to logging strategy (not yet created)

---

**Author**: DevOps Team
**Reviewers**: Development Team
**Approved**: 2025-11-11
**Implementation**: 2025-11-11 (during initial deployment)
**Last Updated**: 2025-11-11

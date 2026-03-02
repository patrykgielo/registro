# ADR-021: Staging File Permissions Issue

## Status
Resolved (2026-01-19)

## Context

CI/CD staging deployment failed with error:
```
curl: (23) Failure writing output to destination
```

The workflow downloads configuration files to the staging server:
```bash
curl -o docker-compose.staging.yml "https://..."
curl -o docker/nginx/app.staging.conf "https://..."
```

## Investigation

1. **Initial assumption**: Disk full or directory permissions
2. **Actual cause**: Individual files owned by `root:root` instead of `deploy:deploy`

```bash
# Problem state:
-rw-r--r-- 1 root   root   5394 Jan 18 22:25 app.staging.conf
-rw-r--r-- 1 root   root   6077 Jan 18 22:25 app.prod.conf

# Expected state:
-rw-r--r-- 1 deploy deploy 5394 Jan 18 22:25 app.staging.conf
```

## Root Cause

Someone (human or AI assistant) executed commands as `root` instead of `deploy` user:
- SSH as root and ran curl/nano
- Used `sudo` without `-u deploy`
- Copied files as root

This violated the principle established in ADR-020 (deploy user migration).

## Resolution

```bash
ssh root@staging "chown deploy:deploy /var/www/paradocks/docker/nginx/app.*.conf"
```

## Decision

### Rules for Server Operations

1. **NEVER SSH as root for application operations**
   ```bash
   # WRONG
   ssh root@45.93.138.193 "curl -o /var/www/paradocks/file.txt ..."

   # CORRECT
   ssh deploy@45.93.138.193 "curl -o /var/www/paradocks/file.txt ..."
   ```

2. **If root is required, restore ownership**
   ```bash
   ssh root@server "command && chown -R deploy:deploy /var/www/paradocks"
   ```

3. **Periodic ownership audit**
   ```bash
   # Find files NOT owned by deploy in app directory
   find /var/www/paradocks -not -user deploy -not -path "*/vendor/*" -ls
   ```

### CI/CD Safeguard

Add ownership verification step to deployment:
```yaml
- name: Verify file ownership
  run: |
    ssh deploy@server "find /var/www/paradocks -maxdepth 2 -not -user deploy -ls"
```

## Consequences

### Positive
- Documented the issue for future reference
- Added rules to prevent recurrence
- CI/CD now works correctly

### Negative
- Lost ~30 minutes debugging
- Required root access to fix

## Related
- ADR-020: Staging Deploy User Migration
- `.claude/rules/staging-deployment.md`
- `.claude/rules/deployment.md`

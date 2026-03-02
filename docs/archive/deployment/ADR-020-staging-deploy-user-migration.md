# ADR-020: Staging Server Deploy User Migration

**Status**: ✅ Implemented
**Date**: 2026-01-14
**Author**: Claude Code + DevOps Team

---

## Context

Staging server (45.93.138.193) was initially configured with `root` user for deployments, violating security best practices and the least privilege principle. Production server (72.60.17.138) correctly uses a dedicated `deploy` user (uid=1002).

### Security Issues with Root Deployment

1. **Excessive Privileges**: Root access provides unnecessary system-wide control
2. **Audit Trail**: Difficult to distinguish deployment actions from system maintenance
3. **Blast Radius**: Compromised deployment credentials = full system compromise
4. **Inconsistency**: Staging and production environments differ, reducing test reliability
5. **Compliance**: Violates industry standards (PCI DSS, SOC 2, ISO 27001)

### Production Setup (Correct Baseline)

- User: `deploy` (uid=1002, gid=1002)
- Groups: `sudo`, `docker`
- SSH: Dedicated ed25519 key
- Sudoers: Passwordless sudo for deployment automation
- GitHub Secret: `VPS_USER` = `deploy`

### Staging Setup (Current - Incorrect)

- User: `root` (uid=0)
- SSH: Root RSA key
- GitHub Secret: `STAGING_VPS_USER` = `root`

---

## Decision

**Migrate staging server to use dedicated `deploy` user matching production configuration.**

### Implementation Steps

1. Create `deploy` user (uid=1002) on staging server
2. Configure groups: `sudo`, `docker`
3. Generate new ed25519 SSH key pair
4. Transfer project ownership to `deploy:deploy`
5. Update GitHub Secrets
6. Verify CI/CD pipeline functionality

---

## Implementation Details

### Server-Side Configuration

```bash
# User creation
useradd --uid 1002 --create-home --shell /bin/bash deploy
usermod -aG sudo,docker deploy

# Sudoers configuration (passwordless for CI/CD)
echo "deploy ALL=(ALL) NOPASSWD:ALL" > /etc/sudoers.d/deploy
chmod 0440 /etc/sudoers.d/deploy

# SSH setup
mkdir -p /home/deploy/.ssh
chmod 700 /home/deploy/.ssh
# (Public key installation)
chmod 600 /home/deploy/.ssh/authorized_keys
chown -R deploy:deploy /home/deploy/.ssh

# Ownership transfer
chown -R deploy:deploy /var/www/paradocks/
```

### SSH Key Generation (Local)

```bash
ssh-keygen -t ed25519 -C "deploy@staging-paradocks" \
  -f ~/.ssh/staging_paradocks_deploy_ed25519
```

### GitHub Secrets Update

- `STAGING_VPS_USER`: `root` → `deploy`
- `STAGING_VPS_SSH_KEY`: Old RSA → New ed25519 private key

### Verification

```bash
# SSH connection test
ssh -i ~/.ssh/staging_paradocks_deploy_ed25519 deploy@45.93.138.193 'id'

# Docker access test
ssh -i ~/.ssh/staging_paradocks_deploy_ed25519 deploy@45.93.138.193 'docker ps'

# Write permission test
ssh -i ~/.ssh/staging_paradocks_deploy_ed25519 deploy@45.93.138.193 \
  'cd /var/www/paradocks && touch test && rm test'

# CI/CD test
# Trigger manual workflow: .github/workflows/deploy-staging.yml
```

---

## Consequences

### Positive

1. **Security Hardening**: Least privilege principle enforced
2. **Consistency**: Staging matches production configuration
3. **Auditability**: Clear separation of deployment vs. system operations
4. **Modern Cryptography**: ed25519 vs. RSA (smaller keys, faster, more secure)
5. **Reduced Blast Radius**: Compromised deploy key ≠ root access
6. **Compliance**: Meets industry security standards

### Negative

1. **Initial Effort**: 50-60 minutes implementation time
2. **Key Management**: One more SSH key to manage (mitigated with ~/.ssh/config)
3. **Documentation Updates**: Multiple docs require updates

### Neutral

- Zero downtime migration (no service interruption)
- Rollback plan available if issues occur
- GitHub Actions workflow unchanged (only secrets updated)

---

## Rollback Plan

### If SSH Access Fails

```bash
# 1. Access via Hostinger panel VPS console
# 2. Restore root SSH access
cp /root/.ssh/authorized_keys.backup /root/.ssh/authorized_keys

# 3. Revert GitHub secrets
# STAGING_VPS_USER = root
# STAGING_VPS_SSH_KEY = old RSA key
```

### If Permission Issues

```bash
# Restore root ownership (temporary)
chown -R root:root /var/www/paradocks/

# Or fix specific directories
chown -R deploy:deploy /var/www/paradocks/storage/
chown -R deploy:deploy /var/www/paradocks/bootstrap/cache/
```

### Complete Rollback

1. Revert GitHub secrets
2. Restore root SSH authorized_keys
3. Restore root file ownership
4. Remove deploy user: `userdel -r deploy`

---

## Security Benefits Analysis

| Attack Vector | Before (Root) | After (Deploy) | Improvement |
|--------------|---------------|----------------|-------------|
| Compromised SSH key | Full system access | Limited to project + sudo | High |
| Container escape | Root privileges | Deploy user privileges | Medium |
| Malicious deployment | System-wide impact | Project-scope impact | High |
| Lateral movement | Direct root access | Requires privilege escalation | Medium |
| Audit trail | Mixed root actions | Clear deployment actions | High |

---

## Compliance Mapping

- **PCI DSS 7.1**: Limit access to system components to least privilege
- **PCI DSS 8.1**: Assign unique ID to each user (deploy ≠ root)
- **SOC 2 CC6.3**: Logical access controls include least privilege
- **ISO 27001 A.9.2**: User access management with appropriate access rights
- **CIS Benchmark 5.3.4**: Limit use of privileged accounts

---

## Related Documentation

- [Production Server Info](/docs/environments/production/00-SERVER-INFO.md) - Correct deploy user setup
- [Staging Server Info](/docs/environments/staging/00-SERVER-INFO.md) - Updated post-migration
- [Git Workflow](/docs/deployment/GIT_WORKFLOW.md) - CI/CD pipeline
- [ADR-001: UFW-Docker Security](/docs/decisions/ADR-001-ufw-docker-security.md) - Related security hardening

---

## Implementation Timeline

| Phase | Duration | Status |
|-------|----------|--------|
| Planning & Documentation | 1h | ✅ Complete |
| Server-side setup | 30m | ✅ Complete |
| SSH key generation | 5m | ✅ Complete |
| GitHub secrets update | 5m | ✅ Complete |
| Verification & testing | 20m | ✅ Complete |
| Documentation update | 10m | ✅ Complete |

**Total**: ~50 minutes
**Implemented**: 2026-01-14 23:15 CET

---

## Post-Migration Checklist

- [x] Deploy user created (uid=1002)
- [x] Groups configured (sudo, docker)
- [x] SSH key generated (ed25519): `~/.ssh/id_ed25519_staging_deploy`
- [x] Public key installed on server
- [x] File ownership transferred
- [x] GitHub secrets updated (STAGING_VPS_USER, STAGING_VPS_SSH_KEY)
- [x] Manual SSH test passed
- [x] GitHub Actions deployment test passed (run #21011812310)
- [x] Application health check passed (HTTP 200)
- [x] Container status verified (all healthy)
- [x] Documentation updated (ADR-020)
- [ ] SSH config updated (~/.ssh/config) - optional
- [x] Old root key backup retained (`/root/backup-before-deploy-user/`)
- [ ] Team notified of new access method

---

## References

- OWASP: Principle of Least Privilege
- NIST SP 800-53: AC-6 (Least Privilege)
- CIS Controls v8: Control 5 (Account Management)
- SSH Key Types: https://www.ssh.com/academy/ssh/keygen
- Ed25519 vs RSA: https://blog.g3rt.nl/upgrade-your-ssh-keys.html

---

**Approved By**: Patryk Gielo
**Implementation Date**: 2026-01-14
**Verified By**: GitHub Actions (run #21011812310)

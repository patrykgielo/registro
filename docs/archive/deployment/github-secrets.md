# GitHub Secrets Setup Guide

**Version:** 1.0.0
**Last Updated:** November 2025
**Repository:** github.com/patrykgielo/paradocks
**Prerequisites:** Repository admin access, VPS setup completed

---

## Table of Contents

1. [Overview](#overview)
2. [Required Secrets](#required-secrets)
3. [Step-by-Step Configuration](#step-by-step-configuration)
4. [Environment Protection Rules](#environment-protection-rules)
5. [Testing Secrets](#testing-secrets)
6. [Security Best Practices](#security-best-practices)
7. [Troubleshooting](#troubleshooting)
8. [Rotating Secrets](#rotating-secrets)

---

## Overview

GitHub Secrets store sensitive credentials needed for CI/CD deployments. Secrets are:

- **Encrypted at rest** - AES-256 encryption
- **Encrypted in transit** - TLS 1.2+
- **Masked in logs** - Never appear in GitHub Actions logs
- **Scoped to repository** - Cannot be accessed by other repos
- **Environment-specific** - Can be scoped to production, staging, etc.

**Required Secrets:**
1. `VPS_HOST` - VPS IP address
2. `VPS_USER` - Deploy username (deploy)
3. `VPS_SSH_KEY` - Private SSH key (ed25519)
4. `VPS_PORT` - SSH port (default: 22)
5. `GHCR_TOKEN` - GitHub Personal Access Token for GHCR

---

## Required Secrets

### VPS_HOST

**Description:** IP address or domain of production VPS
**Value:** `72.60.17.138`
**Format:** IP address or domain (no protocol, no port)
**Example:**
```
72.60.17.138
```

---

### VPS_USER

**Description:** Username for SSH deployments
**Value:** `deploy`
**Format:** Linux username (lowercase, alphanumeric)
**Example:**
```
deploy
```

**Note:** This user must be created on VPS with:
- Docker group membership
- Sudo privileges
- SSH key authentication configured

---

### VPS_SSH_KEY

**Description:** Private SSH key for authentication (ed25519)
**Value:** Contents of `~/.ssh/paradocks_deploy_ed25519` (private key)
**Format:** Multi-line ed25519 private key
**Example:**
```
-----BEGIN OPENSSH PRIVATE KEY-----
b3BlbnNzaC1rZXktdjEAAAAABG5vbmUAAAAEbm9uZQAAAAAAAAABAAAAMwAAAAtzc2gtZW
QyNTUxOQAAACBr8VPE0kN2x5j9YzqS3LKvU4O5F5r2E2p9y8h7W3e4MwAAAJjK5X5YyuV+
WAAAAAtzc2gtZWQyNTUxOQAAACBr8VPE0kN2x5j9YzqS3LKvU4O5F5r2E2p9y8h7W3e4Mw
AAAEDU7h2vZ9x3K2p8L4m9Y6e3F1r5h4N2j8W9x5O3p7K4r2vyU8TSQ3bHmP1jOpLcsq9T
g7kXmvYTan3LyHtbd7gzAAAAGGRlcGxveUBwYXJhZG9ja3MtdnBzAQIDBAUG
-----END OPENSSH PRIVATE KEY-----
```

**How to Get:**
```bash
# Display private key (from your local machine)
cat ~/.ssh/paradocks_deploy_ed25519

# Copy ENTIRE output including BEGIN/END lines
```

**Security:**
- ⚠️ **NEVER** commit this to Git
- ⚠️ **NEVER** share in Slack/email
- ⚠️ Store only in GitHub Secrets
- ⚠️ Use passphrase-less key for automation

---

### VPS_PORT

**Description:** SSH port for VPS connection
**Value:** `22` (default SSH port)
**Format:** Integer (1-65535)
**Example:**
```
22
```

**Custom Port:**
If your VPS uses a non-standard SSH port (e.g., 2222 for security):
```
2222
```

---

### GHCR_TOKEN

**Description:** GitHub Personal Access Token for GHCR authentication
**Value:** Generated from https://github.com/settings/tokens
**Format:** `ghp_` prefix followed by 36 characters
**Example:**
```
ghp_1234567890abcdefghijklmnopqrstuvwxyz
```

**How to Generate:**

1. **Navigate to GitHub Settings:**
   - URL: https://github.com/settings/tokens
   - Click: "Generate new token" → "Generate new token (classic)"

2. **Configure Token:**
   - **Note:** "Paradocks CI/CD - GHCR Access"
   - **Expiration:** 90 days (recommended)
   - **Scopes:** Select:
     - ✅ `write:packages` - Push Docker images to GHCR
     - ✅ `read:packages` - Pull Docker images from GHCR
     - ✅ `delete:packages` - Delete old images (optional)

3. **Generate and Copy:**
   - Click "Generate token"
   - **Copy immediately** (only shown once)
   - Save in password manager

**Token Rotation:**
- Rotate every 90 days (set calendar reminder)
- Update GitHub Secret before expiration
- Test deployment after rotation

---

## Step-by-Step Configuration

### Step 1: Navigate to Repository Secrets

1. Go to repository: https://github.com/patrykgielo/paradocks
2. Click: **Settings** tab
3. Sidebar: **Secrets and variables** → **Actions**

### Step 2: Add Repository Secrets

Click **"New repository secret"** for each secret:

#### Add VPS_HOST

1. **Name:** `VPS_HOST`
2. **Value:** `72.60.17.138`
3. Click: **Add secret**

#### Add VPS_USER

1. **Name:** `VPS_USER`
2. **Value:** `deploy`
3. Click: **Add secret**

#### Add VPS_SSH_KEY

1. **Name:** `VPS_SSH_KEY`
2. **Value:** Paste entire private key content
   ```
   -----BEGIN OPENSSH PRIVATE KEY-----
   ... (entire key)
   -----END OPENSSH PRIVATE KEY-----
   ```
3. **Important:** Include BEGIN/END lines, preserve formatting
4. Click: **Add secret**

#### Add VPS_PORT

1. **Name:** `VPS_PORT`
2. **Value:** `22`
3. Click: **Add secret**

#### Add GHCR_TOKEN

1. **Name:** `GHCR_TOKEN`
2. **Value:** `ghp_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx`
3. Click: **Add secret**

### Step 3: Verify Secrets Added

You should see 5 secrets listed:

```
VPS_HOST          Updated 2 minutes ago
VPS_USER          Updated 2 minutes ago
VPS_SSH_KEY       Updated 2 minutes ago
VPS_PORT          Updated 2 minutes ago
GHCR_TOKEN        Updated 2 minutes ago
```

**Note:** Secret values are **never** displayed after creation (for security).

---

## Environment Protection Rules

### Step 4: Create Production Environment

1. **Navigate:** Settings → Environments
2. Click: **New environment**
3. **Name:** `production`
4. Click: **Configure environment**

### Step 5: Configure Protection Rules

#### Required Reviewers

1. **Enable:** "Required reviewers"
2. **Add reviewers:** Select team members (e.g., yourself, tech lead)
3. **Minimum:** 1 reviewer required
4. **Benefit:** Prevents accidental production deployments

#### Wait Timer (Optional)

1. **Enable:** "Wait timer"
2. **Minutes:** 5 minutes (gives time to cancel if needed)
3. **Benefit:** Cooling-off period before deployment

#### Deployment Branches

1. **Enable:** "Deployment branches and tags"
2. **Select:** "Selected branches and tags"
3. **Add rule:**
   - **Type:** Tags
   - **Pattern:** `v*.*.*` (semantic version tags only)
4. **Benefit:** Only tagged releases can deploy to production

### Step 6: Environment Secrets (Optional)

If you need environment-specific secrets:

1. **Environment:** production
2. **Add secret** (overrides repository secret)
3. **Example:** `DB_PASSWORD` (different for staging vs production)

---

## Testing Secrets

### Step 7: Test Secret Access

Create a test workflow to verify secrets:

**.github/workflows/test-secrets.yml:**
```yaml
name: Test Secrets

on:
  workflow_dispatch:

jobs:
  test:
    runs-on: ubuntu-latest
    steps:
      - name: Test VPS_HOST
        run: |
          if [ -z "${{ secrets.VPS_HOST }}" ]; then
            echo "❌ VPS_HOST is not set"
            exit 1
          fi
          echo "✅ VPS_HOST is set (length: ${#VPS_HOST})"
        env:
          VPS_HOST: ${{ secrets.VPS_HOST }}

      - name: Test VPS_USER
        run: |
          if [ -z "${{ secrets.VPS_USER }}" ]; then
            echo "❌ VPS_USER is not set"
            exit 1
          fi
          echo "✅ VPS_USER is set: ${{ secrets.VPS_USER }}"

      - name: Test VPS_SSH_KEY
        run: |
          if [ -z "${{ secrets.VPS_SSH_KEY }}" ]; then
            echo "❌ VPS_SSH_KEY is not set"
            exit 1
          fi
          echo "✅ VPS_SSH_KEY is set (length: ${#VPS_SSH_KEY})"
        env:
          VPS_SSH_KEY: ${{ secrets.VPS_SSH_KEY }}

      - name: Test GHCR_TOKEN
        run: |
          if [ -z "${{ secrets.GHCR_TOKEN }}" ]; then
            echo "❌ GHCR_TOKEN is not set"
            exit 1
          fi
          echo "✅ GHCR_TOKEN is set (starts with: ${GHCR_TOKEN:0:4})"
        env:
          GHCR_TOKEN: ${{ secrets.GHCR_TOKEN }}
```

**Run Test:**
1. Go to: Actions → Test Secrets
2. Click: "Run workflow"
3. Expected output: All ✅ checks pass

### Step 8: Test SSH Connection

Create a test deployment workflow:

**.github/workflows/test-ssh.yml:**
```yaml
name: Test SSH Connection

on:
  workflow_dispatch:

jobs:
  test-ssh:
    runs-on: ubuntu-latest
    steps:
      - name: Setup SSH Key
        run: |
          mkdir -p ~/.ssh
          echo "${{ secrets.VPS_SSH_KEY }}" > ~/.ssh/deploy_key
          chmod 600 ~/.ssh/deploy_key
          ssh-keyscan -p ${{ secrets.VPS_PORT }} ${{ secrets.VPS_HOST }} >> ~/.ssh/known_hosts

      - name: Test SSH Connection
        run: |
          ssh -i ~/.ssh/deploy_key \
              -p ${{ secrets.VPS_PORT }} \
              ${{ secrets.VPS_USER }}@${{ secrets.VPS_HOST }} \
              "echo '✅ SSH connection successful' && whoami && pwd"
```

**Run Test:**
1. Go to: Actions → Test SSH Connection
2. Click: "Run workflow"
3. Expected output:
   ```
   ✅ SSH connection successful
   deploy
   /home/deploy
   ```

---

## Security Best Practices

### Secret Management

- ✅ **Use separate tokens** for CI/CD vs local development
- ✅ **Rotate tokens** every 90 days
- ✅ **Use minimal scopes** (only required permissions)
- ✅ **Monitor usage** (GitHub Settings → Developer Settings → Personal Access Tokens → Activity)
- ✅ **Revoke immediately** if compromised

### SSH Key Security

- ✅ **Use ed25519 keys** (more secure than RSA)
- ✅ **One key per purpose** (separate keys for CI/CD vs manual SSH)
- ✅ **No passphrase for CI/CD keys** (automation requires passphrase-less)
- ✅ **Passphrase for manual keys** (protect your local machine)
- ✅ **Revoke old keys** when rotating

### Access Control

- ✅ **Limit repository access** (only trusted team members)
- ✅ **Enable branch protection** (require PR reviews)
- ✅ **Use environment protection** (manual approval for production)
- ✅ **Audit logs** (review Actions logs regularly)

### Monitoring

- ✅ **Review Actions logs** after each deployment
- ✅ **Check for failed jobs** (may indicate secret issues)
- ✅ **Monitor GHCR usage** (detect unauthorized pulls)
- ✅ **Set up alerts** (email notifications for failed deployments)

---

## Troubleshooting

### Secret Not Found

**Symptom:** `Error: The secret 'VPS_HOST' is not defined`

**Solution:**
1. Check secret name matches exactly (case-sensitive)
2. Verify secret is in repository settings (not organization)
3. Check environment secrets (if using environments)

### SSH Authentication Failed

**Symptom:** `Permission denied (publickey)`

**Solution:**
1. **Verify private key format:**
   ```bash
   # Should start with:
   -----BEGIN OPENSSH PRIVATE KEY-----
   ```
2. **Check newlines preserved:**
   - Copy entire key including BEGIN/END lines
   - No extra spaces or characters
3. **Test key locally:**
   ```bash
   ssh -i ~/.ssh/paradocks_deploy_ed25519 deploy@72.60.17.138
   ```

### GHCR Authentication Failed

**Symptom:** `Error: unauthorized: authentication required`

**Solution:**
1. **Verify token format:**
   - Starts with `ghp_`
   - 36 characters total length
2. **Check token scopes:**
   - Must have `write:packages` and `read:packages`
3. **Check token expiration:**
   - Go to: https://github.com/settings/tokens
   - Regenerate if expired
4. **Test token locally:**
   ```bash
   echo "ghp_xxxx..." | docker login ghcr.io -u patrykgielo --password-stdin
   ```

### Environment Protection Blocking

**Symptom:** Deployment stuck at "Waiting for approval"

**Solution:**
1. **Check reviewers:**
   - Settings → Environments → production → Required reviewers
   - Add yourself if missing
2. **Approve deployment:**
   - Actions → Running workflow → "Review deployments"
   - Select "production" → "Approve and deploy"

---

## Rotating Secrets

### When to Rotate

- ✅ **Every 90 days** (scheduled rotation)
- ✅ **On team member departure** (revoke access)
- ✅ **After suspected compromise** (immediate rotation)
- ✅ **Before major releases** (preventive measure)

### SSH Key Rotation

**Step 1: Generate New Key**
```bash
# Generate new ed25519 key
ssh-keygen -t ed25519 -C "deploy@paradocks-vps-2025" -f ~/.ssh/paradocks_deploy_ed25519_new
```

**Step 2: Add New Public Key to VPS**
```bash
# Copy new public key to VPS
ssh-copy-id -i ~/.ssh/paradocks_deploy_ed25519_new.pub deploy@72.60.17.138
```

**Step 3: Update GitHub Secret**
1. Settings → Secrets → VPS_SSH_KEY
2. Click "Update"
3. Paste new private key
4. Save

**Step 4: Test Deployment**
```bash
# Create test tag
git tag -a v1.0.0-test -m "Test new SSH key"
git push origin v1.0.0-test

# Monitor in GitHub Actions
```

**Step 5: Remove Old Key (After Verification)**
```bash
# SSH to VPS
ssh -i ~/.ssh/paradocks_deploy_ed25519_new deploy@72.60.17.138

# Edit authorized_keys
nano ~/.ssh/authorized_keys

# Remove old key line, save
# Keep only new key
```

### GHCR Token Rotation

**Step 1: Generate New Token**
1. Go to: https://github.com/settings/tokens
2. Generate new token (classic)
3. Note: "Paradocks CI/CD - GHCR Access (2025-Q1)"
4. Scopes: `write:packages`, `read:packages`
5. Copy token

**Step 2: Update GitHub Secret**
1. Settings → Secrets → GHCR_TOKEN
2. Click "Update"
3. Paste new token
4. Save

**Step 3: Update VPS GHCR Auth**
```bash
# SSH to VPS
ssh deploy@72.60.17.138

# Re-login with new token
echo "ghp_NEW_TOKEN" | docker login ghcr.io -u patrykgielo --password-stdin

# Update stored token
echo "ghp_NEW_TOKEN" > ~/.ghcr-token
chmod 600 ~/.ghcr-token
```

**Step 4: Revoke Old Token**
1. Go to: https://github.com/settings/tokens
2. Find old token
3. Click "Delete"
4. Confirm deletion

---

## Appendix

### Quick Reference

| Secret | Value | Where to Get |
|--------|-------|--------------|
| VPS_HOST | `72.60.17.138` | VPS provider dashboard |
| VPS_USER | `deploy` | VPS setup guide (created user) |
| VPS_SSH_KEY | `-----BEGIN OPENSSH...` | `cat ~/.ssh/paradocks_deploy_ed25519` |
| VPS_PORT | `22` | VPS SSH configuration |
| GHCR_TOKEN | `ghp_xxx...` | https://github.com/settings/tokens |

### Useful Commands

```bash
# List all secrets (names only, not values)
gh secret list --repo patrykgielo/paradocks

# Update a secret via CLI
gh secret set VPS_HOST --repo patrykgielo/paradocks --body "72.60.17.138"

# Delete a secret
gh secret delete VPS_HOST --repo patrykgielo/paradocks

# Test SSH key locally
ssh -i ~/.ssh/paradocks_deploy_ed25519 deploy@72.60.17.138 "echo 'SSH works'"

# Test GHCR token locally
echo "ghp_xxx" | docker login ghcr.io -u patrykgielo --password-stdin
```

---

## Next Steps

After configuring secrets:

1. **Test First Deployment**
   - Create tag: `git tag -a v1.0.0 -m "First release"`
   - Push tag: `git push origin v1.0.0`
   - Monitor: https://github.com/patrykgielo/paradocks/actions

2. **Schedule Token Rotation**
   - Calendar reminder: 90 days
   - Rotate GHCR token
   - Rotate SSH key

3. **Configure Notifications**
   - GitHub: Settings → Notifications → Actions
   - Email alerts for failed deployments
   - Slack integration (optional)

4. **Review Security**
   - Audit repository access
   - Review Actions logs
   - Check GHCR usage

---

**Document Owner:** DevOps Team
**Review Cycle:** Quarterly
**Last Review:** November 2025
**Next Review:** February 2026

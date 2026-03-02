# VPS Setup Guide for CI/CD Deployments

**Version:** 1.0.0
**Last Updated:** November 2025
**Target VPS:** 72.60.17.138
**Prerequisites:** Root or sudo access to VPS

---

## Table of Contents

1. [Overview](#overview)
2. [Create Deploy User](#create-deploy-user)
3. [SSH Key Configuration](#ssh-key-configuration)
4. [Docker Permissions](#docker-permissions)
5. [GHCR Authentication](#ghcr-authentication)
6. [Directory Structure](#directory-structure)
7. [Testing Setup](#testing-setup)
8. [Troubleshooting](#troubleshooting)

---

## Overview

This guide configures a dedicated `deploy` user on the VPS for secure, automated CI/CD deployments. The setup follows security best practices:

- **Dedicated user** - Separate from root, limited privileges
- **SSH key authentication** - No password authentication
- **Docker access** - Non-root Docker socket access
- **GHCR access** - Authenticated to GitHub Container Registry

**Architecture:**
```
GitHub Actions
    ↓ (SSH with ed25519 key)
deploy@72.60.17.138
    ↓ (docker compose commands)
Docker Containers (app, horizon, scheduler)
    ↓ (pull images)
ghcr.io/patrykgielo/paradocks:v1.2.3
```

---

## Create Deploy User

### Step 1: Create User with Home Directory

```bash
# SSH as root or user with sudo
ssh root@72.60.17.138

# Create deploy user with home directory
sudo useradd -m -s /bin/bash deploy

# Set a strong temporary password (will disable later)
sudo passwd deploy
# Enter temporary password: <strong-password>
```

**Verify:**
```bash
# Check user was created
id deploy
# Output: uid=1001(deploy) gid=1001(deploy) groups=1001(deploy)

# Check home directory exists
ls -la /home/deploy
# Output: drwxr-x--- 2 deploy deploy 4096 Nov 29 14:30 /home/deploy
```

### Step 2: Grant Sudo Privileges

```bash
# Add deploy to sudo group
sudo usermod -aG sudo deploy

# Verify sudo access
sudo -u deploy sudo whoami
# Output: root
```

**Security Note:** The `deploy` user needs sudo for:
- Docker Compose commands
- Restarting services
- File permission changes

---

## SSH Key Configuration

### Step 3: Generate SSH Key Pair (ed25519)

**Why ed25519?**
- More secure than RSA
- Faster key generation
- Smaller key size (256 bits vs 2048 bits)
- Resistant to timing attacks

**Generate on your local machine** (NOT on VPS):

```bash
# Generate ed25519 key pair
ssh-keygen -t ed25519 -C "deploy@paradocks-vps" -f ~/.ssh/paradocks_deploy_ed25519

# Enter passphrase (OPTIONAL - GitHub Actions cannot use passphrase)
# For GitHub Actions: Press Enter (no passphrase)

# Output:
# Generating public/private ed25519 key pair.
# Your identification has been saved in /home/you/.ssh/paradocks_deploy_ed25519
# Your public key has been saved in /home/you/.ssh/paradocks_deploy_ed25519.pub
```

**Files created:**
- `~/.ssh/paradocks_deploy_ed25519` - **Private key** (NEVER share, add to GitHub Secrets)
- `~/.ssh/paradocks_deploy_ed25519.pub` - **Public key** (copy to VPS)

### Step 4: Copy Public Key to VPS

**Method 1: Using ssh-copy-id (Recommended)**

```bash
# Copy public key to deploy user
ssh-copy-id -i ~/.ssh/paradocks_deploy_ed25519.pub deploy@72.60.17.138

# Enter deploy user password when prompted
```

**Method 2: Manual Copy**

```bash
# Display public key
cat ~/.ssh/paradocks_deploy_ed25519.pub

# Copy the output (entire line starting with "ssh-ed25519")

# SSH to VPS as deploy user
ssh deploy@72.60.17.138

# Create .ssh directory
mkdir -p ~/.ssh
chmod 700 ~/.ssh

# Add public key to authorized_keys
echo "ssh-ed25519 AAAAC3Nza... deploy@paradocks-vps" >> ~/.ssh/authorized_keys

# Set correct permissions
chmod 600 ~/.ssh/authorized_keys
```

### Step 5: Test SSH Key Authentication

```bash
# Test SSH connection with private key
ssh -i ~/.ssh/paradocks_deploy_ed25519 deploy@72.60.17.138

# Should connect without password prompt
# If prompted for password, check authorized_keys permissions
```

### Step 6: Disable Password Authentication (Optional - Recommended)

```bash
# SSH as deploy user
ssh -i ~/.ssh/paradocks_deploy_ed25519 deploy@72.60.17.138

# Edit SSH config
sudo nano /etc/ssh/sshd_config

# Find and modify these lines:
PasswordAuthentication no
PubkeyAuthentication yes
ChallengeResponseAuthentication no

# Save and exit (Ctrl+X, Y, Enter)

# Restart SSH service
sudo systemctl restart sshd
```

**Warning:** Ensure you can connect with SSH key BEFORE disabling password auth!

---

## Docker Permissions

### Step 7: Add Deploy User to Docker Group

```bash
# SSH as deploy user
ssh -i ~/.ssh/paradocks_deploy_ed25519 deploy@72.60.17.138

# Add deploy to docker group
sudo usermod -aG docker deploy

# Verify group membership
groups deploy
# Output: deploy sudo docker

# Apply group changes (logout and login)
exit
ssh -i ~/.ssh/paradocks_deploy_ed25519 deploy@72.60.17.138

# Test Docker access (no sudo required)
docker ps
# Should show running containers

# Test Docker Compose
cd /var/www/paradocks
docker compose -f docker-compose.prod.yml ps
# Should show containers
```

**Expected Output:**
```
NAME                        STATUS
paradocks-app-prod          Up (healthy)
paradocks-mysql-prod        Up (healthy)
paradocks-nginx-prod        Up (healthy)
paradocks-redis-prod        Up (healthy)
paradocks-horizon-prod      Up (healthy)
paradocks-scheduler-prod    Up
```

---

## GHCR Authentication

### Step 8: Create GitHub Personal Access Token

1. **Navigate to GitHub:**
   - Go to: https://github.com/settings/tokens
   - Click: "Generate new token" → "Generate new token (classic)"

2. **Configure Token:**
   - **Note:** "Paradocks VPS Deployment"
   - **Expiration:** 90 days (or custom)
   - **Scopes:** Select:
     - `read:packages` - Download images from GHCR
     - `write:packages` - Push images to GHCR (if needed)
     - `delete:packages` - Delete old images (optional)

3. **Generate and Copy:**
   - Click "Generate token"
   - **IMPORTANT:** Copy token immediately (only shown once)
   - Format: `ghp_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx`

### Step 9: Authenticate VPS with GHCR

```bash
# SSH as deploy user
ssh -i ~/.ssh/paradocks_deploy_ed25519 deploy@72.60.17.138

# Login to GHCR
echo "ghp_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx" | docker login ghcr.io -u patrykgielo --password-stdin

# Output: Login Succeeded

# Verify authentication
docker pull ghcr.io/patrykgielo/paradocks:latest
# Should download image successfully
```

**Store Token Securely (Optional):**

```bash
# Save token to file (secure permissions)
echo "ghp_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx" > ~/.ghcr-token
chmod 600 ~/.ghcr-token

# Auto-login on reboot (add to .bashrc)
echo 'cat ~/.ghcr-token | docker login ghcr.io -u patrykgielo --password-stdin &>/dev/null' >> ~/.bashrc

# Test auto-login
source ~/.bashrc
docker pull ghcr.io/patrykgielo/paradocks:latest
```

---

## Directory Structure

### Step 10: Verify Application Directory

```bash
# SSH as deploy user
ssh -i ~/.ssh/paradocks_deploy_ed25519 deploy@72.60.17.138

# Check application directory
ls -la /var/www/paradocks

# Expected structure:
# drwxrwxr-x  2 deploy deploy 4096 app/
# drwxrwxr-x  2 deploy deploy 4096 storage/
# drwxrwxr-x  2 deploy deploy 4096 scripts/
# -rw-rw-r--  1 deploy deploy 5432 docker-compose.prod.yml
# -rw-rw-r--  1 deploy deploy 1234 .env

# Verify ownership
ls -la /var/www/paradocks | grep -E 'app|storage|scripts'
# All should be owned by deploy:deploy
```

**Fix Permissions (if needed):**

```bash
# Change ownership to deploy user
sudo chown -R deploy:deploy /var/www/paradocks

# Fix storage permissions
sudo chmod -R 775 /var/www/paradocks/storage
sudo chmod -R 775 /var/www/paradocks/bootstrap/cache
```

### Step 11: Create Backup Directory

```bash
# Create backup directory
mkdir -p /var/www/paradocks/backups

# Set permissions
chmod 755 /var/www/paradocks/backups

# Verify
ls -la /var/www/paradocks | grep backups
# drwxr-xr-x 2 deploy deploy 4096 Nov 29 14:30 backups
```

---

## Testing Setup

### Step 12: Test Manual Deployment

```bash
# SSH as deploy user
ssh -i ~/.ssh/paradocks_deploy_ed25519 deploy@72.60.17.138

# Navigate to app directory
cd /var/www/paradocks

# Test deployment script (dry-run)
./scripts/deploy-update.sh latest --help

# Expected output:
# Usage: ./scripts/deploy-update.sh [VERSION] [OPTIONS]
# ...
```

### Step 13: Test Docker Image Pull

```bash
# Pull latest image
docker compose -f docker-compose.prod.yml pull app

# Expected output:
# [+] Pulling 1/1
#  ✔ app Pulled
```

### Step 14: Test Container Restart

```bash
# Restart app container
docker compose -f docker-compose.prod.yml restart app

# Check status
docker compose -f docker-compose.prod.yml ps app

# Expected output:
# NAME                  STATUS
# paradocks-app-prod    Up (healthy)
```

### Step 15: Test Health Endpoint

```bash
# Query health endpoint
curl -s http://localhost:8081/health | jq

# Expected output:
# {
#   "status": "healthy",
#   "checks": {
#     "database": true,
#     "redis": "PONG"
#   },
#   "timestamp": "2025-11-29T14:30:22Z",
#   "version": "v1.0.0"
# }
```

---

## Troubleshooting

### SSH Connection Refused

**Symptom:** `ssh: connect to host 72.60.17.138 port 22: Connection refused`

**Solution:**
```bash
# Check if SSH service is running (from root)
sudo systemctl status sshd

# Restart SSH service
sudo systemctl restart sshd

# Check firewall rules
sudo ufw status
sudo ufw allow 22/tcp
```

### Permission Denied (Docker)

**Symptom:** `Got permission denied while trying to connect to the Docker daemon socket`

**Solution:**
```bash
# Add user to docker group
sudo usermod -aG docker deploy

# Logout and login
exit
ssh -i ~/.ssh/paradocks_deploy_ed25519 deploy@72.60.17.138

# Test Docker
docker ps
```

### GHCR Authentication Failed

**Symptom:** `Error response from daemon: unauthorized: authentication required`

**Solution:**
```bash
# Re-login to GHCR
docker logout ghcr.io
echo "ghp_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx" | docker login ghcr.io -u patrykgielo --password-stdin

# Verify repository access
docker pull ghcr.io/patrykgielo/paradocks:latest
```

### Storage Full

**Symptom:** `no space left on device`

**Solution:**
```bash
# Check disk usage
df -h

# Clean Docker system
docker system prune -af --volumes

# Remove old images
docker images | grep paradocks | grep -v latest | awk '{print $3}' | xargs docker rmi
```

---

## Security Checklist

Before completing setup, verify:

- [ ] Deploy user created with home directory
- [ ] SSH key authentication configured (ed25519)
- [ ] Password authentication disabled (optional)
- [ ] Deploy user in docker group (Docker access)
- [ ] GHCR authenticated with Personal Access Token
- [ ] Application directory owned by deploy:deploy
- [ ] Backup directory created
- [ ] Manual deployment script tested
- [ ] Health endpoint accessible
- [ ] Firewall rules configured (ports 22, 80, 443)

---

## Next Steps

After completing VPS setup:

1. **Configure GitHub Secrets** - See: `docs/deployment/github-secrets.md`
2. **Test First Deployment** - Create `v1.0.0` tag and verify GitHub Actions workflow
3. **Monitor Deployments** - Check GitHub Actions logs, health endpoint
4. **Schedule Backups** - Configure automated daily/weekly backups
5. **Setup Monitoring** - Consider uptime monitoring (UptimeRobot, etc.)

---

**Document Owner:** DevOps Team
**Review Cycle:** Quarterly
**Last Review:** November 2025
**Next Review:** February 2026

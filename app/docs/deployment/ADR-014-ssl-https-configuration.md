# ADR-014: SSL/HTTPS Configuration with Let's Encrypt

**Status:** ✅ Accepted
**Date:** 2025-12-09
**Context:** Production Filament file upload failure due to HTTPS/HTTP mismatch
**Decision Maker:** DevOps Team

---

## Problem Statement

Production deployment with `APP_URL=https://srv1117368.hstgr.cloud` but Nginx only listening on port 80 caused:

- **Filament file upload preview**: `ERR_CONNECTION_REFUSED` when loading storage images
- **JavaScript errors**: `Failed to fetch` for `https://srv1117368.hstgr.cloud/storage/...`
- **Root cause**: Laravel generated HTTPS URLs but Nginx couldn't serve them

**User Feedback:** "to jest produkcja nie ma czegoś takiego jak QUICK FIX!"

---

## Decision

**We will implement proper SSL/HTTPS using Let's Encrypt with automated renewal.**

### Rationale

1. **Production Security**: No production site should run without SSL in 2025
2. **User Trust**: Browser warnings erode user confidence
3. **SEO**: Google penalizes non-HTTPS sites in rankings
4. **Mixed Content**: Modern browsers block HTTP resources on HTTPS pages
5. **Free & Automated**: Let's Encrypt provides free SSL with 90-day auto-renewal

---

## Implementation

### 1. SSL Certificate Generation

```bash
# Stop Nginx temporarily
docker compose -f docker-compose.prod.yml stop nginx

# Generate certificate (standalone mode)
certbot certonly --standalone \
  -d srv1117368.hstgr.cloud \
  --non-interactive \
  --agree-tos \
  --email admin@srv1117368.hstgr.cloud

# Certificate stored at:
# /etc/letsencrypt/live/srv1117368.hstgr.cloud/fullchain.pem (cert + chain)
# /etc/letsencrypt/live/srv1117368.hstgr.cloud/privkey.pem (private key)
```

**Result:**
```
Certificate is saved at: /etc/letsencrypt/live/srv1117368.hstgr.cloud/fullchain.pem
Key is saved at:         /etc/letsencrypt/live/srv1117368.hstgr.cloud/privkey.pem
This certificate expires on 2026-03-09.
```

---

### 2. Nginx SSL Configuration

**File:** `docker/nginx/app.prod.conf`

```nginx
# HTTP -> HTTPS redirect
server {
    listen 80;
    server_name srv1117368.hstgr.cloud www.srv1117368.hstgr.cloud;

    # Allow Let's Encrypt challenges
    location /.well-known/acme-challenge/ {
        root /var/www/letsencrypt;
    }

    # Redirect all other traffic to HTTPS
    location / {
        return 301 https://$host$request_uri;
    }
}

# HTTPS server
server {
    listen 443 ssl http2;
    server_name srv1117368.hstgr.cloud www.srv1117368.hstgr.cloud;
    root /var/www/public;

    # SSL certificates
    ssl_certificate /etc/letsencrypt/live/srv1117368.hstgr.cloud/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/srv1117368.hstgr.cloud/privkey.pem;

    # SSL configuration (Mozilla Intermediate)
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers 'ECDHE-ECDSA-AES128-GCM-SHA256:...';
    ssl_prefer_server_ciphers off;
    ssl_session_timeout 1d;
    ssl_session_cache shared:SSL:50m;

    # Security headers
    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-XSS-Protection "1; mode=block" always;

    # ... rest of config (PHP-FPM, static assets, etc.)
}
```

---

### 3. Docker Compose Configuration

**File:** `docker-compose.prod.yml`

```yaml
nginx:
  image: nginx:1.25-alpine
  container_name: registro-nginx
  restart: unless-stopped
  ports:
    - "80:80"
    - "443:443"  # ← ADDED
  volumes:
    - ./docker/nginx/app.prod.conf:/etc/nginx/conf.d/default.conf:ro
    - app_public:/var/www/public:ro
    - storage-app-public:/var/www/storage/app/public:ro
    - /etc/letsencrypt:/etc/letsencrypt:ro  # ← SSL certificates
    - /var/lib/letsencrypt:/var/lib/letsencrypt:ro
    - /var/www/letsencrypt:/var/www/letsencrypt:ro  # ← ACME challenges
```

---

### 4. Automated Certificate Renewal

**Renewal Configuration:** `/etc/letsencrypt/renewal/srv1117368.hstgr.cloud.conf`

```ini
[renewalparams]
authenticator = webroot
webroot_path = /var/www/letsencrypt
server = https://acme-v02.api.letsencrypt.org/directory
```

**Renewal Hook:** `/etc/letsencrypt/renewal-hooks/deploy/restart-nginx.sh`

```bash
#!/bin/bash
# Reload Nginx after SSL certificate renewal

cd /var/www/registro || exit 1
docker compose -f docker-compose.prod.yml restart nginx

logger -t certbot-renewal "SSL certificates renewed, Nginx restarted"
```

**Systemd Timer:**
```bash
# Check timer status
systemctl status snap.certbot.renew.timer

# Next renewal: Wed 2025-12-10 05:23:00 CET (runs twice daily)
```

---

## Security Features

### 1. TLS Configuration
- **Protocols**: TLS 1.2, TLS 1.3 (no SSLv3, TLS 1.0, TLS 1.1)
- **Cipher Suite**: Mozilla Intermediate (compatible with 99% of clients)
- **Session Cache**: 50MB shared cache, 1 day timeout
- **Perfect Forward Secrecy**: ECDHE key exchange

### 2. HTTP Security Headers
- **HSTS**: `max-age=31536000; includeSubDomains` (1 year, force HTTPS)
- **X-Frame-Options**: `SAMEORIGIN` (prevent clickjacking)
- **X-Content-Type-Options**: `nosniff` (prevent MIME sniffing)
- **X-XSS-Protection**: `1; mode=block` (legacy XSS protection)

### 3. Certificate Validation
- **Issuer**: Let's Encrypt (trusted by all browsers)
- **Validity**: 90 days (auto-renewed at 60 days)
- **Chain**: Full chain included (intermediate + root CA)

---

## Verification

### Manual Tests (2025-12-09)

```bash
# HTTPS homepage
curl -I https://srv1117368.hstgr.cloud/
# → HTTP/2 200 (✅ SSL works)

# Storage images
curl -I https://srv1117368.hstgr.cloud/storage/services/images/01KC2399NHRDPGFJRJ8JX9G9VQ.jpg
# → HTTP/2 200 (✅ File upload preview fixed)

# HTTP redirect
curl -I http://srv1117368.hstgr.cloud/
# → HTTP/1.1 301 (✅ Redirects to HTTPS)

# Security headers
curl -I https://srv1117368.hstgr.cloud/ | grep -i strict
# → strict-transport-security: max-age=31536000; includeSubDomains (✅ HSTS enabled)
```

### SSL Labs Test

**Recommended:** Test with https://www.ssllabs.com/ssltest/

**Expected Grade:** A (with current configuration)

---

## Operational Procedures

### Certificate Renewal (Automatic)

**Renewal runs automatically via systemd timer (twice daily).**

**Manual renewal (if needed):**
```bash
# Test renewal
certbot renew --dry-run

# Force renewal (if <30 days to expiry)
certbot renew --force-renewal

# Check certificate expiry
certbot certificates
```

### Certificate Expiry Monitoring

**Current certificate expires:** 2026-03-09
**Auto-renewal triggers at:** 60 days before expiry (2026-01-08)
**Emergency manual renewal:** 30 days before expiry (2026-02-07)

**Monitoring:** Check systemd timer logs daily
```bash
journalctl -u snap.certbot.renew.service --since "24 hours ago"
```

### Troubleshooting

**Problem: Renewal fails with "standalone" error**
- **Cause:** Nginx is running on port 80/443
- **Solution:** Renewal should use `webroot` authenticator (already configured)

**Problem: Nginx can't find certificates after renewal**
- **Cause:** Nginx not restarted after renewal
- **Solution:** Check renewal hook executed: `journalctl -t certbot-renewal`

**Problem: Mixed content warnings**
- **Cause:** HTTP resources on HTTPS page
- **Solution:** Ensure `APP_URL=https://` in .env

---

## Migration Guide (Future Domains)

**To add SSL for additional domains (e.g., registro.local):**

```bash
# 1. Stop Nginx
docker compose -f docker-compose.prod.yml stop nginx

# 2. Generate certificate
certbot certonly --standalone -d registro.local -d www.registro.local

# 3. Update Nginx config
# Add new server block with SSL for registro.local

# 4. Create webroot renewal config
cat > /etc/letsencrypt/renewal/registro.local.conf << 'EOF'
authenticator = webroot
webroot_path = /var/www/letsencrypt
[[webroot_map]]
registro.local = /var/www/letsencrypt
www.registro.local = /var/www/letsencrypt
EOF

# 5. Restart Nginx
docker compose -f docker-compose.prod.yml start nginx
```

---

## Alternatives Considered

### ❌ HTTP-Only (Rejected)
- **Pros**: No SSL setup complexity
- **Cons**: Insecure, SEO penalty, browser warnings, Filament file upload breaks

### ❌ Cloudflare SSL (Rejected)
- **Pros**: Free, managed by Cloudflare
- **Cons**: Requires Cloudflare DNS, adds latency, limited control

### ✅ Let's Encrypt with Certbot (CHOSEN)
- **Pros**: Free, automated renewal, full control, industry standard
- **Cons**: 90-day expiry (mitigated by auto-renewal)

---

## References

- **Mozilla SSL Config Generator**: https://ssl-config.mozilla.org/
- **Let's Encrypt Docs**: https://letsencrypt.org/docs/
- **Certbot Docs**: https://eff-certbot.readthedocs.io/
- **SSL Labs Test**: https://www.ssllabs.com/ssltest/
- **Production Server**: http://srv1117368.hstgr.cloud/ → https://srv1117368.hstgr.cloud/

---

## Related

- [Known Issues](known-issues.md) - Issue #0 (v0.6.1 Docker user mismatch)
- [Deployment History](deployment-history.md) - All production deployments
- [CLAUDE.md](../../CLAUDE.md) - Production URLs now use HTTPS

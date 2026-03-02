# Environment Variables Checklist - Production Deployment

**Purpose:** Ensure all required environment variables are properly configured in both `.env` file AND `docker-compose.prod.yml`.

## ⚠️ CRITICAL: Docker Compose Environment Variables

**Problem:** Environment variables in `/var/www/paradocks/.env` on the server are **NOT automatically available** inside Docker containers.

**Solution:** Every variable that Laravel needs must be explicitly listed in `docker-compose.prod.yml` under `environment:` section.

## Required Environment Variables

### Core Application (✅ Already configured)
- `APP_ENV=production`
- `APP_KEY=${APP_KEY}`
- `APP_URL=${APP_URL}`

### Database (✅ Already configured)
- `DB_CONNECTION=mysql`
- `DB_HOST=mysql`
- `DB_DATABASE=${DB_DATABASE}`
- `DB_USERNAME=${DB_USERNAME}`
- `DB_PASSWORD=${DB_PASSWORD}`

### Redis/Cache/Queue (✅ Already configured)
- `REDIS_HOST=redis`
- `REDIS_PORT=6379`
- `REDIS_CLIENT=phpredis`
- `CACHE_STORE=redis`
- `QUEUE_CONNECTION=redis`
- `SESSION_DRIVER=redis`

### Mail (✅ Configured in `horizon` service)
- `MAIL_MAILER=smtp`
- `MAIL_HOST=smtp.gmail.com`
- `MAIL_PORT=587`
- `MAIL_USERNAME=${MAIL_USERNAME}`
- `MAIL_PASSWORD=${MAIL_PASSWORD}`
- `MAIL_ENCRYPTION=tls`
- `MAIL_FROM_ADDRESS=${MAIL_FROM_ADDRESS}`
- `MAIL_FROM_NAME=${MAIL_FROM_NAME}`

### Google Maps (✅ Fixed on 2025-12-14)
- `GOOGLE_MAPS_API_KEY=${GOOGLE_MAPS_API_KEY}`
- `GOOGLE_MAPS_MAP_ID=${GOOGLE_MAPS_MAP_ID}`

**Services:** `app`, `horizon`

## Deployment Checklist

When adding a **NEW** environment variable to the project:

### 1. Add to `.env.example` in repository
```bash
# Example: Adding Stripe integration
STRIPE_PUBLIC_KEY=pk_test_example
STRIPE_SECRET_KEY=sk_test_example
```

### 2. Add to `docker-compose.prod.yml`
```yaml
services:
  app:
    environment:
      # ... existing vars ...
      - STRIPE_PUBLIC_KEY=${STRIPE_PUBLIC_KEY}
      - STRIPE_SECRET_KEY=${STRIPE_SECRET_KEY}
```

### 3. Add to production server `.env`
```bash
ssh root@72.60.17.138
nano /var/www/paradocks/.env
# Add actual production values
```

### 4. Deploy changes
```bash
# Option A: Full deployment (recommended)
git tag -a v4.2.3 -m "feat: add Stripe integration"
git push origin v4.2.3

# Option B: Quick config update
ssh root@72.60.17.138 "cd /var/www/paradocks && \
  curl -o docker-compose.prod.yml https://raw.githubusercontent.com/patrykgielo/paradocks/main/docker-compose.prod.yml && \
  docker compose -f docker-compose.prod.yml up -d --force-recreate app horizon"
```

### 5. Verify in container
```bash
ssh root@72.60.17.138 "docker compose -f /var/www/paradocks/docker-compose.prod.yml exec -T app env | grep STRIPE"
```

## Common Mistakes

### ❌ WRONG: Only adding to `.env`
```bash
# This WILL NOT WORK - container won't see it!
ssh root@72.60.17.138
echo "NEW_API_KEY=abc123" >> /var/www/paradocks/.env
```

### ✅ CORRECT: Add to both `.env` AND `docker-compose.prod.yml`
```yaml
# docker-compose.prod.yml
services:
  app:
    environment:
      - NEW_API_KEY=${NEW_API_KEY}  # ← This reads from .env
```

## Testing Environment Variables

### Test in container
```bash
# Check all env vars
docker compose -f /var/www/paradocks/docker-compose.prod.yml exec -T app env

# Check specific var
docker compose -f /var/www/paradocks/docker-compose.prod.yml exec -T app env | grep GOOGLE_MAPS

# Test via Laravel Tinker
docker compose -f /var/www/paradocks/docker-compose.prod.yml exec -T app php artisan tinker --execute="echo config('services.google_maps.api_key');"
```

## Which Services Need Which Variables?

| Variable | app | horizon | scheduler | Notes |
|----------|-----|---------|-----------|-------|
| Database | ✅ | ✅ | ✅ | All services need DB access |
| Redis | ✅ | ✅ | ✅ | All services use cache/queue |
| Mail | ❌ | ✅ | ❌ | Only queue workers send emails |
| Google Maps | ✅ | ✅ | ❌ | App renders views, Horizon may send notifications with maps |

## Previous Issues

### 2025-12-14: Google Maps API Error
**Issue:** `ApiProjectMapError` - API key not available in JavaScript
**Root Cause:** `GOOGLE_MAPS_API_KEY` was in `/var/www/paradocks/.env` but not in `docker-compose.prod.yml`
**Fix:** Added to `app` and `horizon` services
**Commit:** `ba831be`

## References

- [Docker Compose Environment Variables](https://docs.docker.com/compose/environment-variables/)
- [Laravel Configuration](https://laravel.com/docs/12.x/configuration)
- [Production Build Guide](../guides/production-build.md)
- [Deployment History](./deployment-history.md)

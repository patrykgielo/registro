#!/bin/bash
# Maintenance Mode Configuration Backup Script
# Version: 1.0
# Last Updated: 2025-12-06
# Description: Backs up maintenance mode configuration including uploaded images, Redis data, and Settings

set -e  # Exit on error

timestamp=$(date +%Y%m%d_%H%M%S)
backup_dir=".backups/maintenance-${timestamp}"

echo "🔄 Starting maintenance mode backup..."
mkdir -p "$backup_dir"

# 1. Backup uploaded images
echo "📁 Backing up uploaded images..."
if docker compose exec app test -d storage/app/public/maintenance/backgrounds 2>/dev/null; then
    docker compose exec app tar -czf /tmp/imgs.tar.gz storage/app/public/maintenance/backgrounds/ 2>/dev/null || true
    docker cp registro-app:/tmp/imgs.tar.gz "$backup_dir/images.tar.gz" 2>/dev/null
    docker compose exec app rm /tmp/imgs.tar.gz 2>/dev/null || true
    echo "   ✅ Images backed up"
else
    echo "   ℹ️  No images directory found (skipping)"
    touch "$backup_dir/no-images.txt"
fi

# 2. Backup Redis maintenance keys
echo "💾 Backing up Redis data..."
docker compose exec redis redis-cli BGSAVE > /dev/null 2>&1
sleep 2
docker cp registro-redis:/data/dump.rdb "$backup_dir/redis.rdb" 2>/dev/null
echo "   ✅ Redis backed up"

# 3. Backup Settings table
echo "🗄️  Backing up Settings table..."
docker compose exec mysql mysqldump -u registro -ppassword registro settings > "$backup_dir/settings.sql" 2>/dev/null
echo "   ✅ Settings backed up"

# 4. Backup current git state
echo "🔖 Recording git state..."
git log -1 --oneline > "$backup_dir/git-commit.txt"
git status > "$backup_dir/git-status.txt"
git diff > "$backup_dir/git-diff.txt" 2>/dev/null || true
echo "   ✅ Git state recorded"

# 5. Create backup manifest
cat > "$backup_dir/MANIFEST.txt" <<EOF
Maintenance Mode Backup
=======================
Backup Date: $(date)
Git Commit: $(git log -1 --oneline)
Git Branch: $(git branch --show-current)

Docker Compose Status:
$(docker compose ps 2>/dev/null)

Backup Contents:
- images.tar.gz: Uploaded background images (if exists)
- redis.rdb: Redis persistence file (maintenance:* keys)
- settings.sql: MySQL settings table dump
- git-commit.txt: Current git commit
- git-status.txt: Git working tree status
- git-diff.txt: Uncommitted changes

Restore Instructions:
=====================
1. Restore uploaded images:
   docker cp $backup_dir/images.tar.gz registro-app:/tmp/
   docker compose exec app tar -xzf /tmp/images.tar.gz -C /var/www/

2. Restore Redis data:
   docker cp $backup_dir/redis.rdb registro-redis:/data/dump.rdb
   docker compose restart redis

3. Restore Settings table:
   docker compose exec mysql mysql -u registro -ppassword registro < $backup_dir/settings.sql

Emergency Rollback:
===================
# Quick rollback (1 min)
git reset --hard 9e0252e
docker compose restart app

# Full rollback with backup restore (5 min)
git reset --hard 878fc5e
docker compose down && docker compose up -d --build
docker compose exec app php artisan migrate:fresh --seed
# Then restore from backup as above

For more info, see:
- docs/deployment/known-issues.md#issue-13-pre-launch-configuration-corruption
EOF

echo ""
echo "✅ Backup complete: $backup_dir"
echo "📄 Manifest: $backup_dir/MANIFEST.txt"
echo ""
echo "Backup size: $(du -sh $backup_dir | cut -f1)"
echo ""
echo "To restore:"
echo "  Images:   docker cp $backup_dir/images.tar.gz registro-app:/tmp/ && docker compose exec app tar -xzf /tmp/images.tar.gz -C /var/www/"
echo "  Redis:    docker cp $backup_dir/redis.rdb registro-redis:/data/dump.rdb && docker compose restart redis"
echo "  Settings: docker compose exec mysql mysql -u registro -ppassword registro < $backup_dir/settings.sql"
echo ""
echo "See full restoration guide in: $backup_dir/MANIFEST.txt"

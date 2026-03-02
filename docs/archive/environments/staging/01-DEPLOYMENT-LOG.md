# Staging Deployment Log

**Environment**: Staging VPS
**Server**: 72.60.17.138 (paradocks.pl, old: srv1117368.hstgr.cloud)
**Initial Deployment**: 2025-11-11

This document chronicles the actual deployment history with all issues, solutions, and workarounds that were implemented.

---

## Deployment #1 - Initial Staging Deployment

**Date**: 2025-11-11
**Branch**: staging
**Deployed By**: Development Team
**Status**: ✅ Successful (with workarounds)

### Pre-Deployment Preparation

#### Server Provisioning (09:00 - 09:30)

1. **VPS Allocation**
   - Provider: Hostinger VPS
   - IP assigned: 72.60.17.138
   - Hostname: paradocks.pl (old: srv1117368.hstgr.cloud)
   - OS: Ubuntu 24.04 LTS (fresh installation)

2. **Initial SSH Access**
   ```bash
   ssh root@72.60.17.138
   # Changed root password
   # Created ubuntu user with sudo privileges
   ```

3. **User Setup**
   ```bash
   adduser ubuntu
   usermod -aG sudo ubuntu

   # Setup SSH key authentication
   mkdir -p /home/ubuntu/.ssh
   cp /root/.ssh/authorized_keys /home/ubuntu/.ssh/
   chown -R ubuntu:ubuntu /home/ubuntu/.ssh
   chmod 700 /home/ubuntu/.ssh
   chmod 600 /home/ubuntu/.ssh/authorized_keys

   # Disable root SSH login
   sed -i 's/PermitRootLogin yes/PermitRootLogin no/' /etc/ssh/sshd_config
   systemctl restart sshd
   ```

4. **System Updates**
   ```bash
   apt update && apt upgrade -y
   apt install -y vim curl wget git htop net-tools
   ```

5. **Swap Configuration**
   ```bash
   # Create 2GB swap (server has 2GB RAM)
   fallocate -l 2G /swapfile
   chmod 600 /swapfile
   mkswap /swapfile
   swapon /swapfile
   echo '/swapfile none swap sw 0 0' >> /etc/fstab

   # Verify
   free -h
   # Output: Swap: 2.0G
   ```

#### Docker Installation (09:30 - 09:45)

1. **Docker Engine Installation**
   ```bash
   # Add Docker's official GPG key
   apt-get update
   apt-get install -y ca-certificates curl
   install -m 0755 -d /etc/apt/keyrings
   curl -fsSL https://download.docker.com/linux/ubuntu/gpg -o /etc/apt/keyrings/docker.asc
   chmod a+r /etc/apt/keyrings/docker.asc

   # Add repository
   echo \
     "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/ubuntu \
     $(. /etc/os-release && echo "$VERSION_CODENAME") stable" | \
     tee /etc/apt/sources.list.d/docker.list > /dev/null

   # Install Docker
   apt-get update
   apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
   ```

2. **Docker Configuration**
   ```bash
   # Add ubuntu user to docker group
   usermod -aG docker ubuntu

   # Enable Docker service
   systemctl enable docker
   systemctl start docker

   # Verify installation
   docker --version
   # Output: Docker version 29.0.0, build 690df1b

   docker compose version
   # Output: Docker Compose version 2.40.3
   ```

3. **Docker Post-Installation**
   ```bash
   # Test Docker without sudo (after re-login)
   su - ubuntu
   docker run hello-world
   # Output: Hello from Docker! (success)
   ```

#### Firewall Configuration (09:45 - 10:00)

1. **UFW Installation and Basic Setup**
   ```bash
   apt install -y ufw

   # Allow SSH before enabling (critical!)
   ufw allow 22/tcp comment 'SSH'

   # Allow HTTP and HTTPS
   ufw allow 80/tcp comment 'HTTP'
   ufw allow 443/tcp comment 'HTTPS'

   # Enable firewall
   ufw enable

   # Verify
   ufw status verbose
   ```

2. **⚠️ ISSUE #1: Docker Bypassing UFW**

   **Problem Discovered**: Docker modifies iptables directly, bypassing UFW rules. This means Docker container ports would be exposed even if not allowed in UFW.

   **Investigation**:
   ```bash
   # Tested exposing MySQL on 3306
   # Port was accessible from outside despite no UFW rule
   # Security risk identified
   ```

   **Solution**: Install ufw-docker script (see ADR-001)

   ```bash
   # Download ufw-docker script
   wget -O /usr/local/bin/ufw-docker https://github.com/chaifeng/ufw-docker/raw/master/ufw-docker
   chmod +x /usr/local/bin/ufw-docker

   # Install UFW rules
   ufw-docker install

   # Reload UFW
   systemctl restart ufw
   ```

   **Verification**:
   ```bash
   # Check iptables rules
   iptables -L DOCKER-USER -n
   # Now shows proper filtering rules
   ```

   **Decision Recorded**: [ADR-001-ufw-docker-security.md](../../architecture/decision_log/ADR-001-ufw-docker-security.md)

   **Status**: ✅ Resolved
   **Time Lost**: ~30 minutes (research and implementation)

### Application Deployment (10:00 - 12:30)

#### Repository Setup (10:00 - 10:15)

1. **Project Directory Creation**
   ```bash
   mkdir -p /var/www
   cd /var/www
   ```

2. **Git Clone**
   ```bash
   # IMPORTANT: Cloned staging branch, NOT main
   git clone -b staging <repository-url> paradocks
   cd paradocks

   # Verify branch
   git branch
   # Output: * staging
   ```

3. **Ownership Setup**
   ```bash
   chown -R ubuntu:ubuntu /var/www/paradocks
   ```

#### Environment Configuration (10:15 - 10:45)

1. **Copy Environment File**
   ```bash
   cp .env.example .env
   ```

2. **Generate Application Key**
   ```bash
   # Had to use Docker for this
   docker run --rm -v $(pwd):/app -w /app php:8.2-cli php artisan key:generate
   ```

3. **⚠️ ISSUE #2: DB_ Variables Commented Out**

   **Problem**: Database connection failing. Investigation showed DB_ variables were commented in .env.example

   ```bash
   # .env.example had:
   # DB_CONNECTION=mysql
   # DB_HOST=mysql
   # etc.
   ```

   **Solution**: Uncommented all DB_ variables

   ```bash
   vim .env
   # Uncommented:
   DB_CONNECTION=mysql
   DB_HOST=mysql
   DB_PORT=3306
   DB_DATABASE=paradocks
   DB_USERNAME=paradocks
   DB_PASSWORD=  # To be set
   ```

   **Status**: ✅ Resolved
   **Time Lost**: ~15 minutes

4. **Generate Secure Passwords**
   ```bash
   # Generated using OpenSSL
   DB_PASSWORD=$(openssl rand -base64 32)
   DB_ROOT_PASSWORD=$(openssl rand -base64 32)
   REDIS_PASSWORD=$(openssl rand -base64 32)

   # Passwords generated:
   # DB_PASSWORD=ENDnAJtD+RtLl88WE0K1UUT/lSlQ6YYk
   # DB_ROOT_PASSWORD=SO0I1JLL4/Sgn5NIpSyf0N0VKYB9EsHq
   # REDIS_PASSWORD=bt3mHr07Im0AVS3Jau851E1KsimlHf02
   ```

5. **Update .env File**
   ```bash
   vim .env

   # Set all environment variables:
   APP_NAME="ParaDocks"
   APP_ENV=production
   APP_DEBUG=false
   APP_URL=http://72.60.17.138
   APP_TIMEZONE=Europe/Warsaw

   # Database
   DB_CONNECTION=mysql
   DB_HOST=mysql
   DB_PORT=3306
   DB_DATABASE=paradocks
   DB_USERNAME=paradocks
   DB_PASSWORD=ENDnAJtD+RtLl88WE0K1UUT/lSlQ6YYk

   # Redis
   CACHE_STORE=redis
   QUEUE_CONNECTION=redis
   SESSION_DRIVER=redis
   REDIS_HOST=redis
   REDIS_PASSWORD=bt3mHr07Im0AVS3Jau851E1KsimlHf02

   # Mail (temporary - log driver)
   MAIL_MAILER=log
   ```

#### Docker Configuration (10:45 - 11:15)

1. **Review docker-compose.prod.yml**
   ```bash
   cat docker-compose.prod.yml
   ```

2. **⚠️ ISSUE #3: Storage Volume Permission Issues**

   **Problem**: Initial docker-compose.prod.yml had Docker-managed volumes for storage:
   ```yaml
   volumes:
     - paradocks_storage:/var/www/html/storage
   ```

   This caused permission issues - the application couldn't write logs, cache, or uploads.

   **Investigation**:
   ```bash
   # Container user (www-data) UID: 82 (Alpine)
   # Host storage/ owner: ubuntu:ubuntu (1000:1000)
   # Mismatch caused permission denied errors
   ```

   **Solution**: Remove Docker volumes, use bind mounts only

   ```yaml
   # Changed to:
   volumes:
     - ./storage:/var/www/html/storage
     - ./bootstrap/cache:/var/www/html/bootstrap/cache

   # Removed volume definition:
   # volumes:
   #   paradocks_storage:  # REMOVED
   ```

   **Set Correct Permissions**:
   ```bash
   chmod -R 775 storage bootstrap/cache
   chown -R ubuntu:ubuntu storage bootstrap/cache
   ```

   **Decision Recorded**: [ADR-002-storage-volume-removal.md](../../architecture/decision_log/ADR-002-storage-volume-removal.md)

   **Status**: ✅ Resolved
   **Time Lost**: ~45 minutes (testing different permission approaches)

3. **Update docker-compose.prod.yml**

   Final working configuration saved to `docker-compose.prod.yml`

#### Nginx Configuration (11:15 - 11:30)

1. **⚠️ ISSUE #4: Nginx Config Had paradocks-node References**

   **Problem**: Initial nginx config referenced paradocks-node service that doesn't exist:
   ```nginx
   location /socket.io {
       proxy_pass http://paradocks-node:3000;
   }
   ```

   **Solution**: Created clean app.prod.conf without Node.js references

   ```bash
   cp docker/nginx/app.prod.conf.example docker/nginx/app.prod.conf
   # Removed all paradocks-node references
   ```

   **Status**: ✅ Resolved
   **Time Lost**: ~10 minutes

#### Build Frontend Assets (11:30 - 11:45)

1. **Install Node.js**
   ```bash
   # Install NVM
   curl -o- https://raw.githubusercontent.com/nvm-sh/nvm/v0.40.1/install.sh | bash
   source ~/.bashrc

   # Install Node 20 LTS
   nvm install 20
   nvm use 20

   # Verify
   node --version
   # Output: v20.19.5

   npm --version
   # Output: 10.9.2
   ```

2. **Build Assets**
   ```bash
   npm install
   npm run build
   ```

3. **⚠️ ISSUE #5: Vite Manifest Not Found**

   **Problem**: Application showed error "Vite manifest not found at: /var/www/paradocks/public/build/manifest.json"

   **Investigation**:
   ```bash
   # Vite builds to:
   ls public/.vite/manifest.json
   # Exists!

   # But Laravel expects:
   # public/build/manifest.json
   # Not found!
   ```

   **Root Cause**: Vite config outputs to `public/.vite/` but Laravel expects `public/build/`

   **Solution Options Considered**:
   - A) Change Vite config (would affect development)
   - B) Copy files (would need automation)
   - C) Create symlink (simple, works everywhere)

   **Solution Implemented**: Symlink

   ```bash
   mkdir -p public/build
   cd public/build
   ln -s ../.vite/manifest.json manifest.json

   # Verify
   ls -la public/build/
   # manifest.json -> ../.vite/manifest.json (working)
   ```

   **Decision Recorded**: [ADR-003-vite-manifest-symlink.md](../../architecture/decision_log/ADR-003-vite-manifest-symlink.md)

   **Status**: ✅ Resolved
   **Time Lost**: ~15 minutes

#### Start Docker Services (11:45 - 12:00)

1. **Build and Start Containers**
   ```bash
   cd /var/www/paradocks
   docker-compose -f docker-compose.prod.yml build
   docker-compose -f docker-compose.prod.yml up -d
   ```

2. **Verify Services**
   ```bash
   docker-compose -f docker-compose.prod.yml ps

   # Expected output:
   # All services showing "Up" status
   ```

3. **Check Logs**
   ```bash
   docker-compose -f docker-compose.prod.yml logs
   # Multiple errors appeared (see Issue #6)
   ```

#### Database Setup (12:00 - 12:30)

1. **⚠️ ISSUE #6: MySQL Password Authentication Failure**

   **Problem**: Application couldn't connect to MySQL:
   ```
   SQLSTATE[HY000] [1045] Access denied for user 'paradocks'@'172.19.0.5' (using password: YES)
   ```

   **Investigation**:
   ```bash
   # Check MySQL logs
   docker-compose -f docker-compose.prod.yml logs mysql

   # MySQL started successfully
   # Root user created
   # paradocks user SHOULD be created by init script
   # But authentication failing
   ```

   **Root Cause**: MySQL password initialization timing issue. The MYSQL_PASSWORD variable wasn't properly applied during initial container creation.

   **Solution**: Manual password reset

   ```bash
   # Access MySQL as root
   docker-compose -f docker-compose.prod.yml exec mysql mysql -u root -p
   # Enter DB_ROOT_PASSWORD

   # Check user
   SELECT user, host FROM mysql.user WHERE user='paradocks';
   # User exists but password not set correctly

   # Reset password manually
   ALTER USER 'paradocks'@'%' IDENTIFIED BY 'ENDnAJtD+RtLl88WE0K1UUT/lSlQ6YYk';
   FLUSH PRIVILEGES;

   # Verify grants
   SHOW GRANTS FOR 'paradocks'@'%';
   # All privileges on paradocks.* confirmed

   # Test connection
   exit
   docker-compose -f docker-compose.prod.yml exec mysql mysql -u paradocks -pENDnAJtD+RtLl88WE0K1UUT/lSlQ6YYk paradocks
   # Success!
   ```

   **Status**: ✅ Resolved
   **Time Lost**: ~20 minutes

2. **Run Migrations**
   ```bash
   docker-compose -f docker-compose.prod.yml exec app php artisan migrate
   # Running migrations...
   # All migrations completed successfully
   ```

3. **Seed Database**
   ```bash
   docker-compose -f docker-compose.prod.yml exec app php artisan db:seed
   # Seeding database...
   # Admin user created
   ```

### Post-Deployment Configuration (12:30 - 13:00)

#### Application Optimization (12:30 - 12:40)

```bash
# Cache configuration
docker-compose -f docker-compose.prod.yml exec app php artisan config:cache

# Cache routes
docker-compose -f docker-compose.prod.yml exec app php artisan route:cache

# Cache views
docker-compose -f docker-compose.prod.yml exec app php artisan view:cache

# Optimize application
docker-compose -f docker-compose.prod.yml exec app php artisan optimize

# Verify caches
docker-compose -f docker-compose.prod.yml exec app php artisan about
```

#### Storage Link (12:40 - 12:45)

```bash
# Create storage symlink
docker-compose -f docker-compose.prod.yml exec app php artisan storage:link
# The [public/storage] link has been connected to [storage/app/public]
```

#### Queue Configuration (12:45 - 12:50)

```bash
# Restart Horizon
docker-compose -f docker-compose.prod.yml restart horizon

# Check Horizon status
docker-compose -f docker-compose.prod.yml exec app php artisan horizon:status
# Horizon is running.

# Verify scheduler
docker-compose -f docker-compose.prod.yml logs scheduler
# Running schedule:run every minute (confirmed)
```

#### SSL Preparation (12:50 - 13:00)

```bash
# Install Certbot
apt install -y certbot python3-certbot-nginx

# Verify installation
certbot --version
# certbot 2.x.x

# Not configured yet - pending domain setup
# Added to next steps
```

### Verification & Testing (13:00 - 13:30)

#### Health Checks

1. **Service Status**
   ```bash
   docker-compose -f docker-compose.prod.yml ps

   NAME                    STATUS
   paradocks-app           Up (healthy)
   paradocks-nginx         Up (healthy)
   paradocks-mysql         Up (healthy)
   paradocks-redis         Up (healthy)
   paradocks-horizon       Up (healthy)
   paradocks-scheduler     Up
   ```

2. **Application Response**
   ```bash
   curl -I http://72.60.17.138

   HTTP/1.1 200 OK
   Server: nginx/1.25.5
   Content-Type: text/html; charset=UTF-8
   ```

3. **Admin Panel Access**
   ```bash
   curl -I http://72.60.17.138/admin

   HTTP/1.1 302 Found
   Location: http://72.60.17.138/admin/login
   # Redirect to login (correct behavior)
   ```

4. **Database Connection**
   ```bash
   docker-compose -f docker-compose.prod.yml exec app php artisan tinker

   >>> \DB::connection()->getPdo();
   # PDO object returned (connection successful)

   >>> \DB::table('users')->count();
   # 1 (admin user exists)
   ```

5. **Redis Connection**
   ```bash
   docker-compose -f docker-compose.prod.yml exec app php artisan tinker

   >>> \Redis::ping();
   # "+PONG" (connection successful)

   >>> \Cache::put('test', 'value', 60);
   >>> \Cache::get('test');
   # "value" (cache working)
   ```

6. **Queue System**
   ```bash
   # Dispatch test job
   docker-compose -f docker-compose.prod.yml exec app php artisan tinker
   >>> dispatch(new \App\Jobs\TestJob());

   # Check Horizon
   # Job processed successfully
   ```

#### Manual Testing

1. **Login to Admin Panel**
   - URL: http://72.60.17.138/admin
   - Email: admin@paradocks.com
   - Password: Admin123!
   - Result: ✅ Login successful

2. **Test Filament Features**
   - Navigation: ✅ Working
   - Dashboard: ✅ Loads correctly
   - Resources: ✅ CRUD operations working
   - Notifications: ✅ Toast notifications appearing

3. **Test File Upload**
   - Upload test image
   - Verify storage/app/public
   - Result: ✅ File saved correctly

4. **Test Queue Processing**
   - Trigger job that sends notification
   - Check Horizon dashboard
   - Result: ✅ Job processed in <1s

### Deployment Summary

**Total Time**: ~4.5 hours (including research and troubleshooting)

**Issues Encountered**: 6
**Issues Resolved**: 6
**Issues Pending**: 0 (for basic deployment)

**Success Metrics**:
- ✅ All Docker containers running and healthy
- ✅ Application accessible via HTTP
- ✅ Database migrations successful
- ✅ Admin panel functional
- ✅ Queue system operational
- ✅ File uploads working
- ✅ Cache system functional

**Known Limitations** (documented for next phase):
- ⏳ HTTPS not configured (Certbot installed but not run)
- ⏳ Email using log driver (Gmail SMTP not configured)
- ⏳ No backup system implemented
- ⏳ Monitoring tools not installed
- ⏳ Admin password is temporary

**Workarounds Implemented**:
1. UFW-Docker script for firewall security
2. Removed storage Docker volumes, using bind mounts
3. Created Vite manifest symlink
4. Manual MySQL password reset
5. Custom Nginx config without Node.js

**Architecture Decisions Recorded**:
- [ADR-001: UFW-Docker Security](../../architecture/decision_log/ADR-001-ufw-docker-security.md)
- [ADR-002: Storage Volume Removal](../../architecture/decision_log/ADR-002-storage-volume-removal.md)
- [ADR-003: Vite Manifest Symlink](../../architecture/decision_log/ADR-003-vite-manifest-symlink.md)

---

## Post-Deployment Actions Completed

**Date**: 2025-11-11 (13:30 - 14:00)

1. **Documentation Created**
   - Server information documented
   - Credentials securely stored
   - Configuration files documented
   - Workarounds documented

2. **Monitoring Setup**
   ```bash
   # Basic monitoring commands tested
   htop              # System resources
   docker stats      # Container resources
   ufw status        # Firewall status
   ```

3. **Backup Preparation**
   - Database backup command tested:
   ```bash
   docker-compose -f docker-compose.prod.yml exec mysql mysqldump -u paradocks -p paradocks > backup_test.sql
   # Backup successful (test file created)
   ```

4. **Access Documentation**
   - Created quick reference card (00-SERVER-INFO.md)
   - Documented all credentials (03-CREDENTIALS.md)
   - Created maintenance procedures (06-MAINTENANCE.md)

---

## Pending Tasks

See: [07-NEXT-STEPS.md](07-NEXT-STEPS.md)

**High Priority**:
- [ ] Configure SSL/HTTPS with Let's Encrypt
- [ ] Change admin password from temporary Admin123!
- [ ] Configure Gmail SMTP for email notifications
- [ ] Implement automated backup system
- [ ] Set up monitoring and alerting

**Medium Priority**:
- [ ] Configure domain name (if available)
- [ ] Implement log rotation
- [ ] Set up external monitoring (UptimeRobot, etc.)
- [ ] Performance baseline testing

**Low Priority**:
- [ ] Consider CDN for static assets
- [ ] Implement rate limiting
- [ ] Set up staging-specific features (debug bar if needed)

---

## Lessons Learned

1. **Docker + UFW**: Always use ufw-docker or similar to prevent Docker from bypassing firewall rules
2. **Storage Volumes**: Bind mounts with proper permissions are more reliable than Docker volumes for development/staging
3. **Vite + Laravel**: Understand build output paths - symlinks are a simple solution for path mismatches
4. **MySQL Initialization**: Always verify database user creation and password setting in containerized environments
5. **Environment Files**: Double-check .env.example - commented variables can cause confusion
6. **Branch Awareness**: Deploy from correct branch (staging, not main) - verify before deployment

---

## Next Deployment Checklist

When deploying updates to staging:

1. **Pre-Deployment**
   - [ ] Backup database
   - [ ] Backup .env file
   - [ ] Note current git commit hash
   - [ ] Notify team of deployment window

2. **Deployment**
   - [ ] Pull latest changes from staging branch
   - [ ] Check for .env changes (compare with .env.example)
   - [ ] Rebuild assets if needed (`npm run build`)
   - [ ] Rebuild Docker images if Dockerfile changed
   - [ ] Run migrations (`php artisan migrate`)
   - [ ] Clear caches (`php artisan optimize:clear`)
   - [ ] Rebuild caches (`php artisan optimize`)
   - [ ] Restart services (`docker-compose restart`)

3. **Verification**
   - [ ] Check all containers running (`docker-compose ps`)
   - [ ] Test application homepage
   - [ ] Test admin panel login
   - [ ] Check queue processing (Horizon)
   - [ ] Review logs for errors

4. **Post-Deployment**
   - [ ] Update deployment log (this file)
   - [ ] Update next steps if needed
   - [ ] Document any new issues/workarounds
   - [ ] Create ADR if architectural changes made

---

**Deployment Log Maintained By**: DevOps Team
**Last Updated**: 2025-11-11 14:00
**Next Planned Deployment**: TBD

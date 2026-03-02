# ADR-007: UFW-Docker Security Integration

**Status**: Accepted and Implemented
**Date**: 2025-11-11
**Decision Makers**: DevOps Team
**Environment**: Staging VPS (72.60.17.138)
**Technical Story**: Security vulnerability discovered during initial deployment

---

## Context

During the initial deployment of the staging environment, we discovered that Docker was bypassing the UFW (Uncomplicated Firewall) rules, exposing container ports directly to the internet despite firewall configuration.

### The Problem

**Initial Setup**:
- UFW configured with rules allowing only ports 22 (SSH), 80 (HTTP), and 443 (HTTPS)
- Docker containers exposing ports 3306 (MySQL) and 6379 (Redis) via `docker-compose.yml`
- Expectation: MySQL and Redis should only be accessible from localhost or within Docker network

**Actual Behavior**:
- MySQL port 3306 was accessible from any external IP address
- Redis port 6379 was accessible from any external IP address
- UFW rules were being completely bypassed for Docker-exposed ports

**Verification**:
```bash
# UFW showed no rules for 3306 or 6379
$ sudo ufw status
Status: active
To                         Action      From
--                         ------      ----
22/tcp                     ALLOW       Anywhere
80/tcp                     ALLOW       Anywhere
443/tcp                    ALLOW       Anywhere

# But nmap showed ports were open
$ nmap -p 3306,6379 72.60.17.138
PORT     STATE SERVICE
3306/tcp open  mysql
6379/tcp open  redis

# iptables showed Docker rules bypassing UFW
$ sudo iptables -L DOCKER-USER
Chain DOCKER-USER (1 references)
target     prot opt source               destination
RETURN     all  --  anywhere             anywhere
# No filtering rules!
```

### Root Cause

Docker modifies iptables directly, adding rules to the FORWARD chain that take precedence over UFW's INPUT chain rules. This is by design for Docker networking to work, but creates a security issue:

1. UFW adds rules to the `INPUT` chain
2. Docker adds rules to the `FILTER` table's `FORWARD` chain
3. Docker's `DOCKER-USER` chain is empty by default
4. All traffic to Docker containers bypasses UFW rules entirely

**Why This Matters**:

Even with password protection on MySQL and Redis:
- Brute force attacks are possible
- DDoS attacks can target these services
- Vulnerabilities in MySQL/Redis could be exploited
- Database exposure increases attack surface
- Best practice: Databases should never be directly exposed to internet

---

## Decision

**Implement UFW-Docker integration using the `ufw-docker` helper script.**

This script adds proper filtering rules to Docker's `DOCKER-USER` chain, ensuring that UFW rules are respected for Docker-exposed ports.

### Why This Solution?

1. **Minimal Changes**: No modification to Docker daemon configuration required
2. **Maintains Functionality**: Docker networking continues to work normally
3. **Transparent**: Application code and docker-compose files unchanged
4. **Proven Solution**: Widely used in production environments
5. **Easy to Maintain**: Simple script, easy to understand and audit

---

## Alternatives Considered

### Option A: Don't Expose Ports at All

**Description**: Remove port exposure from docker-compose.yml entirely.

```yaml
# Change from:
mysql:
  ports:
    - "3306:3306"

# To:
mysql:
  # No ports section - only accessible within Docker network
```

**Pros**:
- Maximum security - no external access possible
- Simplest solution
- No additional tools needed

**Cons**:
- Cannot connect with external tools (MySQL Workbench, TablePlus, RedisInsight)
- Cannot monitor from external monitoring services
- Makes debugging and maintenance difficult
- Would need SSH tunneling for every external connection

**Verdict**: ❌ Rejected - Too restrictive for development/staging environment

---

### Option B: Modify Docker Daemon Configuration

**Description**: Configure Docker daemon to use a different iptables table or disable iptables management.

```json
// /etc/docker/daemon.json
{
  "iptables": false
}
```

**Pros**:
- Complete control over firewall rules
- Docker won't interfere with iptables

**Cons**:
- Breaks Docker networking entirely
- Requires manual iptables rules for all containers
- Complex to maintain
- Easy to misconfigure and break containers
- Risk of network isolation issues

**Verdict**: ❌ Rejected - Too complex, breaks Docker functionality

---

### Option C: Use Custom Iptables Rules

**Description**: Manually add iptables rules to DOCKER-USER chain.

```bash
iptables -I DOCKER-USER -p tcp --dport 3306 -j DROP
iptables -I DOCKER-USER -p tcp --dport 6379 -j DROP
iptables -I DOCKER-USER -i eth0 -j DROP
iptables -I DOCKER-USER -i eth0 -m conntrack --ctstate ESTABLISHED,RELATED -j RETURN
```

**Pros**:
- Direct control over rules
- No third-party tools
- Flexible configuration

**Cons**:
- Rules don't persist across Docker restarts
- Requires manual rule management
- Easy to make mistakes
- Hard to integrate with UFW
- Need to recreate rules after every Docker update

**Verdict**: ❌ Rejected - Not maintainable, rules don't persist

---

### Option D: Use UFW-Docker Script (CHOSEN)

**Description**: Install `ufw-docker` helper script that integrates UFW with Docker.

**Pros**:
- ✅ Integrates seamlessly with UFW
- ✅ Rules persist across reboots
- ✅ Easy to install and configure
- ✅ Well-maintained open-source project
- ✅ No Docker daemon configuration changes
- ✅ Docker networking continues to work
- ✅ Simple to understand and audit
- ✅ Can allow specific ports if needed

**Cons**:
- Requires third-party script (though widely trusted)
- Adds one more component to maintain

**Verdict**: ✅ ACCEPTED - Best balance of security and usability

---

### Option E: IP-Based Restriction in docker-compose.yml

**Description**: Bind ports to localhost only.

```yaml
mysql:
  ports:
    - "127.0.0.1:3306:3306"  # Only accessible from localhost

redis:
  ports:
    - "127.0.0.1:6379:6379"
```

**Pros**:
- Simple configuration change
- No additional tools needed
- Secure against external access

**Cons**:
- Still accessible from localhost (any user on server)
- Cannot access from development machine without SSH tunnel
- Doesn't solve the underlying UFW bypass issue
- Other containers might still expose ports

**Verdict**: ⚠️ Partial solution - Could be used in combination, but doesn't address root cause

---

## Implementation

### Installation Steps

```bash
# 1. Download ufw-docker script
sudo wget -O /usr/local/bin/ufw-docker \
  https://github.com/chaifeng/ufw-docker/raw/master/ufw-docker

# 2. Make executable
sudo chmod +x /usr/local/bin/ufw-docker

# 3. Install UFW rules
sudo ufw-docker install

# 4. Reload UFW to apply changes
sudo systemctl restart ufw
```

### Verification

```bash
# Check DOCKER-USER chain now has filtering rules
sudo iptables -L DOCKER-USER -n

# Output shows filtering rules:
Chain DOCKER-USER (1 references)
target     prot opt source               destination
ufw-user-forward  all  --  0.0.0.0/0   0.0.0.0/0
DROP       all  --  0.0.0.0/0            0.0.0.0/0
```

### How It Works

1. The script adds rules to Docker's `DOCKER-USER` chain
2. These rules forward traffic to UFW's `ufw-user-forward` chain
3. UFW rules are now applied before Docker's permissive rules
4. Default policy becomes DROP (deny) instead of ACCEPT
5. Only explicitly allowed traffic can reach containers

### Allowing Specific Container Ports (if needed)

```bash
# Allow public access to a specific service
sudo ufw-docker allow container-name 8080/tcp

# Example: Allow external nginx access
sudo ufw-docker allow paradocks-nginx 80/tcp
sudo ufw-docker allow paradocks-nginx 443/tcp
```

### Current Configuration

For staging environment, we keep the default setup:
- **Ports 80, 443**: Allowed (nginx) - needed for web access
- **Port 3306**: Blocked externally (MySQL) - password protected but not exposed
- **Port 6379**: Blocked externally (Redis) - password protected but not exposed
- **Port 22**: Allowed (SSH) - key authentication only

MySQL and Redis remain accessible:
- Within Docker network (containers can communicate)
- Via SSH tunnel from development machines
- From localhost on the server itself

---

## Consequences

### Positive Consequences

1. **Enhanced Security**:
   - Database ports no longer exposed to internet
   - Reduced attack surface
   - UFW rules now properly protect Docker containers
   - Aligned with security best practices

2. **Maintains Functionality**:
   - Docker networking unchanged
   - Containers communicate normally
   - No application code changes needed
   - Development workflow unchanged

3. **Operational Benefits**:
   - Rules persist across reboots
   - Integrates with existing UFW configuration
   - Easy to allow specific services if needed
   - Clear audit trail of firewall rules

4. **Defense in Depth**:
   - Password protection + firewall restriction
   - Multiple layers of security
   - Reduced risk even if password compromised

### Negative Consequences

1. **External Access Complexity**:
   - External tools (MySQL Workbench, etc.) need SSH tunneling
   - Additional step for database administration
   - Slightly more complex for developers

   **Mitigation**: Document SSH tunnel setup in 00-SERVER-INFO.md

2. **Monitoring Integration**:
   - External monitoring services can't directly access MySQL/Redis
   - Need agent-based monitoring instead
   - Or allow specific monitoring service IPs

   **Mitigation**: Use Horizon dashboard for queues, implement agent-based monitoring

3. **Dependency on Third-Party Script**:
   - Relies on chaifeng/ufw-docker maintenance
   - Potential compatibility issues with future Docker versions
   - One more component to monitor

   **Mitigation**: Script is widely used, actively maintained, and simple enough to fork if needed

### Neutral Consequences

1. **SSH Tunnel Workflow**:
   ```bash
   # Developers need to use SSH tunnels for database access
   ssh -L 3306:localhost:3306 ubuntu@72.60.17.138
   # Then connect to localhost:3306
   ```

2. **Documentation Updates**:
   - Need to document SSH tunnel usage
   - Update connection instructions
   - Already done in 00-SERVER-INFO.md

---

## Lessons Learned

1. **Docker and Firewalls**:
   - Always verify firewall rules after Docker installation
   - Don't assume UFW/firewalld will protect Docker ports
   - Test port exposure from external network

2. **Security Testing**:
   - Use nmap or similar tools to verify actual port exposure
   - Check iptables rules, not just UFW status
   - Test from external IP address

3. **Defense in Depth**:
   - Never rely on single security measure
   - Always use password protection even with firewall
   - Combine network-level and application-level security

4. **Documentation**:
   - Document security decisions clearly
   - Explain WHY decisions were made
   - Provide workarounds for legitimate use cases

---

## Future Considerations

### For Production Environment

1. **No External Database Access**:
   - Remove all port exposure in production
   - Use bastion host for administrative access
   - Implement strict network segmentation

2. **VPN Access**:
   - Consider VPN for administrative access
   - WireGuard or OpenVPN for developer access
   - Eliminate need for exposed ports entirely

3. **Cloud-Native Solutions**:
   - Use cloud provider's security groups
   - Implement network ACLs
   - Consider managed database services

### Monitoring

1. **Regular Audits**:
   - Monthly review of exposed ports
   - Quarterly security audit
   - Automated port scanning

2. **Alerting**:
   - Alert on unexpected port exposure
   - Monitor iptables rule changes
   - Track UFW configuration changes

---

## References

- **ufw-docker GitHub**: https://github.com/chaifeng/ufw-docker
- **Docker and iptables**: https://docs.docker.com/network/iptables/
- **UFW Documentation**: https://help.ubuntu.com/community/UFW
- **Deployment Log**: [../../environments/staging/01-DEPLOYMENT-LOG.md](../../environments/staging/01-DEPLOYMENT-LOG.md#issue-1-docker-bypassing-ufw-firewall)
- **Issues & Workarounds**: [../../environments/staging/05-ISSUES-WORKAROUNDS.md](../../environments/staging/05-ISSUES-WORKAROUNDS.md#issue-1-docker-bypassing-ufw-firewall)

---

## Related ADRs

None yet. This is the first ADR for the project.

---

**Author**: DevOps Team
**Reviewers**: Development Team
**Approved**: 2025-11-11
**Implementation**: 2025-11-11 (during initial deployment)
**Last Updated**: 2025-11-11

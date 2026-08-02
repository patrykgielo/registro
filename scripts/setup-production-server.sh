#!/bin/bash
###############################################################################
# Registro production server bootstrap
#
# Prepares a bare Ubuntu 24.04 VPS to run Registro. Machine only -- it installs
# no application, pulls no image, touches no database.
#
# Unlike setup-staging-server.sh (which drives a remote host over SSH from a
# laptop), this runs ON the server, as root:
#
#   scp scripts/setup-production-server.sh scripts/server/deploy.sh root@HOST:/root/
#   ssh root@HOST 'bash /root/setup-production-server.sh'
#
# Idempotent: safe to re-run. Every step checks its own end state first, so a
# half-finished run can simply be repeated.
#
# Exit codes: 0 - ok, 1 - usage/environment error, 2 - verification failed
###############################################################################

set -euo pipefail

readonly DEPLOY_USER="deploy"
readonly PROJECT_DIR="/var/www/registro"
readonly DEPLOY_SCRIPT_DIR="/opt/registro"
readonly SWAP_FILE="/swapfile"
readonly SWAP_SIZE_MB=2048
readonly TIMEZONE="Europe/Warsaw"

readonly GREEN='\033[0;32m'
readonly YELLOW='\033[1;33m'
readonly RED='\033[0;31m'
readonly NC='\033[0m'

log()  { echo -e "${GREEN}[+]${NC} $*"; }
warn() { echo -e "${YELLOW}[!]${NC} $*"; }
die()  { echo -e "${RED}[x]${NC} $*" >&2; exit "${2:-1}"; }

[ "$(id -u)" -eq 0 ] || die "run as root"
[ -r /etc/os-release ] && . /etc/os-release
[ "${ID:-}" = "ubuntu" ] || warn "not Ubuntu (${ID:-unknown}) -- proceeding anyway"

###############################################################################
log "System packages"
###############################################################################

export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get upgrade -y -qq
apt-get install -y -qq \
    ca-certificates curl wget git gnupg lsb-release unzip \
    ufw htop vim certbot unattended-upgrades jq

timedatectl set-timezone "$TIMEZONE"
log "Timezone: $(timedatectl show -p Timezone --value)"

# Security updates only, applied automatically. Reboots stay manual.
cat >/etc/apt/apt.conf.d/20auto-upgrades <<'EOF'
APT::Periodic::Update-Package-Lists "1";
APT::Periodic::Unattended-Upgrade "1";
EOF

###############################################################################
log "Swap (${SWAP_SIZE_MB} MB)"
###############################################################################

# A small VPS running MySQL + Redis + PHP-FPM + Horizon has no headroom.
# Migrations and queue bursts are where the OOM killer usually shows up.
# `swapon --show | grep -q .` would be the obvious form and is the exact
# pipefail trap this script warns about further down: grep -q exits on the first
# line, the writer takes SIGPIPE, and the pipeline reports failure even though
# the match succeeded. It happens to survive today only because the output is
# small enough that swapon usually finishes writing first.
if [ -n "$(swapon --show --noheadings)" ]; then
    log "Swap already active: $(swapon --show=NAME,SIZE --noheadings | tr '\n' ' ')"
else
    fallocate -l "${SWAP_SIZE_MB}M" "$SWAP_FILE" || dd if=/dev/zero of="$SWAP_FILE" bs=1M count="$SWAP_SIZE_MB"
    chmod 600 "$SWAP_FILE"
    mkswap "$SWAP_FILE" >/dev/null
    swapon "$SWAP_FILE"
    grep -q "^${SWAP_FILE}" /etc/fstab || echo "${SWAP_FILE} none swap sw 0 0" >>/etc/fstab
    log "Swap enabled"
fi

sysctl -q -w vm.swappiness=10
grep -q '^vm.swappiness' /etc/sysctl.conf || echo 'vm.swappiness=10' >>/etc/sysctl.conf

###############################################################################
log "Docker"
###############################################################################

if ! command -v docker >/dev/null 2>&1; then
    curl -fsSL https://get.docker.com -o /tmp/get-docker.sh
    sh /tmp/get-docker.sh
    rm -f /tmp/get-docker.sh
fi
apt-get install -y -qq docker-compose-plugin
systemctl enable --now docker

# Without log rotation the JSON log files grow until the disk is full -- weeks,
# not months. Merge rather than overwrite: cloud images ship their own
# daemon.json (this VPS came with default-address-pools), so writing the file
# wholesale would discard the provider's settings, while skipping it entirely
# when the file exists would silently leave rotation unconfigured.
mkdir -p /etc/docker
DESIRED_DAEMON='{"log-driver":"json-file","log-opts":{"max-size":"10m","max-file":"3"},"live-restore":true}'

if [ -s /etc/docker/daemon.json ]; then
    current="$(cat /etc/docker/daemon.json)"
else
    current='{}'
fi

merged="$(jq -S --argjson want "$DESIRED_DAEMON" '. * $want' <<<"$current")" \
    || die "/etc/docker/daemon.json is not valid JSON -- fix it by hand" 2

if [ "$(jq -S . <<<"$current")" = "$merged" ]; then
    log "Docker daemon.json already correct"
else
    cp /etc/docker/daemon.json "/etc/docker/daemon.json.bak-$(date +%s)" 2>/dev/null || true
    printf '%s\n' "$merged" >/etc/docker/daemon.json
    systemctl restart docker
    log "Docker daemon.json updated (log rotation + live-restore), previous file backed up"
fi

docker --version
docker compose version

###############################################################################
log "User: ${DEPLOY_USER}"
###############################################################################

if ! id -u "$DEPLOY_USER" >/dev/null 2>&1; then
    adduser --disabled-password --gecos "" "$DEPLOY_USER"
fi
usermod -aG docker "$DEPLOY_USER"

# Deliberately NOT in sudo. Membership in `docker` is already root-equivalent
# (docker run -v /:/host), so adding sudo buys nothing and widens what a
# compromised CI key reaches. Administration happens as root over a separate key.
# Same pipefail reason as the swap check above: match on a captured string, not
# through a pipe into grep -q.
if [[ " $(id -nG "$DEPLOY_USER") " == *" sudo "* ]]; then
    warn "${DEPLOY_USER} is in the sudo group -- remove it: deluser ${DEPLOY_USER} sudo"
fi

install -d -m 700 -o "$DEPLOY_USER" -g "$DEPLOY_USER" "/home/${DEPLOY_USER}/.ssh"
if [ ! -s "/home/${DEPLOY_USER}/.ssh/authorized_keys" ]; then
    if [ -s /root/.ssh/authorized_keys ]; then
        cp /root/.ssh/authorized_keys "/home/${DEPLOY_USER}/.ssh/authorized_keys"
        chown "${DEPLOY_USER}:${DEPLOY_USER}" "/home/${DEPLOY_USER}/.ssh/authorized_keys"
        chmod 600 "/home/${DEPLOY_USER}/.ssh/authorized_keys"
        warn "Seeded ${DEPLOY_USER}'s authorized_keys from root's -- replace with the CI key"
        warn "and prepend the forced command before wiring up GitHub Actions (see below)."
    else
        warn "No authorized_keys for ${DEPLOY_USER} -- add the CI key before deploying"
    fi
fi

###############################################################################
log "Directories"
###############################################################################

install -d -m 755 -o "$DEPLOY_USER" -g "$DEPLOY_USER" "$PROJECT_DIR"
install -d -m 755 -o root -g root "$DEPLOY_SCRIPT_DIR"

# The forced-command target must not be writable by the account it constrains.
if [ -f "$(dirname "$0")/server/deploy.sh" ]; then
    install -m 755 -o root -g root "$(dirname "$0")/server/deploy.sh" "${DEPLOY_SCRIPT_DIR}/deploy.sh"
    log "Installed ${DEPLOY_SCRIPT_DIR}/deploy.sh"
elif [ -f /root/deploy.sh ]; then
    install -m 755 -o root -g root /root/deploy.sh "${DEPLOY_SCRIPT_DIR}/deploy.sh"
    log "Installed ${DEPLOY_SCRIPT_DIR}/deploy.sh from /root/deploy.sh"
else
    warn "deploy.sh not found -- copy scripts/server/deploy.sh to ${DEPLOY_SCRIPT_DIR}/deploy.sh manually"
fi

install -m 664 -o "$DEPLOY_USER" -g "$DEPLOY_USER" /dev/null /var/log/registro-deploy.log 2>/dev/null || true
install -d -m 755 -o "$DEPLOY_USER" -g "$DEPLOY_USER" /var/backups/registro

# Certificate reconciliation.
#
# A wildcard certificate is not obtainable on a hostname inside Hostinger's own
# hstgr.cloud zone -- Let's Encrypt issues wildcards only via DNS-01, and the
# _acme-challenge TXT would have to be published in a zone we do not control.
# Instead one certificate carries every tenant subdomain as a SAN, re-issued
# over HTTP-01 whenever the tenant list changes. Root-owned because certbot
# writes /etc/letsencrypt and nginx has to be reloaded.
if [ -f "$(dirname "$0")/server/sync-certificate.sh" ]; then
    install -m 755 -o root -g root "$(dirname "$0")/server/sync-certificate.sh" \
        "${DEPLOY_SCRIPT_DIR}/sync-certificate.sh"
    log "Installed ${DEPLOY_SCRIPT_DIR}/sync-certificate.sh"
elif [ -f /root/sync-certificate.sh ]; then
    install -m 755 -o root -g root /root/sync-certificate.sh "${DEPLOY_SCRIPT_DIR}/sync-certificate.sh"
    log "Installed ${DEPLOY_SCRIPT_DIR}/sync-certificate.sh from /root/"
else
    warn "sync-certificate.sh not found -- tenant subdomains will show certificate warnings"
fi

install -m 644 -o root -g root /dev/null /var/log/registro-certificate.log 2>/dev/null || true

# Every 15 minutes: the script exits immediately when the name set is unchanged,
# so this costs nothing until a tenant is added or removed. It bounds the window
# in which a brand-new tenant's subdomain still shows a browser warning.
cat >/etc/cron.d/registro-certificate <<'CRON'
# Reconcile the TLS certificate with the live tenant list (see /opt/registro/sync-certificate.sh)
*/15 * * * * root /opt/registro/sync-certificate.sh >/dev/null 2>&1
CRON
chmod 644 /etc/cron.d/registro-certificate
log "Installed /etc/cron.d/registro-certificate (every 15 min, no-op when unchanged)"

###############################################################################
log "SSH hardening"
###############################################################################

# The filename must sort FIRST, not last. sshd keeps the FIRST value it sees for
# a keyword -- the opposite of nearly every other drop-in config system -- and
# Ubuntu's cloud images ship /etc/ssh/sshd_config.d/50-cloud-init.conf containing
# `PasswordAuthentication yes`. A file named 99-registro.conf is read after it
# and is therefore completely inert: the box goes on accepting password logins
# while the config file says otherwise. Observed on this exact server.
rm -f /etc/ssh/sshd_config.d/99-registro.conf
cat >/etc/ssh/sshd_config.d/00-registro.conf <<'EOF'
PasswordAuthentication no
PermitRootLogin prohibit-password
KbdInteractiveAuthentication no
X11Forwarding no
MaxAuthTries 3
EOF

sshd -t || die "sshd config invalid -- NOT restarting sshd, fix /etc/ssh/sshd_config.d/00-registro.conf" 2
systemctl reload ssh

# Assert the value actually took effect rather than trusting that writing the
# file was enough -- that assumption is what this whole block exists to correct.
#
# Every directive written above is verified, not just the first one. Checking
# only PasswordAuthentication would leave the same first-wins trap open for the
# other four -- and KbdInteractiveAuthentication in particular is the standard
# way password logins survive `PasswordAuthentication no`, because PAM answers
# the keyboard-interactive prompt instead.
#
# `sshd -T` is captured ONCE into a variable, and every lookup reads that string.
#
# The obvious form, `sshd -T | awk '$1 == k {print $2; exit}'`, is the exact
# SIGPIPE-under-pipefail trap this script warns about for `grep -q` -- awk's
# `exit` closes the pipe before sshd has finished writing, sshd dies of SIGPIPE,
# and `set -o pipefail` propagates 141 out of the assignment. Under `set -e` the
# script then dies SILENTLY, mid-run, with no message at all.
#
# That is not hypothetical: it is what killed the first real bootstrap of this
# server on 2026-08-02, at exactly this point. Measured on that host, 9 of 10
# runs returned 141. It survived earlier runs only because there was a single
# lookup instead of five, so the race was rolled once rather than five times.
SSHD_EFFECTIVE="$(sshd -T 2>/dev/null || true)"
[ -n "$SSHD_EFFECTIVE" ] || die "sshd -T produced no output -- cannot verify SSH hardening" 2

# No early `exit`: awk consumes the whole string, so nothing can take SIGPIPE.
sshd_value() {
    printf '%s\n' "$SSHD_EFFECTIVE" \
        | awk -v k="$1" '$1 == k && !seen { v = $2; seen = 1 } END { print v }'
}

# Variadic: several directives have more than one accepted spelling.
assert_sshd() {
    local keyword="$1" actual expected
    shift
    actual="$(sshd_value "$keyword")"
    for expected in "$@"; do
        [ "$actual" = "$expected" ] && return 0
    done
    die "sshd ${keyword} is '${actual:-unset}', expected one of: $* -- another sshd_config.d file sorts before 00-registro.conf" 2
}

# The assertions themselves run AFTER the firewall block, not here. They are
# hard failures by design, and aborting at this point would leave the box with
# no ufw at all -- trading a possible SSH misconfiguration for a guaranteed
# absence of a firewall, which is the worse of the two.

###############################################################################
log "Firewall"
###############################################################################

# This host publishes an AAAA record and serves over IPv6, so the firewall must
# cover it. Ubuntu ships IPV6=yes, but an inherited /etc/default/ufw may not --
# and with it off, ufw silently protects only half the attack surface.
if grep -q '^IPV6=no' /etc/default/ufw 2>/dev/null; then
    sed -i 's/^IPV6=no/IPV6=yes/' /etc/default/ufw
    warn "Enabled IPV6=yes in /etc/default/ufw"
fi

ufw --force reset >/dev/null
ufw default deny incoming >/dev/null
ufw default allow outgoing >/dev/null
ufw allow 22/tcp >/dev/null
ufw allow 80/tcp >/dev/null
ufw allow 443/tcp >/dev/null
ufw --force enable >/dev/null

# Plain ufw does not cover ports Docker publishes: Docker inserts its own rules
# into the DOCKER-USER chain, ahead of ufw's. Without ufw-docker, a published
# 3306 is reachable from the internet with ufw reporting "deny incoming".
if [ ! -x /usr/local/bin/ufw-docker ]; then
    wget -q -O /usr/local/bin/ufw-docker \
        https://github.com/chaifeng/ufw-docker/raw/master/ufw-docker
    chmod +x /usr/local/bin/ufw-docker
fi
/usr/local/bin/ufw-docker install >/dev/null

# `ufw-docker install` is DEFAULT-DENY for everything Docker publishes. Without
# the two rules below the site is unreachable from the internet the moment nginx
# comes up -- fails closed, but it looks like a broken application, not a
# firewall. The `ufw allow 80/443` rules above do NOT cover this: they apply to
# the INPUT chain, while container traffic is routed through DOCKER-USER.
#
# Deliberately the port-based `ufw route` form rather than
# `ufw-docker allow registro-nginx 80`. The latter resolves and pins the
# container's current IP, which changes every time `up -d` recreates it, and
# repairing that needs `ufw-docker reload` as root -- something the deploy user
# cannot run. Port-based rules survive container recreation untouched.
ufw route allow proto tcp from any to any port 80 >/dev/null
ufw route allow proto tcp from any to any port 443 >/dev/null

# MySQL and Redis are deliberately absent: they are reachable only on the
# compose network. If a published 3306 ever appears, this default-deny is what
# keeps it off the internet.

systemctl restart ufw
ufw status verbose

###############################################################################
log "SSH hardening -- assertions"
###############################################################################

# Deferred from the SSH block above so that a failure here leaves a firewalled
# machine rather than an open one. See the note there.
assert_sshd passwordauthentication no
# `prohibit-password` is what the config file says; `sshd -T` normalises it to
# the older synonym `without-password` on OpenSSH as shipped with Ubuntu 24.04.
# Both mean key-only root login. Asserting only the modern spelling failed on the
# real server even though the setting was correct.
assert_sshd permitrootlogin prohibit-password without-password
assert_sshd kbdinteractiveauthentication no
assert_sshd x11forwarding no
assert_sshd maxauthtries 3
log "SSH: password + keyboard-interactive auth disabled, root login key-only"

###############################################################################
log "Verification"
###############################################################################

fail=0
# NOTE: never use `grep -q` inside these expressions. This script runs under
# `set -o pipefail`, and grep -q closes the pipe on first match, so the upstream
# command dies of SIGPIPE and the pipeline reports failure on a PASSING check.
# Plain grep reads the whole stream; the redirect below discards its output.
check() {
    if eval "$2" >/dev/null 2>&1; then
        echo -e "  ${GREEN}ok${NC}   $1"
    else
        echo -e "  ${RED}FAIL${NC} $1"
        fail=1
    fi
}

check "docker runs"                       "docker run --rm hello-world"
check "${DEPLOY_USER} can use docker"     "su - ${DEPLOY_USER} -c 'docker ps'"
check "${PROJECT_DIR} owned by ${DEPLOY_USER}" "[ \"\$(stat -c %U ${PROJECT_DIR})\" = ${DEPLOY_USER} ]"
check "deploy.sh installed root:root"     "[ \"\$(stat -c '%U:%a' ${DEPLOY_SCRIPT_DIR}/deploy.sh)\" = 'root:755' ]"
check "deploy.sh not writable by ${DEPLOY_USER}" "! su - ${DEPLOY_USER} -c 'test -w ${DEPLOY_SCRIPT_DIR}/deploy.sh'"
check "swap active"                       "swapon --show --noheadings | grep ."
check "ufw active"                        "ufw status | grep 'Status: active'"
check "ufw covers IPv6"                   "grep '^IPV6=yes' /etc/default/ufw"
# Without these two the DOCKER-USER default-deny installed above silently drops
# all traffic to the published container ports.
check "ufw routes port 80 to containers"  "ufw status | grep '^80/tcp .*ALLOW FWD'"
check "ufw routes port 443 to containers" "ufw status | grep '^443/tcp .*ALLOW FWD'"
# Via sshd_value, not a fresh `sshd -T | awk ... exit` pipeline: see the note on
# SSHD_EFFECTIVE above. These are the checks whose own comment block warns about
# exactly this, so getting it wrong here twice would be its own indictment.
check "password auth disabled"             "[ \"\$(sshd_value passwordauthentication)\" = no ]"
check "keyboard-interactive auth disabled" "[ \"\$(sshd_value kbdinteractiveauthentication)\" = no ]"
check "root login key-only"                "case \"\$(sshd_value permitrootlogin)\" in prohibit-password|without-password) true ;; *) false ;; esac"
# Certbot's HTTP-01 challenge resolves AAAA first and never falls back to IPv4.
# If this host has a global IPv6 address, the stack must be reachable over it or
# certificate issuance fails against a site that otherwise works fine.
check "global IPv6 address present"       "ip -6 addr show scope global | grep inet6"
check "IPv6 default route present"        "ip -6 route show default | grep default"

echo ""
if [ "$fail" -ne 0 ]; then
    die "verification failed -- fix the items above before deploying" 2
fi

cat <<EOF

$(log "Server ready. Remaining manual steps:")

  1. Replace ${DEPLOY_USER}'s authorized_keys with the CI key, prefixed by the
     forced command (one line, no wrapping):

       command="${DEPLOY_SCRIPT_DIR}/deploy.sh",no-pty,no-agent-forwarding,no-port-forwarding,no-X11-forwarding,restrict ssh-ed25519 AAAA... ci@github

  2. As ${DEPLOY_USER}, log in to GHCR once so deploys need no token:
       su - ${DEPLOY_USER} -c 'docker login ghcr.io -u <user> --password-stdin'

  3. git clone the repository into ${PROJECT_DIR} as ${DEPLOY_USER}, then
     create .env from .env.production.example and run:
       ./scripts/validate-env.sh production

  4. Verify nothing but 22/80/443 is reachable once the stack is up:
       ss -tlnp && docker ps --format '{{.Names}} {{.Ports}}'

EOF

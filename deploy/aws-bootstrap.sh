#!/usr/bin/env bash
# ===========================================================================
# Surgical Devices ERP — EC2 one-time bootstrap (Ubuntu 22.04 / 24.04)
#
# Installs Docker Engine + Compose plugin, adds a swap file (so the frontend
# `npm run build` doesn't OOM on small instances), and basic firewall rules.
#
# Run once on a fresh instance:
#   chmod +x deploy/aws-bootstrap.sh
#   sudo ./deploy/aws-bootstrap.sh
#
# Then log out/in (so your user picks up the docker group) and run deploy.sh.
# ===========================================================================
set -euo pipefail

if [ "$(id -u)" -ne 0 ]; then
  echo "Please run with sudo: sudo ./deploy/aws-bootstrap.sh" >&2
  exit 1
fi

TARGET_USER="${SUDO_USER:-ubuntu}"

echo "==> Updating apt and installing prerequisites"
export DEBIAN_FRONTEND=noninteractive
apt-get update -y
apt-get install -y ca-certificates curl gnupg git ufw

echo "==> Installing Docker Engine + Compose plugin"
if ! command -v docker >/dev/null 2>&1; then
  curl -fsSL https://get.docker.com | sh
fi
# get.docker.com includes the compose plugin; verify.
docker compose version >/dev/null 2>&1 || apt-get install -y docker-compose-plugin

echo "==> Adding user '$TARGET_USER' to the docker group"
usermod -aG docker "$TARGET_USER" || true
systemctl enable --now docker

echo "==> Creating a 2G swap file (idempotent)"
if [ ! -f /swapfile ]; then
  fallocate -l 2G /swapfile || dd if=/dev/zero of=/swapfile bs=1M count=2048
  chmod 600 /swapfile
  mkswap /swapfile
  swapon /swapfile
  grep -q '/swapfile' /etc/fstab || echo '/swapfile none swap sw 0 0' >> /etc/fstab
fi

echo "==> Configuring firewall (allow SSH, HTTP, HTTPS)"
ufw allow OpenSSH || true
ufw allow 80/tcp || true
ufw allow 443/tcp || true
ufw --force enable || true

echo ""
echo "✅ Bootstrap complete."
echo "   Log out and back in (so docker works without sudo), then run:"
echo "     ./deploy/deploy.sh --seed     # first deploy (creates roles + admin login)"

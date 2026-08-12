#!/usr/bin/env bash
# One-time production server setup (run ON the server with sudo).
# Ubuntu + nginx default install assumed.
#
# Usage (on server):
#   cd /opt/gaming-hub
#   sudo bash scripts/server-bootstrap.sh
#
set -euo pipefail

APP_DIR="${APP_DIR:-/opt/gaming-hub}"
DOMAIN="${DOMAIN:-shinichiy-gaming-hub.com}"
DEPLOY_USER="${DEPLOY_USER:-${SUDO_USER:-ubuntu}}"

if [[ ! -f "${APP_DIR}/scripts/server-bootstrap.sh" ]]; then
  echo "Error: ${APP_DIR}/scripts/server-bootstrap.sh not found."
  echo "Run deploy from your Mac first:"
  echo "  DEPLOY_USER=${DEPLOY_USER} ./scripts/deploy.sh"
  exit 1
fi

echo "==> Installing Docker (if missing)..."
if ! command -v docker >/dev/null 2>&1; then
  apt-get update
  apt-get install -y ca-certificates curl gnupg
  install -m 0755 -d /etc/apt/keyrings
  curl -fsSL https://download.docker.com/linux/ubuntu/gpg | gpg --dearmor -o /etc/apt/keyrings/docker.gpg
  chmod a+r /etc/apt/keyrings/docker.gpg
  echo \
    "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/ubuntu \
    $(. /etc/os-release && echo "$VERSION_CODENAME") stable" \
    > /etc/apt/sources.list.d/docker.list
  apt-get update
  apt-get install -y docker-ce docker-ce-cli containerd.io docker-compose-plugin
fi

usermod -aG docker "$DEPLOY_USER" 2>/dev/null || true

echo "==> App directory ${APP_DIR} ..."
mkdir -p "$APP_DIR/tesla"
chown -R "${DEPLOY_USER}:${DEPLOY_USER}" "$APP_DIR"

if [[ ! -f "${APP_DIR}/.env" ]]; then
  if [[ -f "${APP_DIR}/.env.production.example" ]]; then
    cp "${APP_DIR}/.env.production.example" "${APP_DIR}/.env"
    echo "Created ${APP_DIR}/.env — edit passwords before going live."
  else
    echo "Warning: ${APP_DIR}/.env not found. Create it from .env.production.example"
  fi
fi

echo "==> nginx site for ${DOMAIN} ..."
NGINX_AVAILABLE="/etc/nginx/sites-available/${DOMAIN}.conf"
NGINX_ENABLED="/etc/nginx/sites-enabled/${DOMAIN}.conf"
cp "${APP_DIR}/config/nginx/${DOMAIN}.conf" "$NGINX_AVAILABLE"
ln -sf "$NGINX_AVAILABLE" "$NGINX_ENABLED"
rm -f /etc/nginx/sites-enabled/default

mkdir -p /var/www/certbot

if [[ ! -f "/etc/letsencrypt/live/${DOMAIN}/fullchain.pem" ]]; then
  echo "==> Obtaining Let's Encrypt certificate..."
  apt-get install -y certbot python3-certbot-nginx
  certbot --nginx -d "$DOMAIN" -d "www.${DOMAIN}" --non-interactive --agree-tos -m "${CERTBOT_EMAIL:-admin@${DOMAIN}}" || {
    echo "certbot failed — set a valid email or run certbot manually."
    exit 1
  }
fi

nginx -t
systemctl reload nginx

echo "==> Starting WordPress stack..."
cd "$APP_DIR"
docker compose -f docker-compose.prod.yml up -d

echo ""
echo "Bootstrap complete."
echo "  Site:    https://${DOMAIN}/"
echo "  Tesla:   place public-key.pem at ${APP_DIR}/tesla/public-key.pem"
echo "  WP:      complete install at https://${DOMAIN}/ if first time"

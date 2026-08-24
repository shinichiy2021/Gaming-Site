#!/usr/bin/env bash
# Deploy Gaming Hub to production via rsync + docker compose.
#
# Usage:
#   DEPLOY_USER=ubuntu ./scripts/deploy.sh
#   DEPLOY_HOST=shinichiy-gaming-hub.com DEPLOY_USER=ubuntu ./scripts/deploy.sh
#
# GitHub Actions (secrets DEPLOY_HOST, DEPLOY_USER, DEPLOY_SSH_KEY):
#   SKIP_BUILD=1 DEPLOY_SSH_KEY="$KEY" DEPLOY_USER=ubuntu ./scripts/deploy.sh
#
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DEPLOY_HOST="${DEPLOY_HOST:-shinichiy-gaming-hub.com}"
DEPLOY_USER="${DEPLOY_USER:-}"
DEPLOY_PATH="${DEPLOY_PATH:-/opt/gaming-hub}"
SKIP_BUILD="${SKIP_BUILD:-0}"

if [[ -z "$DEPLOY_USER" ]]; then
  echo "Set DEPLOY_USER (SSH login user), e.g.: DEPLOY_USER=ubuntu $0"
  exit 1
fi

REMOTE="${DEPLOY_USER}@${DEPLOY_HOST}"

SSH_EXTRA=()
if [[ -n "${DEPLOY_SSH_KEY:-}" ]]; then
  KEY_FILE="$(mktemp)"
  trap 'rm -f "$KEY_FILE"' EXIT
  printf '%s\n' "$DEPLOY_SSH_KEY" > "$KEY_FILE"
  chmod 600 "$KEY_FILE"
  SSH_EXTRA=(-i "$KEY_FILE")
elif [[ -n "${DEPLOY_SSH_KEY_FILE:-}" ]]; then
  SSH_EXTRA=(-i "$DEPLOY_SSH_KEY_FILE")
fi

SSH=(ssh "${SSH_EXTRA[@]}" -o StrictHostKeyChecking=accept-new "$REMOTE")
RSYNC=(rsync -avz --delete -e "ssh ${SSH_EXTRA[*]} -o StrictHostKeyChecking=accept-new")

echo "==> Preparing ${DEPLOY_PATH} on server..."
"${SSH[@]}" "sudo mkdir -p ${DEPLOY_PATH} && sudo chown -R \$(whoami):\$(whoami) ${DEPLOY_PATH}"

if [[ "$SKIP_BUILD" != "1" ]]; then
  echo "==> Building theme assets..."
  cd "$ROOT/wp-content/themes/gaming-hub"
  if [[ ! -d node_modules ]]; then
    npm ci
  fi
  npm run build:flows
else
  echo "==> Skipping local build (SKIP_BUILD=1)"
fi

echo "==> Syncing to ${REMOTE}:${DEPLOY_PATH} ..."
"${RSYNC[@]}" \
  --exclude '.env' \
  --exclude '.git/' \
  --exclude '.cursor/' \
  --exclude '.vscode/' \
  --exclude 'node_modules/' \
  --exclude 'wp-content/themes/gaming-hub/node_modules/' \
  --exclude 'wp-content/ecoflow-cache/bridge-config.json' \
  --exclude 'wp-content/ecoflow-cache/*.json' \
  --exclude 'private-key.pem' \
  --exclude 'tesla/fleet-key.pem' \
  --exclude 'tesla/tls-key.pem' \
  --exclude 'tesla/tls-cert.pem' \
  --exclude 'tesla/session-cache.json' \
  --exclude '.DS_Store' \
  "$ROOT/" "${REMOTE}:${DEPLOY_PATH}/"

"${SSH[@]}" "mkdir -p ${DEPLOY_PATH}/tesla"

if [[ -f "$ROOT/public-key.pem" ]]; then
  echo "==> Installing Tesla public key..."
  "${RSYNC[@]}" "$ROOT/public-key.pem" "${REMOTE}:${DEPLOY_PATH}/tesla/public-key.pem"
else
  echo "Warning: ${ROOT}/public-key.pem not found — Tesla Fleet API partner registration will fail."
fi

FLEET_KEY=""
if [[ -f "$ROOT/tesla/fleet-key.pem" ]]; then
  FLEET_KEY="$ROOT/tesla/fleet-key.pem"
elif [[ -f "$ROOT/private-key.pem" && -f "$ROOT/public-key.pem" ]] \
  && openssl pkey -in "$ROOT/private-key.pem" -pubout 2>/dev/null \
    | cmp -s - <(openssl pkey -in "$ROOT/public-key.pem" -pubin 2>/dev/null); then
  echo "==> Using Tesla EC private-key.pem as command-signing fleet-key"
  FLEET_KEY="$ROOT/private-key.pem"
fi

if [[ -n "$FLEET_KEY" ]]; then
  echo "==> Installing Tesla command-signing private key..."
  "${RSYNC[@]}" "$FLEET_KEY" "${REMOTE}:${DEPLOY_PATH}/tesla/fleet-key.pem"
fi

echo "==> Making scripts executable on server..."
"${SSH[@]}" "chmod +x ${DEPLOY_PATH}/scripts/*.sh 2>/dev/null || true"

echo "==> Preparing tesla-http-proxy TLS..."
"${SSH[@]}" "bash ${DEPLOY_PATH}/scripts/tesla-proxy-prepare.sh ${DEPLOY_PATH}/tesla"

echo "==> Updating nginx site config..."
"${SSH[@]}" "sudo cp ${DEPLOY_PATH}/config/nginx/${DEPLOY_HOST}.conf /etc/nginx/sites-available/${DEPLOY_HOST}.conf && sudo ln -sf /etc/nginx/sites-available/${DEPLOY_HOST}.conf /etc/nginx/sites-enabled/${DEPLOY_HOST}.conf && sudo nginx -t && sudo systemctl reload nginx"

if ! "${SSH[@]}" "test -f ${DEPLOY_PATH}/.env"; then
  echo "==> Creating ${DEPLOY_PATH}/.env from template..."
  "${SSH[@]}" "cp ${DEPLOY_PATH}/.env.production.example ${DEPLOY_PATH}/.env"
  echo "Warning: edit ${DEPLOY_PATH}/.env on the server before relying on production secrets."
fi

echo "==> Starting Docker on server..."
"${SSH[@]}" "cd ${DEPLOY_PATH} && docker compose -f docker-compose.prod.yml up -d"

echo ""
echo "Deploy complete: https://${DEPLOY_HOST}/"
echo ""
echo "First time on server, run:"
echo "  ssh ${REMOTE}"
echo "  sudo CERTBOT_EMAIL=you@example.com bash ${DEPLOY_PATH}/scripts/server-bootstrap.sh"

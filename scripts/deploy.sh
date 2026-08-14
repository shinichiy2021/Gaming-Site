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
  --exclude '*.pem' \
  --exclude '.DS_Store' \
  "$ROOT/" "${REMOTE}:${DEPLOY_PATH}/"

echo "==> Making scripts executable on server..."
"${SSH[@]}" "chmod +x ${DEPLOY_PATH}/scripts/*.sh 2>/dev/null || true"

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

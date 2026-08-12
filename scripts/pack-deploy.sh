#!/usr/bin/env bash
# Create deploy.tar.gz for manual upload when rsync/deploy.sh fails.
#
# Usage:
#   ./scripts/pack-deploy.sh
#   scp deploy.tar.gz ubuntu@shinichiy-gaming-hub.com:/tmp/
#   ssh ubuntu@shinichiy-gaming-hub.com
#   sudo mkdir -p /opt/gaming-hub && sudo tar -xzf /tmp/deploy.tar.gz -C /opt/gaming-hub
#   sudo chown -R ubuntu:ubuntu /opt/gaming-hub
#   sudo CERTBOT_EMAIL=you@example.com bash /opt/gaming-hub/scripts/server-bootstrap.sh
#
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
OUT="${ROOT}/deploy.tar.gz"

echo "==> Building theme assets..."
cd "$ROOT/wp-content/themes/gaming-hub"
if [[ ! -d node_modules ]]; then
  npm ci
fi
npm run build:flows

echo "==> Creating ${OUT} ..."
cd "$ROOT"
tar -czf "$OUT" \
  --exclude='./.env' \
  --exclude='./.git' \
  --exclude='./.cursor' \
  --exclude='./.vscode' \
  --exclude='./node_modules' \
  --exclude='./wp-content/themes/gaming-hub/node_modules' \
  --exclude='./wp-content/ecoflow-cache/bridge-config.json' \
  --exclude='./deploy.tar.gz' \
  .

echo "Done: ${OUT}"
echo ""
echo "Upload to server:"
echo "  scp deploy.tar.gz ubuntu@shinichiy-gaming-hub.com:/tmp/"
echo ""
echo "On server:"
echo "  sudo mkdir -p /opt/gaming-hub"
echo "  sudo tar -xzf /tmp/deploy.tar.gz -C /opt/gaming-hub"
echo "  sudo chown -R \$(whoami):\$(whoami) /opt/gaming-hub"
echo "  ls /opt/gaming-hub/scripts/server-bootstrap.sh"
echo "  sudo CERTBOT_EMAIL=shinichiy2011@gmail.com bash /opt/gaming-hub/scripts/server-bootstrap.sh"

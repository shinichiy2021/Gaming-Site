#!/usr/bin/env bash
# Build the portfolio app (crypto-app) locally and ship it to production.
#
# The EC2 host has <1 GB RAM, so `next build` runs here (linux/amd64) and the
# image is streamed over SSH. Also uploads the git-ignored SBI holdings.
#
# Usage:
#   DEPLOY_USER=ubuntu ./scripts/deploy-crypto.sh
#
# Requires CRYPTO_BASIC_AUTH_USER / CRYPTO_BASIC_AUTH_PASSWORD in the server .env.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DEPLOY_HOST="${DEPLOY_HOST:-shinichiy-gaming-hub.com}"
DEPLOY_USER="${DEPLOY_USER:-ubuntu}"
DEPLOY_PATH="${DEPLOY_PATH:-/opt/gaming-hub}"
IMAGE="gaming-hub-crypto:latest"
REMOTE="${DEPLOY_USER}@${DEPLOY_HOST}"
SSH=(ssh -o StrictHostKeyChecking=accept-new "$REMOTE")

echo "==> Checking auth settings in ${DEPLOY_PATH}/.env ..."
if ! "${SSH[@]}" "grep -Eq '^CRYPTO_BASIC_AUTH_USER=.+' ${DEPLOY_PATH}/.env && grep -Eq '^CRYPTO_BASIC_AUTH_PASSWORD=.+' ${DEPLOY_PATH}/.env"; then
  echo "Set CRYPTO_BASIC_AUTH_USER and CRYPTO_BASIC_AUTH_PASSWORD in ${DEPLOY_PATH}/.env first."
  exit 1
fi

echo "==> Building ${IMAGE} (linux/amd64, basePath /portfolio) ..."
docker buildx build \
  --platform linux/amd64 \
  --build-arg CRYPTO_BASE_PATH=/portfolio \
  -t "$IMAGE" \
  --load \
  "$ROOT/crypto-app"

echo "==> Uploading image ..."
docker save "$IMAGE" | gzip | "${SSH[@]}" "gunzip | docker load"

if [[ -d "$ROOT/crypto-app/public/data" ]]; then
  echo "==> Uploading SBI holdings ..."
  "${SSH[@]}" "mkdir -p ${DEPLOY_PATH}/crypto-app/public/data && chmod 700 ${DEPLOY_PATH}/crypto-app/public/data"
  rsync -az -e "ssh -o StrictHostKeyChecking=accept-new" \
    "$ROOT/crypto-app/public/data/" "${REMOTE}:${DEPLOY_PATH}/crypto-app/public/data/"
fi

echo "==> Starting crypto container ..."
"${SSH[@]}" "cd ${DEPLOY_PATH} && docker compose -f docker-compose.prod.yml --profile portfolio up -d crypto && docker image prune -f >/dev/null"

echo ""
echo "Portfolio: https://${DEPLOY_HOST}/portfolio (Basic auth)"

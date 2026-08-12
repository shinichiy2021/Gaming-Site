#!/usr/bin/env bash
# Verify deploy files exist on the production server.
#
# Usage:
#   DEPLOY_USER=ubuntu ./scripts/verify-remote.sh
#
set -euo pipefail

DEPLOY_HOST="${DEPLOY_HOST:-shinichiy-gaming-hub.com}"
DEPLOY_USER="${DEPLOY_USER:-ubuntu}"
DEPLOY_PATH="${DEPLOY_PATH:-/opt/gaming-hub}"

REMOTE="${DEPLOY_USER}@${DEPLOY_HOST}"

echo "Checking ${REMOTE}:${DEPLOY_PATH} ..."
ssh "$REMOTE" "ls -la ${DEPLOY_PATH}/scripts/server-bootstrap.sh ${DEPLOY_PATH}/docker-compose.prod.yml 2>&1 || echo 'NOT FOUND — run deploy.sh or pack-deploy.sh first'"

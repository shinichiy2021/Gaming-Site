#!/usr/bin/env bash
# Register GitHub Actions secrets/variables for AWS deploy.
#
# Prerequisites:
#   brew install gh
#   gh auth login
#
# Usage:
#   ./scripts/setup-github-actions.sh
#   DEPLOY_SSH_KEY_FILE=~/.ssh/id_ed25519 ./scripts/setup-github-actions.sh
#
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
REPO="${GITHUB_REPOSITORY:-}"

if [[ -z "$REPO" ]]; then
  if git -C "$ROOT" remote get-url origin >/dev/null 2>&1; then
    ORIGIN="$(git -C "$ROOT" remote get-url origin)"
    if [[ "$ORIGIN" =~ github\.com[:/]([^/]+)/([^/.]+)(\.git)?$ ]]; then
      REPO="${BASH_REMATCH[1]}/${BASH_REMATCH[2]}"
    fi
  fi
fi

if [[ -z "$REPO" ]]; then
  echo "Could not detect GitHub repo. Set GITHUB_REPOSITORY=owner/name"
  exit 1
fi

if ! command -v gh >/dev/null 2>&1; then
  echo "GitHub CLI (gh) is required. Install: brew install gh"
  exit 1
fi

if ! gh auth status >/dev/null 2>&1; then
  echo "Not logged in to GitHub. Run:"
  echo "  gh auth login"
  exit 1
fi

DEPLOY_HOST="${DEPLOY_HOST:-shinichiy-gaming-hub.com}"
DEPLOY_USER="${DEPLOY_USER:-ubuntu}"
DEPLOY_PATH="${DEPLOY_PATH:-/opt/gaming-hub}"
SSH_KEY_FILE="${DEPLOY_SSH_KEY_FILE:-${ROOT}/private-key.pem}"

echo "==> Repository: ${REPO}"
echo "==> Deploy target: ${DEPLOY_USER}@${DEPLOY_HOST}:${DEPLOY_PATH}"

echo "==> Ensuring production environment..."
gh api "repos/${REPO}/environments/production" -X PUT --silent >/dev/null 2>&1 || true

echo "==> Setting secrets..."
gh secret set DEPLOY_HOST -R "$REPO" -b "$DEPLOY_HOST"
gh secret set DEPLOY_USER -R "$REPO" -b "$DEPLOY_USER"

if [[ -n "${DEPLOY_SSH_KEY:-}" ]]; then
  printf '%s\n' "$DEPLOY_SSH_KEY" | gh secret set DEPLOY_SSH_KEY -R "$REPO"
elif [[ -f "$SSH_KEY_FILE" ]]; then
  gh secret set DEPLOY_SSH_KEY -R "$REPO" < "$SSH_KEY_FILE"
else
  echo "Error: SSH private key not found."
  echo "  Place key at ${ROOT}/private-key.pem or set DEPLOY_SSH_KEY_FILE=/path/to/key"
  exit 1
fi

echo "==> Setting variables..."
gh variable set DEPLOY_PATH -R "$REPO" -b "$DEPLOY_PATH"

echo ""
echo "GitHub Actions setup complete for ${REPO}."
echo ""
echo "Configured:"
echo "  Secret  DEPLOY_HOST"
echo "  Secret  DEPLOY_USER = ${DEPLOY_USER}"
echo "  Secret  DEPLOY_SSH_KEY (from key file)"
echo "  Variable DEPLOY_PATH = ${DEPLOY_PATH}"
echo "  Environment production"
echo ""
echo "Next:"
echo "  1. Commit and push .github/workflows/deploy-aws.yml to master"
echo "  2. Actions -> Deploy to AWS -> Run workflow (or push to master)"
echo ""
gh secret list -R "$REPO"
gh variable list -R "$REPO"

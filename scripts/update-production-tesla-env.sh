#!/usr/bin/env bash
# Update Tesla Fleet API keys in production .env and restart WordPress.
#
# GitHub Actions secrets (production environment):
#   TESLA_CLIENT_ID, TESLA_CLIENT_SECRET
#   TESLA_VEHICLE_VIN, TESLA_REFRESH_TOKEN
#   Optional: TESLA_FLEET_API_BASE_URL, TESLA_REDIRECT_URI, TESLA_PARTNER_DOMAIN
#
set -euo pipefail

DEPLOY_HOST="${DEPLOY_HOST:-shinichiy-gaming-hub.com}"
DEPLOY_USER="${DEPLOY_USER:?Set DEPLOY_USER}"
DEPLOY_PATH="${DEPLOY_PATH:-/opt/gaming-hub}"

: "${TESLA_CLIENT_ID:?Set TESLA_CLIENT_ID}"
: "${TESLA_CLIENT_SECRET:?Set TESLA_CLIENT_SECRET}"
: "${TESLA_VEHICLE_VIN:?Set TESLA_VEHICLE_VIN}"
: "${TESLA_REFRESH_TOKEN:?Set TESLA_REFRESH_TOKEN}"

TESLA_FLEET_API_BASE_URL="${TESLA_FLEET_API_BASE_URL:-https://fleet-api.prd.na.vn.cloud.tesla.com}"
TESLA_REDIRECT_URI="${TESLA_REDIRECT_URI:-https://shinichiy-gaming-hub.com/wp-json/gaming-hub/v1/tesla/oauth/callback}"
TESLA_PARTNER_DOMAIN="${TESLA_PARTNER_DOMAIN:-shinichiy-gaming-hub.com}"

REMOTE="${DEPLOY_USER}@${DEPLOY_HOST}"

SSH_EXTRA=()
if [[ -n "${DEPLOY_SSH_KEY:-}" ]]; then
	KEY_FILE="$(mktemp)"
	trap 'rm -f "$KEY_FILE"' EXIT
	printf '%s\n' "$DEPLOY_SSH_KEY" > "$KEY_FILE"
	chmod 600 "$KEY_FILE"
	SSH_EXTRA=(-i "$KEY_FILE")
fi

SSH=(ssh "${SSH_EXTRA[@]}" -o StrictHostKeyChecking=accept-new "$REMOTE")

update_env() {
	local key="$1"
	local value="$2"
	local encoded
	encoded="$(printf '%s' "$value" | base64 | tr -d '\n')"
	"${SSH[@]}" "python3 -c \"
import pathlib, re, base64
path = pathlib.Path('${DEPLOY_PATH}/.env')
key = '${key}'
value = base64.b64decode('${encoded}').decode('utf-8')
text = path.read_text(encoding='utf-8') if path.exists() else ''
pattern = re.compile(r'^' + re.escape(key) + r'=.*$', re.M)
line = key + '=' + value
if pattern.search(text):
    text = pattern.sub(line, text, count=1)
else:
    text = text.rstrip('\\n') + ('\\n' if text else '') + line + '\\n'
path.write_text(text, encoding='utf-8')
print('updated', key)
\""
}

echo "==> Updating Tesla env on ${REMOTE}:${DEPLOY_PATH}/.env ..."
update_env TESLA_CLIENT_ID "$TESLA_CLIENT_ID"
update_env TESLA_CLIENT_SECRET "$TESLA_CLIENT_SECRET"
update_env TESLA_VEHICLE_VIN "$TESLA_VEHICLE_VIN"
update_env TESLA_REFRESH_TOKEN "$TESLA_REFRESH_TOKEN"
update_env TESLA_FLEET_API_BASE_URL "$TESLA_FLEET_API_BASE_URL"
update_env TESLA_REDIRECT_URI "$TESLA_REDIRECT_URI"
update_env TESLA_PARTNER_DOMAIN "$TESLA_PARTNER_DOMAIN"

echo "==> Recreating WordPress ..."
"${SSH[@]}" "cd ${DEPLOY_PATH} && docker compose -f docker-compose.prod.yml up -d --force-recreate wordpress"

echo "==> Done."

#!/usr/bin/env bash
# Update EcoFlow keys in production .env and restart WordPress + bridge.
#
# GitHub Actions secrets:
#   ECOFLOW_ACCESS_KEY, ECOFLOW_SECRET_KEY
#   Optional: ECOFLOW_API_REGION, ECOFLOW_DEVICE_SN, ECOFLOW_DEVICE_SN_2
#
set -euo pipefail

DEPLOY_HOST="${DEPLOY_HOST:-shinichiy-gaming-hub.com}"
DEPLOY_USER="${DEPLOY_USER:?Set DEPLOY_USER}"
DEPLOY_PATH="${DEPLOY_PATH:-/opt/gaming-hub}"

: "${ECOFLOW_ACCESS_KEY:?Set ECOFLOW_ACCESS_KEY}"
: "${ECOFLOW_SECRET_KEY:?Set ECOFLOW_SECRET_KEY}"

ECOFLOW_API_REGION="${ECOFLOW_API_REGION:-a}"
ECOFLOW_DEVICE_SN="${ECOFLOW_DEVICE_SN:-}"
ECOFLOW_DEVICE_SN_2="${ECOFLOW_DEVICE_SN_2:-}"

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

echo "==> Updating EcoFlow env on ${REMOTE}:${DEPLOY_PATH}/.env ..."
update_env ECOFLOW_ACCESS_KEY "$ECOFLOW_ACCESS_KEY"
update_env ECOFLOW_SECRET_KEY "$ECOFLOW_SECRET_KEY"
update_env ECOFLOW_API_REGION "$ECOFLOW_API_REGION"

if [[ -n "$ECOFLOW_DEVICE_SN" ]]; then
	update_env ECOFLOW_DEVICE_SN "$ECOFLOW_DEVICE_SN"
fi

if [[ -n "$ECOFLOW_DEVICE_SN_2" ]]; then
	update_env ECOFLOW_DEVICE_SN_2 "$ECOFLOW_DEVICE_SN_2"
fi

echo "==> Recreating WordPress and ecoflow-bridge ..."
"${SSH[@]}" "cd ${DEPLOY_PATH} && docker compose -f docker-compose.prod.yml up -d --force-recreate wordpress ecoflow-bridge"

echo "==> Done."

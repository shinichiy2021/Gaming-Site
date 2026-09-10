#!/usr/bin/env bash
# Prepare Fleet Telemetry Phase 1 files under tesla/telemetry/.
#
# Usage:
#   bash scripts/tesla-telemetry-prepare.sh
#   TESLA_TELEMETRY_HOST=telemetry.example.com bash scripts/tesla-telemetry-prepare.sh
#
# Creates:
#   tesla/telemetry/config.json
#   tesla/telemetry/certs/server.crt + server.key (+ ca.pem for vehicle config)
#   tesla/telemetry/vehicle-config.json  (CA filled in; send with configure.php)
#
# Prefer Let's Encrypt when available:
#   certbot certonly --nginx -d telemetry.shinichiy-gaming-hub.com
#   TESLA_TELEMETRY_CERT_DIR=/etc/letsencrypt/live/telemetry.shinichiy-gaming-hub.com \
#     bash scripts/tesla-telemetry-prepare.sh
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DIR="${1:-${ROOT}/tesla}"
TELEM="${DIR}/telemetry"
CERTS="${TELEM}/certs"
HOST="${TESLA_TELEMETRY_HOST:-telemetry.shinichiy-gaming-hub.com}"
PORT="${TESLA_TELEMETRY_HOST_PORT:-8443}"
CERT_DIR="${TESLA_TELEMETRY_CERT_DIR:-}"

mkdir -p "${CERTS}" "${DIR}/telemetry-data"

if [[ ! -f "${TELEM}/config.json" ]]; then
	echo "==> Writing ${TELEM}/config.json from example"
	cp "${TELEM}/config.example.json" "${TELEM}/config.json"
fi

have_server_cert=0
if [[ "${TESLA_TELEMETRY_FORCE_CERTS:-}" != "1" && -f "${CERTS}/server.crt" && -f "${CERTS}/server.key" ]]; then
	have_server_cert=1
	echo "==> Reusing existing ${CERTS}/server.crt (set TESLA_TELEMETRY_FORCE_CERTS=1 to replace)"
fi

if [[ "${have_server_cert}" -eq 0 && -n "${CERT_DIR}" ]]; then
	if [[ -r "${CERT_DIR}/fullchain.pem" && -r "${CERT_DIR}/privkey.pem" ]]; then
		echo "==> Copying Let's Encrypt certs from ${CERT_DIR}"
		cp -f "${CERT_DIR}/fullchain.pem" "${CERTS}/server.crt"
		cp -f "${CERT_DIR}/privkey.pem" "${CERTS}/server.key"
		have_server_cert=1
	elif sudo test -f "${CERT_DIR}/fullchain.pem" && sudo test -f "${CERT_DIR}/privkey.pem"; then
		echo "==> Copying Let's Encrypt certs via sudo from ${CERT_DIR}"
		sudo cp -f "${CERT_DIR}/fullchain.pem" "${CERTS}/server.crt"
		sudo cp -f "${CERT_DIR}/privkey.pem" "${CERTS}/server.key"
		sudo chown "$(id -u):$(id -g)" "${CERTS}/server.crt" "${CERTS}/server.key"
		have_server_cert=1
	else
		echo "Warning: TESLA_TELEMETRY_CERT_DIR set but fullchain/privkey missing: ${CERT_DIR}"
	fi
fi

if [[ "${have_server_cert}" -eq 0 ]]; then
	echo "==> Generating self-signed TLS cert for ${HOST} (Phase 1 / lab)"
	openssl req -x509 -nodes -newkey ec \
		-pkeyopt ec_paramgen_curve:secp384r1 \
		-subj "/CN=${HOST}" \
		-keyout "${CERTS}/server.key" \
		-out "${CERTS}/server.crt" \
		-sha256 -days 825 \
		-addext "subjectAltName = DNS:${HOST}" \
		-addext "extendedKeyUsage = serverAuth" \
		-addext "keyUsage = digitalSignature, keyCertSign, keyAgreement"
fi

# fleet-telemetry image may run as non-root; key must be world-readable in the mount.
chmod 644 "${CERTS}/server.crt" "${CERTS}/server.key" 2>/dev/null || true
chmod 644 "${CERTS}/ca.pem" 2>/dev/null || true


# Vehicle config "ca" must verify server.crt.
# Self-signed: the leaf is the CA. Let's Encrypt: prefer ISRG Root X1 if present.
if [[ ! -f "${CERTS}/ca.pem" ]]; then
	if openssl x509 -in "${CERTS}/server.crt" -noout -issuer 2>/dev/null | grep -qi "Let's Encrypt\|ISRG"; then
		if [[ -f /etc/ssl/certs/ISRG_Root_X1.pem ]]; then
			cp /etc/ssl/certs/ISRG_Root_X1.pem "${CERTS}/ca.pem"
			echo "==> Wrote ca.pem from ISRG_Root_X1"
		else
			cp "${CERTS}/server.crt" "${CERTS}/ca.pem"
			echo "==> Wrote ca.pem from server.crt (prefer ISRG_Root_X1 for Let's Encrypt)"
		fi
	else
		cp "${CERTS}/server.crt" "${CERTS}/ca.pem"
		echo "==> Wrote ca.pem (= self-signed server.crt)"
	fi
	chmod 644 "${CERTS}/ca.pem"
fi

CA_ESCAPED=$(awk 'BEGIN{printf "\""} {gsub(/\\/,"\\\\"); gsub(/"/,"\\\""); gsub(/\r/,""); printf "%s\\n", $0} END{printf "\""}' "${CERTS}/ca.pem")

EXAMPLE="${TELEM}/vehicle-config.example.json"
OUT="${TELEM}/vehicle-config.json"
if [[ -f "${EXAMPLE}" ]]; then
	echo "==> Writing ${OUT}"
	sed \
		-e "s#\"hostname\": \"[^\"]*\"#\"hostname\": \"${HOST}\"#" \
		-e "s#\"port\": [0-9]*#\"port\": ${PORT}#" \
		-e "s#\"ca\": \"[^\"]*\"#\"ca\": ${CA_ESCAPED}#" \
		"${EXAMPLE}" > "${OUT}"
fi

echo ""
echo "Ready."
echo "  Host:   ${HOST}:${PORT}"
echo "  Config: ${TELEM}/config.json"
echo "  Certs:  ${CERTS}/server.crt (+ ca.pem)"
echo "  Vehicle payload: ${OUT}"
echo ""
echo "Start (production):"
echo "  docker compose -f docker-compose.prod.yml --profile telemetry up -d tesla-fleet-telemetry"
echo ""
echo "Send config to the car (after proxy + OAuth work):"
echo "  docker compose -f docker-compose.prod.yml exec -T wordpress \\"
echo "    php /var/www/html/scripts/tesla-telemetry-configure.php"
echo ""
echo "Watch ingest:"
echo "  docker logs -f gaming-site-tesla-telemetry"
echo "  curl -sS http://127.0.0.1:${TESLA_TELEMETRY_STATUS_PORT:-8444}/status || true"

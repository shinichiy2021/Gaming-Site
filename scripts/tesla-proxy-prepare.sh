#!/usr/bin/env bash
# Generate tesla-http-proxy TLS certs and lock down the command-signing key.
# Usage: scripts/tesla-proxy-prepare.sh [/path/to/tesla]
set -euo pipefail

DIR="${1:-}"
if [[ -z "$DIR" ]]; then
	ROOT="$(cd "$(dirname "$0")/.." && pwd)"
	DIR="${ROOT}/tesla"
fi

mkdir -p "$DIR"

if [[ ! -f "$DIR/tls-cert.pem" || ! -f "$DIR/tls-key.pem" ]]; then
	echo "==> Generating tesla-http-proxy TLS cert in ${DIR}"
	openssl req -x509 -nodes -newkey ec \
		-pkeyopt ec_paramgen_curve:secp384r1 \
		-subj '/CN=tesla-http-proxy' \
		-keyout "$DIR/tls-key.pem" \
		-out "$DIR/tls-cert.pem" \
		-sha256 -days 3650 \
		-addext 'subjectAltName = DNS:tesla-http-proxy,DNS:localhost,IP:127.0.0.1' \
		-addext 'extendedKeyUsage = serverAuth' \
		-addext 'keyUsage = digitalSignature, keyCertSign, keyAgreement'
	chmod 600 "$DIR/tls-key.pem"
	chmod 644 "$DIR/tls-cert.pem"
	chown 1000:1000 "$DIR/tls-key.pem" "$DIR/tls-cert.pem" 2>/dev/null || true
fi

if [[ -f "$DIR/fleet-key.pem" ]]; then
	# tesla-http-proxy runs as uid 1000 on production.
	chmod 600 "$DIR/fleet-key.pem"
	chown 1000:1000 "$DIR/fleet-key.pem" 2>/dev/null || true
else
	echo "Warning: ${DIR}/fleet-key.pem is missing. tesla-http-proxy cannot sign charge commands until it is installed."
fi

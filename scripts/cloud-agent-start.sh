#!/usr/bin/env bash
# Cursor Cloud Agent "start" phase for Gaming Hub.
# Runs on every boot: starts Docker, brings up the compose stack, and performs
# a one-time-idempotent WordPress bootstrap (install + theme activation).
set -euo pipefail

cd "$(dirname "$0")/.."
# shellcheck source=scripts/cloud-agent-lib.sh
source scripts/cloud-agent-lib.sh

# Pin the compose project so network/volume names are stable regardless of the
# checkout directory name.
export COMPOSE_PROJECT_NAME="${COMPOSE_PROJECT_NAME:-gaminghub}"

if [ ! -f docker-compose.yml ]; then
  log "docker-compose.yml not found on this branch; nothing to start."
  exit 0
fi

ensure_dockerd

log "Starting compose stack (WordPress, MySQL, phpMyAdmin, EcoFlow bridge)..."
# -p keeps the project name stable through sudo (which strips env vars).
sudo docker compose -p "$COMPOSE_PROJECT_NAME" up -d

# The compose network is (re)created above; re-assert the network fixes so
# container-to-container and outbound traffic are not dropped.
apply_network_fixes

wait_for_wordpress "http://localhost:8080/" 40 || true

# Idempotent WordPress bootstrap via WP-CLI (shares the wordpress data volume).
WP_URL="http://localhost:8080"
WP_TITLE="Gaming Hub"
WP_ADMIN_USER="${WP_ADMIN_USER:-admin}"
WP_ADMIN_PASS="${WP_ADMIN_PASS:-admin123}"
WP_ADMIN_EMAIL="${WP_ADMIN_EMAIL:-admin@example.com}"

wp() {
  sudo docker run -i --rm \
    --network "${COMPOSE_PROJECT_NAME}_gaming-network" \
    --volumes-from gaming-site-wp \
    -e WORDPRESS_DB_HOST=db \
    -e WORDPRESS_DB_USER="${MYSQL_USER:-wordpress}" \
    -e WORDPRESS_DB_PASSWORD="${MYSQL_PASSWORD:-wordpress}" \
    -e WORDPRESS_DB_NAME="${MYSQL_DATABASE:-wordpress}" \
    --user 33:33 \
    wordpress:cli "$@"
}

if wp wp core is-installed >/dev/null 2>&1; then
  log "WordPress already installed."
else
  log "Installing WordPress core..."
  wp wp core install \
    --url="$WP_URL" --title="$WP_TITLE" \
    --admin_user="$WP_ADMIN_USER" --admin_password="$WP_ADMIN_PASS" \
    --admin_email="$WP_ADMIN_EMAIL" --skip-email >/dev/null
fi

if [ "$(wp wp theme list --status=active --field=name 2>/dev/null | tr -d '[:space:]')" != "gaming-hub" ]; then
  log "Activating Gaming Hub theme..."
  wp wp theme activate gaming-hub >/dev/null || true
fi

# Pretty permalinks so /pokemon-go/ and tag routes resolve.
wp wp rewrite structure '/%postname%/' >/dev/null 2>&1 || true
wp wp rewrite flush >/dev/null 2>&1 || true

log "Ready."
log "  WordPress:  http://localhost:8080  (admin: ${WP_ADMIN_USER} / ${WP_ADMIN_PASS})"
log "  phpMyAdmin: http://localhost:8081"
log "  EcoFlow:    http://localhost:8080/tag/ecoflow/"

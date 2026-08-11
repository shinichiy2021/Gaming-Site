#!/usr/bin/env bash
# Cursor Cloud Agent "install" phase for Gaming Hub.
# Idempotent: installs Docker Engine, builds the theme's React bundle, and
# pre-pulls the Docker images so agent boots are fast. Safe to run repeatedly.
set -euo pipefail

cd "$(dirname "$0")/.."
# shellcheck source=scripts/cloud-agent-lib.sh
source scripts/cloud-agent-lib.sh

# Pin the compose project so network/volume names are stable regardless of the
# checkout directory name.
export COMPOSE_PROJECT_NAME="${COMPOSE_PROJECT_NAME:-gaminghub}"

if [ ! -f docker-compose.yml ]; then
  log "docker-compose.yml not found on this branch; nothing to set up."
  exit 0
fi

# 1. Docker Engine + Compose plugin + fuse-overlayfs (nested-VM storage driver).
if ! command -v docker >/dev/null 2>&1; then
  log "Installing Docker Engine + Compose plugin..."
  sudo install -m 0755 -d /etc/apt/keyrings
  if [ ! -f /etc/apt/keyrings/docker.gpg ]; then
    curl -fsSL https://download.docker.com/linux/ubuntu/gpg \
      | sudo gpg --dearmor -o /etc/apt/keyrings/docker.gpg
    sudo chmod a+r /etc/apt/keyrings/docker.gpg
  fi
  # shellcheck disable=SC1091
  . /etc/os-release
  echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/ubuntu ${VERSION_CODENAME} stable" \
    | sudo tee /etc/apt/sources.list.d/docker.list >/dev/null
  sudo apt-get update -qq
  sudo DEBIAN_FRONTEND=noninteractive apt-get install -y -qq \
    docker-ce docker-ce-cli containerd.io docker-buildx-plugin \
    docker-compose-plugin fuse-overlayfs
  # fuse3 ships an interactive conffile prompt; resolve it non-interactively.
  sudo DEBIAN_FRONTEND=noninteractive dpkg --configure -a \
    --force-confdef --force-confold || true
else
  log "Docker already installed ($(docker --version))."
fi

# 2. Configure the daemon for the nested VM: classic fuse-overlayfs graph driver
#    (the default overlayfs snapshotter cannot mount overlay-on-overlay here).
sudo mkdir -p /etc/docker
echo '{ "storage-driver": "fuse-overlayfs", "features": { "containerd-snapshotter": false } }' \
  | sudo tee /etc/docker/daemon.json >/dev/null

# 3. Let the agent user reach the Docker socket without sudo.
sudo groupadd -f docker
sudo usermod -aG docker "$(id -un)" || true

# 4. Build the EcoFlow React bundle (served as a static asset by the theme).
if command -v npm >/dev/null 2>&1; then
  log "Building EcoFlow React bundle..."
  ( cd wp-content/themes/gaming-hub && npm ci && npm run build:ecoflow )
else
  log "WARNING: npm not found; skipping React bundle build."
fi

# 5. Pre-pull Docker images so the first boot's compose up is fast.
ensure_dockerd
log "Pre-pulling Docker images..."
# -p keeps the project name stable through sudo (which strips env vars).
sudo docker compose -p "$COMPOSE_PROJECT_NAME" pull --quiet \
  || log "WARNING: image pre-pull failed (will pull on start)."

log "Install phase complete."

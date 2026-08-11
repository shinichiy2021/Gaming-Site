#!/usr/bin/env bash
# Shared helpers for Cursor Cloud Agent environment setup.
# Sourced by cloud-agent-install.sh and cloud-agent-start.sh.

log() { echo "[cloud-agent] $*"; }

# Nested Cloud Agent VMs bridge L2 frames through the netfilter FORWARD chain,
# which drops container-to-container traffic on Docker's user-defined networks.
# Letting bridged frames bypass iptables restores compose service-to-service
# connectivity (e.g. WordPress -> MySQL). These sysctls reset on every boot.
apply_bridge_fix() {
  sudo sysctl -w \
    net.bridge.bridge-nf-call-iptables=0 \
    net.bridge.bridge-nf-call-ip6tables=0 \
    net.bridge.bridge-nf-call-arptables=0 >/dev/null 2>&1 || true
}

# Start the Docker daemon if it is not already running. The Cloud Agent VM does
# not run systemd as PID 1, so dockerd is launched directly.
ensure_dockerd() {
  if sudo docker info >/dev/null 2>&1; then
    log "Docker daemon already running."
    apply_bridge_fix
    return 0
  fi
  log "Starting Docker daemon..."
  sudo nohup dockerd >/tmp/dockerd.log 2>&1 &
  for _ in $(seq 1 30); do
    if sudo docker info >/dev/null 2>&1; then
      log "Docker daemon is up."
      apply_bridge_fix
      return 0
    fi
    sleep 1
  done
  log "ERROR: Docker daemon failed to start. Last log lines:"
  tail -n 25 /tmp/dockerd.log || true
  return 1
}

# Wait until WordPress answers over HTTP (200/301/302).
wait_for_wordpress() {
  local url="${1:-http://localhost:8080/}"
  local tries="${2:-40}"
  for i in $(seq 1 "$tries"); do
    local code
    code="$(curl -s -o /dev/null -w '%{http_code}' "$url" 2>/dev/null || echo 000)"
    if echo "$code" | grep -qE '^(200|301|302)$'; then
      log "WordPress is responding (HTTP $code)."
      return 0
    fi
    sleep 3
  done
  log "WARNING: WordPress did not become ready in time (last HTTP $code)."
  return 1
}

#!/usr/bin/env bash
# Shared helpers for the NCK-109 proof fixtures. Sourced, never executed.
set -euo pipefail

fail() { echo "FAIL: $*" >&2; exit 1; }

container_id() { docker inspect --format '{{.Id}}' "$1"; }

log_config() {
  docker inspect \
    --format '{{.HostConfig.LogConfig.Type}} {{index .HostConfig.LogConfig.Config "max-size"}} {{index .HostConfig.LogConfig.Config "max-file"}}' \
    "$1"
}

metrics_address() {
  local addresses
  addresses=$(hostname -I | tr -s ' ' '\n')
  awk '/^10\.44\./ { print; exit }' <<<"$addresses"
}

grafana_password() {
  orbit metrics:credentials --json | php -r 'echo json_decode(stream_get_contents(STDIN), true)["password"] ?? "";'
}

# How many lines of the dashboard Grafana currently serves name the given node.
# A transport failure counts as "still there" so the caller keeps polling.
served_dashboard_mentions() {
  local password address body count
  password=$(grafana_password)
  address=$(metrics_address)
  body=$(curl -s -u "admin:${password}" "http://${address}:3000/api/dashboards/uid/orbit-node-resources" || true)

  if [[ -z "$body" ]]; then
    echo 1
    return 0
  fi

  count=$(printf '%s' "$body" | grep -c "$1" || true)
  echo "${count:-0}"
}

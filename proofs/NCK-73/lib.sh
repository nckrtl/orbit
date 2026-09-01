#!/usr/bin/env bash
# Shared helpers for the NCK-73 proof fixtures. Sourced, never executed.
set -euo pipefail

CA=/home/orbit/.orbit/e2e-gateway-root-ca.pem
GATEWAY_DNS=10.44.0.1
STATE=/tmp/orbit-proof-nck-73
ORB7_CLEANUP_ROOT=/var/lib/orbit-e2e/proof-cleanup

fail() { echo "FAIL: $*" >&2; exit 1; }

orb7_service_record() {
  local action="$1"
  shift
  local record="$ORB7_CLEANUP_ROOT/$action"
  sudo test ! -e "$record" || fail "cleanup record already exists: $record"
  local baseline
  baseline=$(mktemp)
  local unit exists active
  for unit in "$@"; do
    exists=0
    active=inactive
    if systemctl cat "$unit" >/dev/null 2>&1; then
      exists=1
      active=$(systemctl is-active "$unit" 2>/dev/null || true)
    fi
    printf '%s\t%s\t%s\n' "$unit" "$exists" "$active" >>"$baseline"
  done
  sudo install -d -o root -g root -m 0700 -- "$record"
  sudo install -o root -g root -m 0600 -- "$baseline" "$record/services.tsv"
  printf 'armed\n' | sudo tee "$record/state" >/dev/null
  rm -f -- "$baseline"
}

orb7_restore_services() {
  local action="$1"
  local record="$ORB7_CLEANUP_ROOT/$action"
  sudo test -e "$record" || return 0
  sudo mkdir "$record/restoring" 2>/dev/null || return 0
  local unit exists active
  if sudo test -f "$record/services.tsv"; then
    while IFS=$'\t' read -r unit exists active; do
      if [[ "$exists" -eq 1 ]]; then
        if [[ "$active" == active ]]; then
          sudo systemctl start "$unit"
        else
          sudo systemctl stop "$unit"
        fi
      else
        ! systemctl cat "$unit" >/dev/null 2>&1 || return 1
      fi
    done < <(sudo cat "$record/services.tsv")
  fi
  printf 'restored\n' | sudo tee "$record/state" >/dev/null
  sudo rm -rf -- "$record"
}

orb7_cleanup_exit() {
  local status="$1"
  local action="$2"
  trap - EXIT INT TERM
  local cleanup_status=0
  orb7_restore_services "$action" || cleanup_status=$?
  if [[ "$status" -eq 0 && "$cleanup_status" -ne 0 ]]; then
    exit "$cleanup_status"
  fi
  exit "$status"
}

orb7_service_traps() {
  local action="$1"
  trap 'orb7_cleanup_exit "$?" '"'"$action"'"'' EXIT
  trap 'exit 130' INT
  trap 'exit 143' TERM
}

orb7_checkpoint() {
  local action="$1"
  local window="$2"
  if [[ "${ORBIT_E2E_ORB7_MODE:-}" == signal \
    && "${ORBIT_E2E_ORB7_CASE:-}" == "$action" \
    && "${ORBIT_E2E_ORB7_WINDOW:-}" == "$window" ]]; then
    printf 'ready\n' | sudo tee "${ORBIT_E2E_ORB7_CHECKPOINT:?}" >/dev/null
    if [[ "${ORBIT_E2E_ORB7_EVENT:-}" == EXIT ]]; then
      until sudo test -f "${ORBIT_E2E_ORB7_CHECKPOINT:?}.continue"; do sleep 0.1; done
      exit 0
    fi
    while true; do sleep 1; done
  fi
}

# Extracts one JSON path (dot separated) from stdin with PHP; prints nothing when absent.
json_get() {
  php -r '
    $data = json_decode(stream_get_contents(STDIN), true);
    foreach (explode(".", $argv[1]) as $key) {
      if (!is_array($data) || !array_key_exists($key, $data)) { exit(0); }
      $data = $data[$key];
    }
    if (is_bool($data)) { echo $data ? "true" : "false"; }
    elseif (is_array($data)) { echo json_encode($data); }
    elseif ($data !== null) { echo (string) $data; }
  ' -- "$1"
}

wireguard_address() {
  local addresses
  addresses=$(ip -4 -o addr show dev orbit)
  awk 'NR == 1 { split($4, address, "/"); print address[1] }' <<<"$addresses"
}

gateway_address() {
  orbit node:list --json | php -r '
    $nodes = json_decode(stream_get_contents(STDIN), true)["nodes"] ?? [];
    foreach ($nodes as $node) { if (in_array("gateway", $node["roles"] ?? [], true)) { echo $node["wireguard_address"]; exit(0); } }
    exit(1);
  '
}

metrics_address() {
  orbit metrics:status --json | json_get assignment.node_id | {
    read -r id
    orbit node:list --json | php -r '
      $id = (int) $argv[1];
      foreach (json_decode(stream_get_contents(STDIN), true)["nodes"] ?? [] as $node) { if ((int) $node["id"] === $id) { echo $node["wireguard_address"]; exit(0); } }
      exit(1);
    ' -- "$id"
  }
}

resolve_metrics() {
  local records
  records=$(dig +time=3 +tries=2 +short metrics.orbit @"$GATEWAY_DNS")
  awk 'NF { print; exit }' <<<"$records"
}

# curl against https://metrics.orbit through private DNS and the Orbit CA.
metrics_curl() {
  local resolved
  resolved=$(resolve_metrics)
  [[ "$resolved" =~ ^([0-9]{1,3}\.){3}[0-9]{1,3}$ ]] || fail "metrics.orbit did not resolve privately"
  curl --silent --show-error --max-time 10 --cacert "$CA" --resolve "metrics.orbit:443:$resolved" "$@"
}

ufw_has_comment() {
  local status
  status=$(sudo ufw status numbered)
  [[ "$status" == *"# $1"* ]]
}

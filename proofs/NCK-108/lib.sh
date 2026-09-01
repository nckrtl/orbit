#!/usr/bin/env bash
# Shared helpers for the NCK-108 proof fixtures. Sourced, never executed.
set -euo pipefail

fail() { echo "FAIL: $*" >&2; exit 1; }

ORB7_CLEANUP_ROOT=/var/lib/orbit-e2e/proof-cleanup

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

node_id() {
  orbit node:list --json | php -r '
    foreach (json_decode(stream_get_contents(STDIN), true)["nodes"] ?? [] as $node) {
      if (($node["name"] ?? null) === $argv[1]) { echo $node["id"]; exit(0); }
    }
    exit(1);
  ' -- "$1"
}

# One sorted line of "<name>=<desired|excluded>/<actual>/<reason>/<degraded_reason or none>".
exporter_summary() {
  php -r '
    $rows = [];
    foreach (json_decode(stream_get_contents(STDIN), true)["exporters"] ?? [] as $exporter) {
      $rows[] = $exporter["name"]
        . "=" . ($exporter["desired"] ? "desired" : "excluded")
        . "/" . $exporter["actual"]
        . "/" . $exporter["reason"]
        . "/" . ($exporter["degraded_reason"] ?? "none");
    }
    sort($rows);
    echo implode(" ", $rows);
  '
}

# Every expectation must appear in the current exporter summary.
assert_exporters() {
  local status summary expectation
  status=$(orbit metrics:status --json)
  summary=$(echo "$status" | exporter_summary)
  for expectation in "$@"; do
    [[ "$summary" == *"$expectation"* ]] || fail "exporter expectation [$expectation] not met: $summary"
  done
  echo "$summary"
}

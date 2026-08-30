#!/usr/bin/env bash
# Shared helpers for the NCK-108 proof fixtures. Sourced, never executed.
set -euo pipefail

fail() { echo "FAIL: $*" >&2; exit 1; }

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

#!/usr/bin/env bash
# Shared helpers for the NCK-107 proof fixtures. Sourced, never executed.
set -euo pipefail

CA=/home/orbit/.orbit/e2e-gateway-root-ca.pem
GATEWAY_DNS=10.44.0.1
PROMETHEUS=http://127.0.0.1:9090

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
  curl --silent --show-error --max-time 15 --cacert "$CA" --resolve "metrics.orbit:443:$resolved" "$@"
}

grafana_password() {
  local password
  password=$(orbit metrics:credentials --json | json_get password)
  [[ -n "$password" ]] || fail "no Grafana admin password available"
  printf '%s' "$password"
}

# The node labels Prometheus currently scrapes, sorted and comma separated.
scraped_nodes() {
  curl --silent --max-time 10 "$PROMETHEUS/api/v1/targets" | php -r '
    $targets = json_decode(stream_get_contents(STDIN), true)["data"]["activeTargets"] ?? [];
    $names = [];
    foreach ($targets as $target) {
      if (($target["health"] ?? "") === "up") { $names[] = $target["labels"]["node"] ?? ""; }
    }
    $names = array_values(array_filter(array_unique($names)));
    sort($names);
    echo implode(",", $names);
  '
}

prom_query() {
  curl --silent --max-time 15 --get --data-urlencode "query=$1" "$PROMETHEUS/api/v1/query" || true
}

# The provisioned dashboard as Grafana serves it, or an empty string while it is still loading.
served_dashboard() {
  metrics_curl --user "admin:$1" https://metrics.orbit/api/dashboards/uid/orbit-node-resources || true
}

# Grafana provisions dashboards from disk on a poll interval; wait for the full board.
wait_for_dashboard() {
  local served panels
  for _ in $(seq 1 30); do
    served=$(served_dashboard "$1")
    panels=$(printf '%s' "$served" | php -r '
      echo count(json_decode(stream_get_contents(STDIN), true)["dashboard"]["panels"] ?? []);
    ')
    if [[ "$panels" == 8 ]]; then
      printf '%s' "$served"
      return 0
    fi
    sleep 5
  done
  fail "Grafana never served the eight panel dashboard (last panel count: ${panels:-none})"
}

#!/usr/bin/env bash
# Shared helpers for the NCK-73 proof fixtures. Sourced, never executed.
set -euo pipefail

CA=/home/orbit/.orbit/e2e-gateway-root-ca.pem
GATEWAY_DNS=10.44.0.1
STATE=/tmp/orbit-proof-nck-73

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

wireguard_address() {
  ip -4 -o addr show dev orbit | awk '{ print $4 }' | cut -d/ -f1 | head -n 1
}

gateway_address() {
  orbit node:list --json | php -r '
    $nodes = json_decode(stream_get_contents(STDIN), true)["nodes"] ?? [];
    foreach ($nodes as $node) {
      if (!in_array("gateway", $node["roles"] ?? [], true)) { continue; }
      $address = $node["wireguard_ip"] ?? null;
      if (!is_string($address) || filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) { exit(1); }
      echo $address;
      exit(0);
    }
    exit(1);
  '
}

metrics_address() {
  orbit metrics:status --json | json_get assignment.node_id | {
    IFS= read -r id || [[ -n "${id:-}" ]]
    orbit node:list --json | php -r '
      $id = (int) $argv[1];
      foreach (json_decode(stream_get_contents(STDIN), true)["nodes"] ?? [] as $node) {
        if ((int) $node["id"] !== $id) { continue; }
        $address = $node["wireguard_ip"] ?? null;
        if (!is_string($address) || filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) { exit(1); }
        echo $address;
        exit(0);
      }
      exit(1);
    ' -- "$id"
  }
}

resolve_metrics() {
  dig +time=3 +tries=2 +short metrics.orbit @"$GATEWAY_DNS" | awk 'NF { print; exit }'
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

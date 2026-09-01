#!/usr/bin/env bash
set -euo pipefail

STATE=/tmp/orbit-proof-orb-19

fail() { echo "FAIL: $*" >&2; exit 1; }

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

node_id() {
  orbit node:list --json | php -r '
    $name = $argv[1];
    foreach (json_decode(stream_get_contents(STDIN), true)["nodes"] ?? [] as $node) {
      if ($node["name"] === $name) { echo $node["id"]; exit(0); }
    }
    exit(1);
  ' -- "$1"
}

gateway_address() {
  orbit node:list --json | php -r '
    foreach (json_decode(stream_get_contents(STDIN), true)["nodes"] ?? [] as $node) {
      if (in_array("gateway", $node["roles"] ?? [], true)) { echo $node["wireguard_address"]; exit(0); }
    }
    exit(1);
  '
}

grafana_password() {
  orbit metrics:credentials --json | json_get password
}

grafana_curl() {
  local password address
  password=$(grafana_password)
  address=$(wireguard_address)
  curl --silent --show-error --fail --max-time 15 --user "admin:$password" "$@" "http://$address:3000"
}

ufw_has_comment() {
  sudo ufw status numbered | grep -qF "# $1"
}

remove_ufw_comment() {
  local comment number
  comment=$1
  number=$(sudo ufw status numbered | sed -n "/# $comment/ s/^\[[[:space:]]*\([0-9][0-9]*\)\].*/\1/p" | head -n 1)
  [[ -n "$number" ]] || fail "UFW rule [$comment] is absent before drift"
  sudo ufw --force delete "$number" >/dev/null
}

doctor_firewall() {
  orbit doctor --node="$(node_id app-dev)" --family=firewall --json || true
}

doctor_has_issue() {
  php -r '
    $resource = $argv[1];
    $report = json_decode(stream_get_contents(STDIN), true);
    foreach ($report["nodes"] ?? [] as $node) {
      foreach ($node["families"] ?? [] as $family) {
        foreach ($family["issues"] ?? [] as $issue) {
          if (($issue["resource_id"] ?? null) === $resource && ($issue["code"] ?? null) === "firewall.rule_missing") { exit(0); }
        }
      }
    }
    exit(1);
  ' -- "$1"
}

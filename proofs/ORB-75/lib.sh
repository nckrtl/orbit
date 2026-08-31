#!/usr/bin/env bash
# Shared helpers for the ORB-75 proof fixtures. Sourced, never executed.
set -euo pipefail

fail() { echo "FAIL: $*" >&2; exit 1; }

json_get() {
  php -r '
    $data = json_decode(stream_get_contents(STDIN), true);
    foreach (explode(".", $argv[1]) as $key) {
      if (! is_array($data) || ! array_key_exists($key, $data)) { exit(0); }
      $data = $data[$key];
    }
    if (is_bool($data)) { echo $data ? "true" : "false"; }
    elseif (is_array($data) || $data === null) { echo json_encode($data, JSON_UNESCAPED_SLASHES); }
    else { echo (string) $data; }
  ' -- "$1"
}

json_has() {
  php -r '
    $data = json_decode(stream_get_contents(STDIN), true);
    echo is_array($data) && array_key_exists($argv[1], $data) ? "yes" : "no";
  ' -- "$1"
}

json_find() {
  php -r '
    $data = json_decode(stream_get_contents(STDIN), true);
    $items = $data[$argv[1]] ?? [];
    foreach (is_array($items) ? $items : [] as $item) {
      if (is_array($item) && ($item[$argv[2]] ?? null) === $argv[3]) {
        $value = $item[$argv[4]] ?? null;
        if (is_bool($value)) { echo $value ? "true" : "false"; }
        elseif (is_array($value) || $value === null) { echo json_encode($value, JSON_UNESCAPED_SLASHES); }
        else { echo (string) $value; }
        exit(0);
      }
    }
    exit(1);
  ' -- "$1" "$2" "$3" "$4"
}

cluster_id() {
  orbit cluster:list --json | json_find clusters name "$1" id
}

node_id() {
  orbit node:list --json | json_find nodes name "$1" id
}

node_field() {
  orbit node:list --json | json_find nodes name "$1" "$2"
}

cluster_snapshot() {
  orbit cluster:list --json | php -r '
    $data = json_decode(stream_get_contents(STDIN), true);
    $clusters = $data["clusters"] ?? [];
    usort($clusters, static fn (array $a, array $b): int => ($a["name"] ?? "") <=> ($b["name"] ?? ""));
    echo json_encode(array_map(static fn (array $cluster): array => [
      "name" => $cluster["name"] ?? null,
      "tld" => $cluster["tld"] ?? null,
      "state" => $cluster["state"] ?? null,
    ], $clusters), JSON_UNESCAPED_SLASHES);
  '
}

gateway_profile_field() {
  php -r '
    $data = json_decode((string) file_get_contents("/home/orbit/.orbit/config.json"), true);
    $name = is_array($data) ? ($data["active_gateway"] ?? null) : null;
    $profile = is_string($name) && is_array($data["gateways"][$name] ?? null)
      ? $data["gateways"][$name]
      : [];
    $value = $profile[$argv[1]] ?? null;
    if (! is_string($value) || $value === "") { exit(1); }
    echo $value;
  ' -- "$1"
}

gateway_json() {
  local method=$1
  local path=$2
  local body=${3:-}
  local url ca
  url=$(gateway_profile_field url) || fail "gateway URL missing"
  ca=$(gateway_profile_field ca_path) || fail "gateway CA path missing"
  local args=(
    -sS
    --cacert "$ca"
    -H 'Accept: application/json'
    -H 'Content-Type: application/json'
    -X "$method"
  )
  if [[ -n "$body" ]]; then
    args+=(--data "$body")
  fi
  curl "${args[@]}" "${url}${path}"
}

expect_error() {
  local code=$1
  shift
  local out status
  set +e
  out=$("$@" 2>&1)
  status=$?
  set -e
  [[ "$status" -eq 1 ]] || fail "expected exit 1 for $code, got $status: $out"
  [[ "$(echo "$out" | json_get error.code)" == "$code" ]] || fail "expected $code: $out"
}

expect_local_error() {
  local code=$1
  shift
  local out status
  set +e
  out=$("$@" 2>&1)
  status=$?
  set -e
  [[ "$status" -eq 1 ]] || fail "expected local exit 1 for $code, got $status: $out"
  [[ "$(echo "$out" | json_get error.code)" == "$code" ]] || fail "expected local $code: $out"
  [[ "$(echo "$out" | json_get error.request_id)" == null ]] || fail "$code reached the Gateway: $out"
}

expect_api_error() {
  local code=$1
  local method=$2
  local path=$3
  local body=$4
  local out
  out=$(gateway_json "$method" "$path" "$body")
  [[ "$(echo "$out" | json_get error.code)" == "$code" ]] || fail "expected API $code: $out"
}

expect_nonzero() {
  local status
  set +e
  "$@" >/dev/null 2>&1
  status=$?
  set -e
  [[ "$status" -ne 0 ]] || fail "expected command to fail: $*"
}

sql_node_field() {
  php -r '
    $allowed = ["cluster_id", "wireguard_ip", "wireguard_address", "lan_ip", "settings"];
    if (! in_array($argv[2], $allowed, true)) { exit(2); }
    $db = new PDO("sqlite:/home/orbit/.orbit/gateway.sqlite");
    $statement = $db->prepare("select {$argv[2]} from nodes where name = :name");
    $statement->execute(["name" => $argv[1]]);
    $value = $statement->fetchColumn();
    if ($value === false || $value === null) { echo "null"; exit(0); }
    echo (string) $value;
  ' -- "$1" "$2"
}

sql_legacy_settings() {
  php -r '
    $db = new PDO("sqlite:/home/orbit/.orbit/gateway.sqlite");
    $statement = $db->prepare("select settings from nodes where name = :name");
    $statement->execute(["name" => $argv[1]]);
    $stored = $statement->fetchColumn();
    $decoded = is_string($stored) ? json_decode($stored, true) : [];
    $legacy = [];
    foreach (["instance", "worktree"] as $key) {
      if (is_array($decoded) && array_key_exists($key, $decoded)) { $legacy[$key] = $decoded[$key]; }
    }
    echo json_encode($legacy, JSON_UNESCAPED_SLASHES);
  ' -- "$1"
}

sql_router_count() {
  php -r '
    $db = new PDO("sqlite:/home/orbit/.orbit/gateway.sqlite");
    $statement = $db->prepare("select count(*) from node_roles where cluster_id = :cluster and role = :role and status = :status");
    $statement->execute(["cluster" => (int) $argv[1], "role" => "router", "status" => "active"]);
    echo (string) $statement->fetchColumn();
  ' -- "$1"
}

sql_mirror_mismatches() {
  php -r '
    $db = new PDO("sqlite:/home/orbit/.orbit/gateway.sqlite");
    echo (string) $db->query(
      "select count(*) from nodes where wireguard_ip is not wireguard_address"
    )->fetchColumn();
  '
}

checkout_snapshot() {
  local instances workspaces
  instances=$(orbit instance:list --json)
  workspaces=$(orbit workspace:list --json)
  php -r '
    $paths = [];
    foreach ([[json_decode($argv[1], true), "instances"], [json_decode($argv[2], true), "workspaces"]] as [$data, $key]) {
      foreach (is_array($data[$key] ?? null) ? $data[$key] : [] as $item) {
        if (is_string($item["checkout_path"] ?? null)) { $paths[] = $key.":".$item["checkout_path"]; }
      }
    }
    sort($paths);
    echo json_encode($paths, JSON_UNESCAPED_SLASHES);
  ' -- "$instances" "$workspaces"
}

provision_app_dev() {
  local host fingerprint architecture tld
  host=$(node_field app-dev public_ssh_host)
  fingerprint=$(node_field app-dev ssh_host_fingerprint)
  architecture=$(node_field app-dev architecture)
  tld=$(node_field app-dev tld)
  orbit node:provision app-dev "$host" \
    --user=orbit \
    --host-key-fingerprint="$fingerprint" \
    --architecture="$architecture" \
    --tld="$tld" \
    --role=app-dev \
    "$@"
}

provision_app_prod() {
  local host fingerprint architecture
  host=$(node_field app-prod public_ssh_host)
  fingerprint=$(node_field app-prod ssh_host_fingerprint)
  architecture=$(node_field app-prod architecture)
  orbit node:provision app-prod "$host" \
    --user=orbit \
    --host-key-fingerprint="$fingerprint" \
    --architecture="$architecture" \
    "$@"
}

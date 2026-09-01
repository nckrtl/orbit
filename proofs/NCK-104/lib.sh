#!/usr/bin/env bash
# Shared helpers for the NCK-104 proof fixtures. Sourced, never executed.
set -euo pipefail

fail() { echo "FAIL: $*" >&2; exit 1; }

readonly ORB7_CLEANUP_ROOT=/var/lib/orbit-e2e/proof-cleanup
readonly ORB7_PROOF_ROOT=${ORBIT_E2E_PROOF_ROOT:-/var/lib/orbit-e2e/proof}
readonly ORB7_STATE_HELPER="$ORB7_PROOF_ROOT/orb-7-node-state.sh"
ORB7_ACTIVE_CLEANUP_HOOK=
readonly -a ORB7_SSH=(
  ssh
  -i /home/orbit/.orbit/ssh/id_ed25519
  -p 22
  -o BatchMode=yes
  -o StrictHostKeyChecking=yes
  -o UserKnownHostsFile=/home/orbit/.orbit/ssh/known_hosts
  -o ConnectTimeout=10
  -o ServerAliveInterval=5
  -o ServerAliveCountMax=2
)

orb7_node_address() {
  local name="$1"
  orbit node:list --json | php -r '
    $name=$argv[1];
    foreach (json_decode(stream_get_contents(STDIN), true)["nodes"] ?? [] as $node) {
      if (($node["name"] ?? null) === $name) { echo $node["wireguard_ip"]; exit(0); }
    }
    exit(1);
  ' -- "$name"
}

orb7_remote_state() {
  local role="$1"
  shift
  local address
  address=$(orb7_node_address "$role")
  "${ORB7_SSH[@]}" -- "orbit@$address" bash "$ORB7_STATE_HELPER" "$@"
}

orb7_arm_paths() {
  local action="$1"
  shift
  bash "$ORB7_STATE_HELPER" arm-paths "$action" "$@"
}

orb7_arm_remote_paths() {
  local role="$1"
  local action="$2"
  shift 2
  orb7_remote_state "$role" arm-paths "$action" "$@"
}

orb7_arm_database() {
  local action="$1"
  bash "$ORB7_STATE_HELPER" arm-database "$action"
}

orb7_arm_remote_database() {
  local action="$1"
  orb7_remote_state gateway arm-database "$action"
}

orb7_restore_action() {
  local action="$1"
  shift
  bash "$ORB7_STATE_HELPER" restore "$action"
  local role
  for role in "$@"; do
    orb7_remote_state "$role" restore "$action"
  done
}

orb7_discard_action() {
  local action="$1"
  shift
  bash "$ORB7_STATE_HELPER" discard "$action"
  local role
  for role in "$@"; do
    orb7_remote_state "$role" discard "$action"
  done
}

orb7_cleanup_exit() {
  local status="$1"
  local action="$2"
  shift 2
  trap - EXIT INT TERM
  local cleanup_status=0
  if [[ -n "${ORB7_ACTIVE_CLEANUP_HOOK:-}" ]]; then
    "$ORB7_ACTIVE_CLEANUP_HOOK" || cleanup_status=$?
  fi
  local restore_status=0
  orb7_restore_action "$action" "$@" || restore_status=$?
  if [[ "$cleanup_status" -eq 0 && "$restore_status" -ne 0 ]]; then
    cleanup_status=$restore_status
  fi
  if [[ "$status" -eq 0 && "$cleanup_status" -ne 0 ]]; then
    exit "$cleanup_status"
  fi
  exit "$status"
}

orb7_traps() {
  local action="$1"
  shift
  ORB7_ACTIVE_ACTION="$action"
  ORB7_ACTIVE_REMOTES=("$@")
  ORB7_ACTIVE_CLEANUP_HOOK=
  trap 'orb7_cleanup_exit "$?" "$ORB7_ACTIVE_ACTION" "${ORB7_ACTIVE_REMOTES[@]}"' EXIT
  trap 'exit 130' INT
  trap 'exit 143' TERM
}

orb7_set_cleanup_hook() {
  local hook="$1"
  declare -F "$hook" >/dev/null || fail "cleanup hook is not a function: $hook"
  ORB7_ACTIVE_CLEANUP_HOOK="$hook"
}

orb7_clear_cleanup_hook() {
  ORB7_ACTIVE_CLEANUP_HOOK=
}

orb7_mark_active() {
  local action="$1"
  shift
  bash "$ORB7_STATE_HELPER" mark "$action" active
  local role
  for role in "$@"; do
    orb7_remote_state "$role" mark "$action" active
  done
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

orb7_complete() {
  local action="$1"
  shift
  orb7_discard_action "$action" "$@"
  trap - EXIT INT TERM
}

orb7_publish() {
  local action="$1"
  shift
  bash "$ORB7_STATE_HELPER" mark "$action" published
  local role
  for role in "$@"; do
    orb7_remote_state "$role" mark "$action" published
  done
  trap - EXIT INT TERM
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
    elseif (is_array($data) || $data === null) { echo json_encode($data, JSON_UNESCAPED_SLASHES); }
    else { echo (string) $data; }
  ' -- "$1"
}

sql_node_settings() {
  php -r '
    $d = new PDO("sqlite:/home/orbit/.orbit/gateway.sqlite");
    $name = $argv[1];
    $statement = $d->prepare("select settings from nodes where name = :name");
    $statement->execute(["name" => $name]);
    $value = $statement->fetchColumn();
    if ($value === false || $value === null) {
      echo "null";
      exit(0);
    }
    echo json_encode(json_decode((string) $value, true), JSON_UNESCAPED_SLASHES);
  ' -- "$1"
}

sql_workspace_origin() {
  php -r '
    $d = new PDO("sqlite:/home/orbit/.orbit/gateway.sqlite");
    $name = $argv[1];
    $statement = $d->prepare("select checkout_path_origin from workspaces where name = :name");
    $statement->execute(["name" => $name]);
    $value = $statement->fetchColumn();
    if ($value === false || $value === null) {
      echo "null";
      exit(0);
    }
    echo (string) $value;
  ' -- "$1"
}

app_dev_settings() {
  orbit node:list --json | php -r '
    foreach (json_decode(stream_get_contents(STDIN), true)["nodes"] ?? [] as $node) {
      if (($node["name"] ?? null) === "app-dev") {
        echo json_encode($node["settings"] ?? null, JSON_UNESCAPED_SLASHES);
        exit(0);
      }
    }
    exit(1);
  '
}

instance_id() {
  orbit instance:list --json | php -r '
    $name = $argv[1];
    foreach (json_decode(stream_get_contents(STDIN), true)["instances"] ?? [] as $instance) {
      if (($instance["name"] ?? null) === $name) {
        echo $instance["id"];
        exit(0);
      }
    }
    exit(1);
  ' -- "$1"
}

workspace_id() {
  orbit workspace:list --json | php -r '
    $name = $argv[1];
    foreach (json_decode(stream_get_contents(STDIN), true)["workspaces"] ?? [] as $workspace) {
      if (($workspace["name"] ?? null) === $name) {
        echo $workspace["id"];
        exit(0);
      }
    }
    exit(1);
  ' -- "$1"
}

instance_checkout() {
  orbit instance:list --json | php -r '
    $name = $argv[1];
    foreach (json_decode(stream_get_contents(STDIN), true)["instances"] ?? [] as $instance) {
      if (($instance["name"] ?? null) === $name) {
        echo $instance["checkout_path"];
        exit(0);
      }
    }
    exit(1);
  ' -- "$1"
}

workspace_checkout() {
  orbit workspace:list --json | php -r '
    $name = $argv[1];
    foreach (json_decode(stream_get_contents(STDIN), true)["workspaces"] ?? [] as $workspace) {
      if (($workspace["name"] ?? null) === $name) {
        echo $workspace["checkout_path"];
        exit(0);
      }
    }
    exit(1);
  ' -- "$1"
}

expect_error() {
  local code=$1
  shift
  local out
  set +e
  out=$("$@" 2>&1)
  local status=$?
  set -e
  [[ "$status" -eq 1 ]] || fail "expected exit 1 for $code, got $status: $out"
  [[ "$(echo "$out" | json_get error.code)" == "$code" ]] || fail "expected error $code: $out"
}

expect_local_error() {
  local code=$1
  shift
  local out
  set +e
  out=$("$@" 2>&1)
  local status=$?
  set -e
  [[ "$status" -eq 1 ]] || fail "expected exit 1 for $code, got $status: $out"
  [[ "$(echo "$out" | json_get error.code)" == "$code" ]] || fail "expected error $code: $out"
  [[ "$(echo "$out" | json_get error.request_id)" == null ]] \
    || fail "CLI syntax error reached the Gateway: $out"
}

gateway_profile_field() {
  php -r '
    $data = json_decode((string) file_get_contents("/home/orbit/.orbit/config.json"), true);
    $name = is_array($data) ? ($data["active_gateway"] ?? null) : null;
    $profile = is_string($name) && is_array($data["gateways"][$name] ?? null)
      ? $data["gateways"][$name]
      : [];
    $value = $profile[$argv[1]] ?? null;
    if (! is_string($value) || $value === "") {
      exit(1);
    }
    echo $value;
  ' -- "$1"
}

gateway_json() {
  local method=$1
  local url_path=$2
  local body=$3
  local url ca
  url=$(gateway_profile_field url) || fail "gateway url missing"
  ca=$(gateway_profile_field ca_path) || fail "gateway ca_path missing"
  curl -sS --cacert "$ca" \
    -H 'Content-Type: application/json' \
    -H 'Accept: application/json' \
    -X "$method" \
    --data "$body" \
    "${url}${url_path}"
}

expect_http_error() {
  local code=$1
  local method=$2
  local url_path=$3
  local body=$4
  local out
  out=$(gateway_json "$method" "$url_path" "$body")
  [[ "$(echo "$out" | json_get error.code)" == "$code" ]] \
    || fail "expected $code from $method $url_path: $out"
}

sql_node_exists() {
  php -r '
    $d = new PDO("sqlite:/home/orbit/.orbit/gateway.sqlite");
    $statement = $d->prepare("select count(*) from nodes where name = :name");
    $statement->execute(["name" => $argv[1]]);
    echo (string) $statement->fetchColumn();
  ' -- "$1"
}

restore_default_roots() {
  orbit node:settings app-dev \
    --setting=instance.path:/srv/orbit/instances \
    --setting=worktree.path:/srv/orbit/worktrees \
    --json >/dev/null
}

node_id() {
  orbit node:list --json | php -r '
    $name = $argv[1];
    foreach (json_decode(stream_get_contents(STDIN), true)["nodes"] ?? [] as $node) {
      if (($node["name"] ?? null) === $name) {
        echo $node["id"];
        exit(0);
      }
    }
    exit(1);
  ' -- "$1"
}

node_field() {
  orbit node:list --json | php -r '
    $name = $argv[1];
    $field = $argv[2];
    foreach (json_decode(stream_get_contents(STDIN), true)["nodes"] ?? [] as $node) {
      if (($node["name"] ?? null) === $name && array_key_exists($field, $node) && $node[$field] !== null) {
        echo is_scalar($node[$field]) ? (string) $node[$field] : json_encode($node[$field]);
        exit(0);
      }
    }
    exit(1);
  ' -- "$1" "$2"
}

app_id() {
  orbit app:list --json | php -r '
    $slug = $argv[1];
    foreach (json_decode(stream_get_contents(STDIN), true)["apps"] ?? [] as $app) {
      if (($app["slug"] ?? null) === $slug) {
        echo $app["id"];
        exit(0);
      }
    }
    exit(1);
  ' -- "$1"
}

sql_clear_workspace_origin() {
  php -r '
    $d = new PDO("sqlite:/home/orbit/.orbit/gateway.sqlite");
    $statement = $d->prepare("update workspaces set checkout_path_origin = null where name = :name");
    $statement->execute(["name" => $argv[1]]);
  ' -- "$1"
}

sql_set_workspace_origin() {
  php -r '
    $d = new PDO("sqlite:/home/orbit/.orbit/gateway.sqlite");
    $statement = $d->prepare("update workspaces set checkout_path_origin = :origin where name = :name");
    $statement->execute(["origin" => $argv[2], "name" => $argv[1]]);
  ' -- "$1" "$2"
}

provision_app_dev() {
  orbit node:provision app-dev "$(node_field app-dev public_ssh_host)" \
    --user=orbit \
    --host-key-fingerprint="$(node_field app-dev ssh_host_fingerprint)" \
    --architecture="$(node_field app-dev architecture)" \
    --tld="$(node_field app-dev tld)" \
    --role=app-dev \
    "$@"
}

provision_app_prod() {
  orbit node:provision app-prod "$(node_field app-prod public_ssh_host)" \
    --user=orbit \
    --host-key-fingerprint="$(node_field app-prod ssh_host_fingerprint)" \
    --architecture="$(node_field app-prod architecture)" \
    "$@"
}

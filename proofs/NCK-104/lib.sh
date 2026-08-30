#!/usr/bin/env bash
# Shared helpers for the NCK-104 proof fixtures. Sourced, never executed.
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

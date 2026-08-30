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

restore_default_roots() {
  orbit node:settings app-dev \
    --setting=instance.path:/srv/orbit/instances \
    --setting=worktree.path:/srv/orbit/worktrees \
    --json >/dev/null
}

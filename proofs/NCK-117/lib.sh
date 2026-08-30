#!/usr/bin/env bash
# Shared helpers for the NCK-117 proof fixtures. Sourced, never executed.
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

node_present() {
  orbit node:list --json | php -r '
    foreach (json_decode(stream_get_contents(STDIN), true)["nodes"] ?? [] as $node) {
      if (($node["name"] ?? null) === $argv[1]) { echo "yes"; exit(0); }
    }
    echo "no";
  ' -- "$1"
}

# The role names one node still holds, sorted and comma joined; "none" when empty.
node_roles() {
  orbit node:list --json | php -r '
    foreach (json_decode(stream_get_contents(STDIN), true)["nodes"] ?? [] as $node) {
      if (($node["name"] ?? null) !== $argv[1]) { continue; }
      $roles = $node["roles"] ?? [];
      sort($roles);
      echo $roles === [] ? "none" : implode(",", $roles);
      exit(0);
    }
    echo "absent";
  ' -- "$1"
}

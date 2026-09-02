#!/usr/bin/env bash
set -euo pipefail

readonly NCK121_DB=/home/orbit/.orbit/gateway.sqlite
readonly NCK121_AUTH_SOURCE=10.44.0.1

fail() {
  echo "FAIL: $*" >&2
  exit 1
}

json_get() {
  php -r '
    $data = json_decode(stream_get_contents(STDIN), true);
    foreach (explode(".", $argv[1]) as $key) {
      if (! is_array($data) || ! array_key_exists($key, $data)) {
        exit(0);
      }
      $data = $data[$key];
    }
    if (is_bool($data)) {
      echo $data ? "true" : "false";
    } elseif (is_array($data)) {
      echo json_encode($data);
    } elseif ($data !== null) {
      echo (string) $data;
    }
  ' -- "$1"
}

wireguard_address() {
  ip -4 -o addr show dev orbit | awk '{ print $4 }' | cut -d/ -f1 | head -n 1
}

ufw_has_comment() {
  sudo ufw status | grep -Fq "# $1"
}

assert_non_orbit_boundary() {
  local user=$1
  local groups

  groups=$(id -nG -- "$user")
  [[ " $groups " != *" docker "* ]] || fail "$user gained Docker-socket group access"
  if sudo -n -u "$user" -- docker ps >/dev/null 2>&1; then
    fail "$user can use the Docker socket without the fixed sudo command boundary"
  fi
}

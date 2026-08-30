#!/usr/bin/env bash
# Shared helpers for the NCK-116 proof fixtures. Sourced, never executed.
set -euo pipefail

readonly ESCAPE=/usr/local/sbin/orbit-metrics-uninstall

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

this_address() { hostname -I | tr -s ' ' '\n' | grep '^10\.44\.' | head -1; }

# Runs the escape and prints its output; the exit code lands in ESCAPE_STATUS.
run_escape() {
  set +e
  ESCAPE_OUTPUT=$(sudo "$ESCAPE" "$@" 2>&1)
  ESCAPE_STATUS=$?
  set -e
  printf '%s\n' "$ESCAPE_OUTPUT"
}

container_exists() { docker container ls --all --format '{{.Names}}' | grep -qx "$1"; }

volume_exists() { docker volume ls --format '{{.Name}}' | grep -qx "$1"; }

# True when a UFW rule carries exactly this comment. The comment ends the line,
# so the match is anchored there and a look-alike neighbour cannot satisfy it.
firewall_rule_exists() { sudo ufw status | grep -q "# $1\$"; }

delete_firewall_rule() {
  local number
  number=$(sudo ufw status numbered | grep "# $1\$" | sed -E 's/^ *\[ *([0-9]+)\].*/\1/' | head -1)
  [[ -n "$number" ]] || return 0
  sudo ufw --force delete "$number" >/dev/null
}

package_installed() { dpkg-query --show --showformat='${db:Status-Status}' "$1" 2>/dev/null | grep -qx installed; }

assert_reports() {
  local needle="$1"
  [[ "$ESCAPE_OUTPUT" == *"$needle"* ]] || fail "escape output does not report [$needle]: $ESCAPE_OUTPUT"
}

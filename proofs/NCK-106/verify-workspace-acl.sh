#!/usr/bin/env bash
set -euo pipefail

umask 077
cd /
orbit=/home/orbit/orbit/apps/cli/orbit

if [[ "$(id -u)" -eq 0 ]]; then
  exec sudo -u orbit -- env HOME=/home/orbit ORBIT_HOME=/home/orbit/.orbit \
    DB_DATABASE=/home/orbit/.orbit/gateway.sqlite bash "$0" "$@"
fi

[[ "$(id -un)" == orbit ]]
[[ -x "$orbit" ]]

acl_path=/home/orbit/.orbit
before_acl=$(getfacl -cp -- "$acl_path" 2>/dev/null | sed -n '/^user:caddy:/p' || true)
[[ "$before_acl" == "user:caddy:--x" ]]

# Selecting the existing E2E profile rewrites config.json through the
# repository under test.
"$orbit" gateway:use e2e --json >/dev/null

after_acl=$(getfacl -cp -- "$acl_path" 2>/dev/null | sed -n '/^user:caddy:/p' || true)
[[ "$after_acl" == "user:caddy:--x" ]]

config=/home/orbit/.orbit/config.json
[[ -f "$config" ]]
[[ "$(stat -c '%a' -- "$config")" == 600 ]]

workspace_json=$("$orbit" workspace:list --json)
workspace_host=$(php -r '
  $v=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
  foreach ($v["workspaces"] ?? [] as $workspace) {
    if (is_string($workspace["hostname"] ?? null) && $workspace["hostname"] !== "") {
      echo $workspace["hostname"]; exit;
    }
  }
  exit(1);
' <<<"$workspace_json")

http_status=$(curl --fail --silent --show-error --cacert /etc/ssl/certs/ca-certificates.crt \
  --resolve "$workspace_host:443:127.0.0.1" \
  -o /dev/null -w '%{http_code}\n' "https://$workspace_host/")
[[ "$http_status" == 200 ]]

# Caddy gets traversal on the workspace ancestor, but no read/list access to
# unrelated Orbit state.
! sudo -u caddy -- test -r "$config"
[[ -z "$(sudo -u caddy -- find /home/orbit/.orbit -maxdepth 1 -type f -print -quit 2>/dev/null)" ]]

cli_home=$(mktemp -d /tmp/orbit-nck-106-cli-only.XXXXXX)
trap 'rm -rf -- "$cli_home"' EXIT
chmod 0700 -- "$cli_home"
install -m 0600 -- "$config" "$cli_home/config.json"
ORBIT_HOME="$cli_home" "$orbit" gateway:use e2e --json >/dev/null
[[ "$(stat -c '%a' -- "$cli_home")" == 700 ]]
[[ "$(stat -c '%a' -- "$cli_home/config.json")" == 600 ]]
! grep -q '^user:caddy:' <<<"$(getfacl -cp -- "$cli_home" 2>/dev/null)"

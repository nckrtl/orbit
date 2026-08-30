#!/usr/bin/env bash
set -euo pipefail
state=/tmp/orbit-nck-103-state
test -s "$state"

json_field() {
  php -r '
    $data = json_decode(stream_get_contents(STDIN), true);
    echo $data[$argv[1]] ?? $data["data"][$argv[1]] ?? "";
  ' -- "$1"
}

normalize_json() {
  php -r '
    function normalize(mixed $value): mixed {
      if (!is_array($value)) { return $value; }
      foreach ($value as $key => $item) {
        if ($key === "request_id") { unset($value[$key]); continue; }
        $value[$key] = normalize($item);
      }
      if (!array_is_list($value)) { ksort($value); }
      return $value;
    }
    echo json_encode(normalize(json_decode(stream_get_contents(STDIN), true)));
  '
}

mapfile -t values < "$state"
slug=${values[0]}; node_id=${values[1]}; instance_id=${values[3]}; workspace_id=${values[4]}; old_tld=${values[5]}
node=$(orbit node:show "$node_id" --json)
instance=$(orbit instance:show "$instance_id" --json)
workspace=$(orbit workspace:show "$workspace_id" --json)
node_tld=$(json_field tld <<<"$node")
instance_host=$(json_field hostname <<<"$instance")
workspace_host=$(json_field hostname <<<"$workspace")
test "$node_tld" = test
test "$instance_host" = "$slug.test"
test "$workspace_host" = "preview.$slug.test"
old_instance_host="$slug.$old_tld"
old_workspace_host="preview.$slug.$old_tld"
node_address=$(ip -4 -o addr show | awk '$4 ~ /^10\.44\./ {sub(/\/.*$/, "", $4); print $4; exit}')
test -n "$node_address"
for host in "$instance_host" "$workspace_host"; do
  gateway_address=$(dig +short "$host" @10.44.0.1 | awk 'NF {print; exit}')
  system_address=$(getent ahostsv4 "$host" | awk 'NR==1 {print $1}')
  test "$gateway_address" = "$node_address"
  test "$system_address" = "$node_address"
  curl --fail --silent --show-error --cacert /etc/ssl/certs/ca-certificates.crt --resolve "$host:443:127.0.0.1" "https://$host/" >/dev/null
done
! getent ahostsv4 "$old_instance_host" >/dev/null
! getent ahostsv4 "$old_workspace_host" >/dev/null
test -z "$(dig +short "$old_instance_host" @10.44.0.1 | awk 'NF {print; exit}')"
test -z "$(dig +short "$old_workspace_host" @10.44.0.1 | awk 'NF {print; exit}')"
active_caddy=$(sudo readlink -f /etc/caddy/Caddyfile)
active_fragments=/etc/caddy/orbit-versions/"$(basename "$(dirname "$active_caddy")")"/fragments
for host in "$instance_host" "$workspace_host"; do
  sudo grep -FRq "$host" "$active_caddy" "$active_fragments"
done
! sudo grep -FRq "$old_tld" "$active_caddy" "$active_fragments" 2>/dev/null
sudo grep -Fq "\\~test" /etc/wireguard/orbit.conf
! sudo grep -Fq "\\~$old_tld" /etc/wireguard/orbit.conf
resolved_domains=$(resolvectl domain orbit)
[[ "$resolved_domains" == *"~test"* ]]
[[ "$resolved_domains" != *"~$old_tld"* ]]
before=$(orbit node:show "$node_id" --json | normalize_json)
orbit node:list --json >/dev/null
orbit node:provision app-dev --user=orbit --orbit-user=orbit --tld=.TEST --json >/dev/null
after=$(orbit node:show "$node_id" --json | normalize_json)
test "$before" = "$after"

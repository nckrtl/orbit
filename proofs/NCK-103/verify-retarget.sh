#!/usr/bin/env bash
set -euo pipefail
state=/tmp/orbit-nck-103-state
test -s "$state"
mapfile -t values < "$state"
slug=${values[0]}; node_id=${values[1]}; instance_id=${values[3]}; workspace_id=${values[4]}; old_tld=${values[5]}
node=$(orbit node:show "$node_id" --json)
instance=$(orbit instance:show "$instance_id" --json)
workspace=$(orbit workspace:show "$workspace_id" --json)
node_tld=$(jq -r '.. | objects | .tld? // empty' <<<"$node" | head -n1)
instance_host=$(jq -r '.. | objects | .hostname? // empty' <<<"$instance" | head -n1)
workspace_host=$(jq -r '.. | objects | .hostname? // empty' <<<"$workspace" | head -n1)
test "$node_tld" = test
test "$instance_host" = "$slug.test"
test "$workspace_host" = "preview.$slug.test"
old_instance_host="$slug.$old_tld"
old_workspace_host="preview.$slug.$old_tld"
node_address=$(ip -4 -o addr show | awk '$4 ~ /^10\.44\./ {sub(/\/.*$/, "", $4); print $4; exit}')
test -n "$node_address"
for host in "$instance_host" "$workspace_host"; do
  address=$(getent ahostsv4 "$host" | awk 'NR==1 {print $1}')
  test "$address" = "$node_address"
  curl --fail --silent --show-error --cacert /etc/ssl/certs/ca-certificates.crt --resolve "$host:443:127.0.0.1" "https://$host/" >/dev/null
done
! getent ahostsv4 "$old_instance_host" >/dev/null
! getent ahostsv4 "$old_workspace_host" >/dev/null
active_caddy=$(readlink -f /etc/caddy/Caddyfile)
active_fragments=/etc/caddy/orbit-versions/"$(basename "$(dirname "$active_caddy")")"/fragments
for host in "$instance_host" "$workspace_host"; do
  sudo grep -FRq "$host" "$active_caddy" "$active_fragments"
  sudo grep -Fq "$host" /etc/dnsmasq.d/orbit-records.conf
done
! sudo grep -FRq "$old_tld" "$active_caddy" "$active_fragments" 2>/dev/null
! sudo grep -Fq "$old_tld" /etc/dnsmasq.d/orbit-records.conf 2>/dev/null
before=$(orbit node:show "$node_id" --json | jq -S 'del(.. | .request_id?)')
orbit node:list --json >/dev/null
orbit node:provision app-dev --user=orbit --orbit-user=orbit --tld=.TEST --json >/dev/null
after=$(orbit node:show "$node_id" --json | jq -S 'del(.. | .request_id?)')
test "$before" = "$after"

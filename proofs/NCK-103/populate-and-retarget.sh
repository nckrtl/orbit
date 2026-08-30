#!/usr/bin/env bash
set -euo pipefail
state=/tmp/orbit-nck-103-state
rm -f "$state"
node_json=$(orbit node:list --json)
node_id=$(jq -r '.. | objects | select(.name? == "app-dev") | .id' <<<"$node_json" | head -n1)
test -n "$node_id" -a "$node_id" != null
old_tld=$(jq -r '.. | objects | .tld? // empty' <<<"$(orbit node:show "$node_id" --json)" | head -n1)
test -n "$old_tld" -a "$old_tld" != null
slug="nck103-$(date +%s)"
app_json=$(orbit app:new "$slug" https://github.com/laravel/laravel.git --json)
app_id=$(jq -r '.id // .data.id' <<<"$app_json")
orbit node:list --json >/dev/null
instance_json=$(orbit instance:new "$app_id" "$node_id" web --json)
instance_id=$(jq -r '.id // .data.id' <<<"$instance_json")
orbit node:list --json >/dev/null
workspace_json=$(orbit workspace:new "$instance_id" preview --json)
workspace_id=$(jq -r '.id // .data.id' <<<"$workspace_json")
printf '%s\n' "$slug" "$node_id" "$app_id" "$instance_id" "$workspace_id" "$old_tld" > "$state"
orbit node:list --json >/dev/null
orbit node:provision app-dev --user=orbit --orbit-user=orbit --tld=.TEST --json >/dev/null

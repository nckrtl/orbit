#!/usr/bin/env bash
set -euo pipefail
state=/tmp/orbit-nck-103-state
rm -f "$state"

json_id() {
  php -r '
    $data = json_decode(stream_get_contents(STDIN), true);
    echo $data["id"] ?? $data["data"]["id"] ?? "";
  '
}

json_field() {
  php -r '
    $data = json_decode(stream_get_contents(STDIN), true);
    echo $data[$argv[1]] ?? $data["data"][$argv[1]] ?? "";
  ' -- "$1"
}

node_json=$(orbit node:list --json)
node_id=$(php -r '
  foreach (json_decode(stream_get_contents(STDIN), true)["nodes"] ?? [] as $node) {
    if (($node["name"] ?? null) === "app-dev") { echo $node["id"]; exit(0); }
  }
  exit(1);
' <<<"$node_json")
test -n "$node_id" -a "$node_id" != null
old_tld=$(json_field tld <<<"$(orbit node:show "$node_id" --json)")
test -n "$old_tld" -a "$old_tld" != null
slug="nck103-$(date +%s)"
app_json=$(orbit app:new "$slug" https://github.com/laravel/laravel.git --json)
app_id=$(json_id <<<"$app_json")
orbit node:list --json >/dev/null
instance_json=$(orbit instance:new "$app_id" "$node_id" web --json)
instance_id=$(json_id <<<"$instance_json")
instance_path=$(json_field checkout_path <<<"$instance_json")
orbit node:list --json >/dev/null
workspace_json=$(orbit workspace:new "$instance_id" preview --json)
workspace_id=$(json_id <<<"$workspace_json")
workspace_path=$(json_field checkout_path <<<"$workspace_json")
printf '%s\n' '<?php echo "orbit-nck-103";' > "$instance_path/public/index.php"
printf '%s\n' '<?php echo "orbit-nck-103";' > "$workspace_path/public/index.php"
printf '%s\n' "$slug" "$node_id" "$app_id" "$instance_id" "$workspace_id" "$old_tld" > "$state"
orbit node:list --json >/dev/null
orbit node:provision app-dev --user=orbit --orbit-user=orbit --tld=.TEST --json >/dev/null

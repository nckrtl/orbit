#!/usr/bin/env bash
set -euo pipefail
umask 077
cd /

orbit=/home/orbit/orbit/apps/cli/orbit
converge=/usr/local/bin/converge-sample-app.sh

run_orbit() {
  sudo -u orbit -- env \
    HOME=/home/orbit \
    ORBIT_HOME=/home/orbit/.orbit \
    DB_DATABASE=/home/orbit/.orbit/gateway.sqlite \
    "$orbit" "$@"
}

instances=$(run_orbit instance:list --json)
read -r app_id app_instance_id starting_commit < <(php -r '$v=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR); if(!is_array($v) || !is_array($v["app_instances"] ?? null) || !array_is_list($v["app_instances"])) exit(65); $matches=array_values(array_filter($v["app_instances"], fn($item) => is_array($item) && ($item["name"] ?? null)==="e2e-dev")); if(count($matches)!==1) exit(1); $instance=$matches[0]; if(!is_int($instance["app_id"] ?? null) || $instance["app_id"]<1 || !is_int($instance["id"] ?? null) || $instance["id"]<1 || ($instance["status"] ?? null)!=="active" || !is_string($instance["starting_commit"] ?? null) || preg_match("/\\A[0-9a-f]{40}\\z/D", $instance["starting_commit"])!==1) exit(1); echo $instance["app_id"], " ", $instance["id"], " ", $instance["starting_commit"], "\n";' <<<"$instances")

clusters=$(run_orbit cluster:list --json)
cluster_id=$(php -r '$v=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR); if(!is_array($v) || !is_array($v["clusters"] ?? null) || !array_is_list($v["clusters"])) exit(65); $matches=array_values(array_filter($v["clusters"], fn($cluster) => is_array($cluster) && ($cluster["name"] ?? null)==="e2e-development")); if(count($matches)!==1 || !is_int($matches[0]["id"] ?? null) || $matches[0]["id"]<1 || ($matches[0]["state"] ?? null)!=="active") exit(1); echo $matches[0]["id"];' <<<"$clusters")

exact_route_id() {
  php -r '$v=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR); if(!is_array($v) || !is_array($v["routes"] ?? null) || !array_is_list($v["routes"])) exit(65); $matches=[]; foreach($v["routes"] as $route) { if(!is_array($route)) exit(65); $target=$route["target"] ?? null; if(($route["hostname"] ?? null)==="e2e-dev.orbit" || (is_array($target) && ($target["app_instance_id"] ?? null)===(int)$argv[2])) $matches[]=$route; } if(count($matches)!==1) exit(1); $route=$matches[0]; $target=$route["target"] ?? null; if(!is_int($route["id"] ?? null) || $route["id"]<1 || ($route["app_id"] ?? null)!==(int)$argv[1] || !array_key_exists("node_id", $route) || $route["node_id"]!==null || ($route["cluster_id"] ?? null)!==(int)$argv[3] || !array_key_exists("generation_basis_node_id", $route) || $route["generation_basis_node_id"]!==null || ($route["hostname"] ?? null)!=="e2e-dev.orbit" || ($route["provenance"] ?? null)!=="explicit" || ($route["publication"] ?? null)!=="private" || !is_array($target) || ($target["app_instance_id"] ?? null)!==(int)$argv[2] || ($target["position"] ?? null)!==0) exit(1); echo $route["id"];' "$app_id" "$app_instance_id" "$cluster_id"
}

before=$(run_orbit route:list --json)
route_id=$(exact_route_id <<<"$before")

"$converge" create-resources app-dev app-prod "$starting_commit" >/dev/null

after=$(run_orbit route:list --json)
retained_route_id=$(exact_route_id <<<"$after")
[[ "$retained_route_id" == "$route_id" ]]

printf 'Route %s remains the sole explicit private e2e-dev.orbit association after repeated convergence.\n' "$route_id"

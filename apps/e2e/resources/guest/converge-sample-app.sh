#!/usr/bin/env bash
set -euo pipefail
umask 077
# incus exec starts in /root, which the orbit account cannot enter; child
# processes spawned by the CLI need a readable working directory.
cd /
orbit=/home/orbit/orbit/apps/cli/orbit
sample_state=/home/orbit/.orbit/e2e-sample-app-state.json

# Older snapshots carry the previous collection name. Preserve malformed and
# ambiguous envelopes so the calling operation can reject them with its context.
instance_list() {
  local output status
  if output=$("$orbit" instance:list --json); then
    php -r '$raw=stream_get_contents(STDIN); $v=json_decode($raw); if(is_object($v) && property_exists($v, "app_instances") && !property_exists($v, "instances")) { $v->instances=$v->app_instances; unset($v->app_instances); echo json_encode($v, JSON_THROW_ON_ERROR); } else { echo $raw; }' <<<"$output"
  else
    status=$?
    printf '%s' "$output"
    return "$status"
  fi
}
sample_state_json() {
  php -r '$s=json_decode(file_get_contents($argv[1]), true, 16, JSON_THROW_ON_ERROR); if(($s["shape"] ?? null)==="app_instances") $s["shape"]="instances"; elseif($s===["shape"=>"instances"]) $s["shape"]="workspaces"; echo json_encode($s, JSON_THROW_ON_ERROR);' "$sample_state"
}
case ${1-} in
  grant-operator)
    [[ "$(id -u)" -eq 0 ]] && exec sudo -u orbit -- env HOME=/home/orbit ORBIT_HOME=/home/orbit/.orbit DB_DATABASE=/home/orbit/.orbit/gateway.sqlite bash "$0" "$@"
    [[ $# -eq 3 && "$2" =~ ^[a-zA-Z0-9][a-zA-Z0-9.-]{0,62}$ && "$3" =~ ^[a-zA-Z0-9][a-zA-Z0-9.-]{0,62}$ ]] || { echo "grant-operator: invalid arguments" >&2; exit 64; }
    ca=/home/orbit/.orbit/ca/root.pem
    [[ -s "$ca" ]] || { echo "grant-operator: missing root CA at $ca ($(id -un))" >&2; ls -ln /home/orbit/.orbit/ca >&2; exit 66; }
    if ! output=$("$orbit" gateway:add https://10.44.0.1 --name=e2e --ca="$ca" --use --json 2>&1); then
      printf 'gateway:add failed: %s\n' "$output" >&2
      exit 1
    fi
    if ! nodes=$("$orbit" node:list --json 2>&1); then
      printf 'node:list failed: %s\n' "$nodes" >&2
      exit 1
    fi
    operator_id=$(php -r '$v=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR); $m=array_values(array_filter($v["nodes"], fn($x) => ($x["name"] ?? null)===$argv[1])); if(count($m)!==1 || !is_int($m[0]["id"] ?? null)) exit(65); echo $m[0]["id"];' "$2" <<<"$nodes")
    gateway_id=$(php -r '$v=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR); $m=array_values(array_filter($v["nodes"], fn($x) => ($x["name"] ?? null)===$argv[1])); if(count($m)!==1 || !is_int($m[0]["id"] ?? null)) exit(65); echo $m[0]["id"];' "$3" <<<"$nodes")
    if ! output=$("$orbit" node:access:add "$operator_id" "$gateway_id" --json 2>&1); then
      printf 'node:access:add failed: %s\n' "$output" >&2
      exit 1
    fi
    ;;
  configure-cli)
    [[ "$(id -u)" -eq 0 ]] && exec sudo -u orbit -- env HOME=/home/orbit ORBIT_HOME=/home/orbit/.orbit DB_DATABASE=/home/orbit/.orbit/gateway.sqlite bash "$0" "$@"
    [[ $# -eq 2 && "$2" =~ ^[a-zA-Z0-9][a-zA-Z0-9.-]{0,62}$ ]]
    ca=/home/orbit/.orbit/e2e-gateway-root-ca.pem
    install -d -m 0700 "$(dirname "$ca")"
    curl --fail --silent --show-error --insecure "https://$2/api/v1/ca/root" -o "$ca.new"
    php -r '$v=json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR); file_put_contents($argv[2], $v["data"]["root_ca"]);' "$ca.new" "$ca"
    rm -f "$ca.new"
    "$orbit" gateway:add "https://$2" --name=e2e --ca="$ca" --use --json
    ;;
  instance-api-readiness)
    [[ "$(id -u)" -eq 0 ]] && exec sudo -u orbit -- env HOME=/home/orbit ORBIT_HOME=/home/orbit/.orbit DB_DATABASE=/home/orbit/.orbit/gateway.sqlite bash "$0" "$@"
    [[ $# -eq 1 ]] || exit 64
    if instances=$(instance_list 2>&1); then
      :
    else
      probe_exit=$?
      printf 'instance-api-readiness: instance:list --json failed with exit code %d: %s\n' "$probe_exit" "$instances" >&2
      exit "$probe_exit"
    fi
    if ! instance_shape=$(php -r '$v=json_decode(stream_get_contents(STDIN), false, 512, JSON_THROW_ON_ERROR); if(!is_object($v)) exit(65); $properties=get_object_vars($v); if(count($properties)!==2 || !array_key_exists("request_id", $properties) || !is_string($v->request_id) || preg_match("/\\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\\z/Di", $v->request_id)!==1) exit(65); if(property_exists($v, "app_instances") || !property_exists($v, "instances")) exit(65); $shape="instances"; if(!is_array($v->{$shape})) exit(65); foreach($v->{$shape} as $instance) if(!is_object($instance)) exit(65); echo $shape;' <<<"$instances"); then
      printf 'instance-api-readiness: instance:list --json returned a malformed or unsupported response envelope\n' >&2
      exit 65
    fi
    printf 'instance-api-readiness: instance:list --json validated %s envelope\n' "$instance_shape"
    ;;
  create-resources)
    [[ "$(id -u)" -eq 0 ]] && exec sudo -u orbit -- env HOME=/home/orbit ORBIT_HOME=/home/orbit/.orbit DB_DATABASE=/home/orbit/.orbit/gateway.sqlite bash "$0" "$@"
    [[ ( $# -eq 4 || ( $# -eq 5 && "$5" == native ) ) && "$2" =~ ^[a-zA-Z0-9][a-zA-Z0-9.-]{0,62}$ && "$3" =~ ^[a-zA-Z0-9][a-zA-Z0-9.-]{0,62}$ && "$4" =~ ^[0-9a-f]{40}$ ]]
    nodes=$("$orbit" node:list --json)
    dev_id=$(php -r '$v=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR); $m=array_values(array_filter($v["nodes"], fn($x) => ($x["name"] ?? null)===$argv[1])); if(count($m)!==1 || !is_int($m[0]["id"] ?? null)) exit(65); echo $m[0]["id"];' "$2" <<<"$nodes")
    prod_id=$(php -r '$v=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR); $m=array_values(array_filter($v["nodes"], fn($x) => ($x["name"] ?? null)===$argv[1])); if(count($m)!==1 || !is_int($m[0]["id"] ?? null)) exit(65); echo $m[0]["id"];' "$3" <<<"$nodes")
    initial_instances=$(instance_list)
    instance_shape=$(php -r '$v=json_decode(stream_get_contents(STDIN), false, 512, JSON_THROW_ON_ERROR); if(!is_object($v)) exit(65); if(property_exists($v, "app_instances") || !property_exists($v, "instances")) exit(65); $key="instances"; $items=$v->{$key}; if(!is_array($items)) exit(65); $targets=[]; foreach($items as $item) { if(!is_object($item)) exit(65); $name=$item->name ?? null; if(in_array($name, ["e2e-dev", "e2e-prod"], true)) { if(isset($targets[$name])) exit(65); $targets[$name]=true; } } echo $key;' <<<"$initial_instances")
    command_surface=$("$orbit" list --raw)
    has_command() { awk -v command="$1" '$1 == command {found=1} END {exit !found}' <<<"$command_surface"; }
    if awk '$1 == "workspace:new" {found=1} END {exit !found}' <<<"$command_surface"; then instance_shape=workspaces; fi
    if [[ "${5-}" == native && "$instance_shape" != instances ]]; then
      printf 'create-resources: declared replacement requires native AppInstance support\n' >&2
      exit 65
    fi
    if [[ "$instance_shape" == instances ]]; then
      # Select one complete mutation contract after the read-only shape
      # preflight and before changing cluster, App, or sample state.
      candidate_contract=0
      environment_contract=0
      if has_command instance:clone && has_command instance:deploy && has_command instance:deploy-step:create; then
        candidate_contract=1
      fi
      if has_command env:import && has_command env:update && has_command env:sync; then
        environment_contract=1
      fi
      typed_cluster_name=e2e-development
      typed_dev_name=$2
      typed_prod_name=$3
      typed_node_cluster_id() {
        php -r '$v=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR); if(!is_array($v) || !array_key_exists("nodes", $v) || !is_array($v["nodes"]) || !array_is_list($v["nodes"])) exit(65); $m=[]; foreach($v["nodes"] as $x) { if(!is_array($x)) exit(65); if(($x["name"] ?? null)===$argv[1]) $m[]=$x; } if(count($m)!==1 || ($m[0]["id"] ?? null)!==(int)$argv[2] || ($m[0]["status"] ?? null)!=="active" || !array_key_exists("cluster_id", $m[0])) exit(65); $clusterId=$m[0]["cluster_id"]; if($clusterId!==null && (!is_int($clusterId) || $clusterId<1)) exit(65); echo $clusterId ?? "none";' "$typed_dev_name" "$dev_id"
      }
      typed_cluster_envelope() {
        php -r '$v=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR); if(!is_array($v) || array_is_list($v)) exit(65); echo json_encode(["clusters"=>[$v]], JSON_THROW_ON_ERROR);'
      }
      typed_cluster_state() {
        php -r '$v=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR); if(!is_array($v) || !array_key_exists("clusters", $v) || !is_array($v["clusters"]) || !array_is_list($v["clusters"])) exit(65); $matches=[]; foreach($v["clusters"] as $cluster) { if(!is_array($cluster) || !is_string($cluster["name"] ?? null)) exit(65); if($cluster["name"]===$argv[1]) $matches[]=$cluster; } if(count($matches)>1) exit(65); if($matches===[]) { if($argv[4]!=="none") exit(65); echo "0 create\n"; exit; } $cluster=$matches[0]; $id=$cluster["id"] ?? null; $state=$cluster["state"] ?? null; $nodes=$cluster["nodes"] ?? null; if(!is_int($id) || $id<1 || !array_key_exists("tld", $cluster) || $cluster["tld"]!==null || !in_array($state, ["inactive", "active"], true) || !is_array($nodes) || !array_is_list($nodes) || !array_key_exists("router", $cluster) || count($nodes)>3) exit(65); $inventory=json_decode($argv[5], true, 512, JSON_THROW_ON_ERROR); $allowed=[]; foreach($inventory["nodes"] as $item) { if(in_array($item["name"] ?? null, [$argv[3], "gateway", $argv[6]], true)) { if(isset($allowed[$item["id"]])) exit(65); $allowed[$item["id"]]=$item; } } $seen=[]; $hasNode=false; foreach($nodes as $member) { $mid=$member["id"] ?? null; if(!is_int($mid) || !isset($allowed[$mid]) || isset($seen[$mid]) || ($member["name"] ?? null)!==$allowed[$mid]["name"] || ($member["status"] ?? null)!=="active" || (count($nodes)>1 && ($allowed[$mid]["cluster_id"] ?? null)!==$id)) exit(65); $seen[$mid]=true; if($mid===(int)$argv[2]) $hasNode=true; } if($nodes!==[] && !$hasNode) exit(65); $router=$cluster["router"]; $hasRouter=$router!==null; if($hasRouter && (!is_array($router) || !isset($seen[$router["id"] ?? 0]) || !in_array($router["name"] ?? null, [$argv[3], "gateway"], true) || ($allowed[$router["id"]]["name"] ?? null)!==($router["name"] ?? null) || ($router["status"] ?? null)!=="active")) exit(65); $nodeClusterId=$argv[4]==="none" ? null : (int)$argv[4]; if(($hasNode && $nodeClusterId!==$id) || (!$hasNode && $nodeClusterId!==null)) exit(65); if($state==="inactive" && !$hasNode && !$hasRouter) $phase="attach"; elseif($state==="inactive" && $hasNode && !$hasRouter) $phase="router"; elseif($state==="inactive" && $hasNode && $hasRouter) $phase="activate"; elseif($state==="active" && $hasNode && $hasRouter) $phase="verified"; else exit(65); echo $id, " ", $phase, "\n";' "$typed_cluster_name" "$dev_id" "$typed_dev_name" "$1" "$nodes" "$typed_prod_name"
      }
      typed_app_instance_state() {
        php -r '$v=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR); if(!is_array($v) || array_key_exists("app_instances", $v) || !array_key_exists("instances", $v) || !is_array($v["instances"]) || !array_is_list($v["instances"])) exit(65); $m=array_values(array_filter($v["instances"], fn($x) => is_array($x) && ($x["name"] ?? null)===$argv[1])); if(count($m)!==1) exit(65); $x=$m[0]; $path=$x["checkout_path"] ?? null; $branch=$x["selected_branch"] ?? null; $commit=$x["starting_commit"] ?? null; if(($x["app_id"] ?? null)!==(int)$argv[2] || ($x["node_id"] ?? null)!==(int)$argv[3] || ($x["status"] ?? null)!=="active" || !is_string($path) || !str_starts_with($path, "/") || str_contains($path, "//") || preg_match("#(?:\\A|/)\\.\\.?(/|\\z)#D", $path)===1 || ($argv[4]!=="" && $path!==$argv[4]) || !is_string($branch) || $branch==="" || !is_string($commit) || preg_match("/\\A[0-9a-f]{40}\\z/D", $commit)!==1 || ($x["effective_root"] ?? null)!=="public") exit(65); echo json_encode(["shape"=>"instances", "app_id"=>(int)$argv[2], "node_id"=>(int)$argv[3], "name"=>$argv[1], "checkout_path"=>$path, "effective_root"=>"public"], JSON_THROW_ON_ERROR);' e2e-dev "$app_id" "$dev_id" "$previous_checkout"
      }
      typed_app_instance_id() {
        php -r '$v=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR); if(!is_array($v) || !is_array($v["instances"] ?? null) || !array_is_list($v["instances"])) exit(65); $m=array_values(array_filter($v["instances"], fn($x) => is_array($x) && ($x["name"] ?? null)===$argv[1])); if(count($m)!==1 || !is_int($m[0]["id"] ?? null) || $m[0]["id"]<1) exit(65); echo $m[0]["id"];' e2e-dev
      }
      typed_production_state() {
        php -r '$v=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR); if(!is_array($v) || !is_array($v["instances"] ?? null) || !array_is_list($v["instances"])) exit(65); $m=array_values(array_filter($v["instances"], fn($x) => is_array($x) && ($x["name"] ?? null)===$argv[1])); if(count($m)!==1) exit(65); $x=$m[0]; $id=$x["id"] ?? null; $home=$x["production_home"] ?? null; $user=$x["production_user"] ?? null; $checkout=$x["checkout_path"] ?? null; $root=$x["effective_root"] ?? null; $ok=static fn(mixed $v): bool => is_string($v) && preg_match("/\\A[a-z0-9](?:[a-z0-9.-]{0,251}[a-z0-9])?\\z/D", $v)===1; $domain=null; if(array_key_exists("domain", $x)) { if(!$ok($x["domain"])) exit(65); $domain=$x["domain"]; } else { $nested=is_array($x["route"] ?? null) && !array_is_list($x["route"]) ? $x["route"] : []; if(!array_key_exists("domain", $nested) || !$ok($nested["domain"])) exit(65); $domain=$nested["domain"]; } $releaseCheckout=is_string($home) && is_string($checkout) && str_starts_with($checkout, $home."/releases/"); $layout=($releaseCheckout || ($x["source_layout"] ?? null)==="release" || ($x["source_layout"] ?? null)==="releases" || is_string($checkout) && str_ends_with($checkout, "/current")) ? "release" : "flat"; $path=static fn(mixed $p): bool => is_string($p) && str_starts_with($p, "/") && !str_contains($p, "//") && preg_match("#(?:\\A|/)\\.\\.?(/|\\z)#D", $p)!==1; if(!is_int($id) || $id<1 || ($x["app_id"] ?? null)!==(int)$argv[2] || ($x["node_id"] ?? null)!==(int)$argv[3] || ($x["environment"] ?? null)!=="production" || ($x["status"] ?? null)!=="active" || !is_string($user) || preg_match("/\\A[a-z_][a-z0-9_-]{0,31}\\z/D", $user)!==1 || !$path($home) || !$path($checkout) || !$path($root) || !is_string($domain) || $domain==="") exit(65); $version=is_string($x["php_version"] ?? null) ? $x["php_version"] : "8.5"; if(preg_match("/\\A[0-9]+\\.[0-9]+\\z/D", $version)!==1) exit(65); $release=$layout==="release" ? ($x["current_target"] ?? ($releaseCheckout ? $checkout : null)) : null; if($layout==="release" && (!$path($release) || !str_starts_with($release, $home."/releases/"))) exit(65); $service=$layout==="release" ? "orbit-".$user."-php".$version."-fpm.service" : "php".$version."-fpm.service"; $socket=$layout==="release" ? "/run/php/".$user.".sock" : "/run/php/orbit-prod-instance-".$id.".sock"; echo json_encode(["layout"=>$layout,"instance_id"=>$id,"user"=>$user,"home"=>$home,"checkout_path"=>($layout==="release" ? $home."/current" : $checkout),"effective_root"=>$root,"environment_path"=>$home."/.env","database_path"=>null,"service"=>$service,"socket"=>$socket,"current_target"=>$release,"domain"=>$domain], JSON_THROW_ON_ERROR);' e2e-prod "$app_id" "$prod_id"
      }
      typed_route_id() {
        php -r '$v=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR); $list=$argv[4]==="list"; if($list) { if(!is_array($v) || array_is_list($v) || !is_array($v["routes"] ?? null) || !array_is_list($v["routes"]) || !is_string($v["request_id"] ?? null) || $v["request_id"]==="") exit(65); $routes=$v["routes"]; } else { if(!is_array($v) || array_is_list($v) || !is_string($v["request_id"] ?? null) || $v["request_id"]==="") exit(65); $routes=[$v]; } $instanceId=(int)$argv[2]; $matches=[]; $targeted=[]; foreach($routes as $route) { if(!is_array($route) || array_is_list($route)) exit(65); foreach(["id","app_id","node_id","cluster_id","generation_basis_node_id","domain","provenance","publication","status","failed_step","error_code","replaces_route_id","replaced_by_route_id","replacement_step","target"] as $key) if(!array_key_exists($key, $route)) exit(65); $ok=static fn(mixed $v): bool => is_string($v) && preg_match("/\\A[a-z0-9](?:[a-z0-9.-]{0,251}[a-z0-9])?\\z/D", $v)===1; if(!$ok($route["domain"])) exit(65); $target=$route["target"]; if(!is_int($route["id"]) || $route["id"]<1 || !is_int($route["app_id"]) || $route["app_id"]<1 || (!is_int($route["node_id"]) && $route["node_id"]!==null) || (!is_int($route["cluster_id"]) && $route["cluster_id"]!==null) || (($route["node_id"]===null)===($route["cluster_id"]===null)) || (!is_int($route["generation_basis_node_id"]) && $route["generation_basis_node_id"]!==null) || !in_array($route["provenance"], ["generated","explicit"], true) || !in_array($route["publication"], ["private","public"], true) || !is_string($route["status"]) || (!is_string($route["failed_step"]) && $route["failed_step"]!==null) || (!is_string($route["error_code"]) && $route["error_code"]!==null) || (!is_int($route["replaces_route_id"]) && $route["replaces_route_id"]!==null) || (!is_int($route["replaced_by_route_id"]) && $route["replaced_by_route_id"]!==null) || (!is_string($route["replacement_step"]) && $route["replacement_step"]!==null)) exit(65); if($target!==null && (!is_array($target) || array_is_list($target) || !is_int($target["id"] ?? null) || $target["id"]<1 || !is_int($target["app_instance_id"] ?? null) || $target["app_instance_id"]<1 || !is_int($target["position"] ?? null) || $target["position"]<0)) exit(65); $targetsInstance=is_array($target) && $target["app_instance_id"]===$instanceId; if($targetsInstance) $targeted[]=$route; if($route["domain"]==="e2e-dev.orbit") $matches[]=$route; } if($list && $matches===[]) { if($targeted!==[]) exit(65); echo "missing"; exit; } if(count($matches)!==1) exit(65); $route=$matches[0]; foreach($targeted as $other) { if($other["id"]===$route["id"]) continue; $linked=$other["replaces_route_id"]===$route["id"] || $other["replaced_by_route_id"]===$route["id"] || $route["replaces_route_id"]===$other["id"] || $route["replaced_by_route_id"]===$other["id"]; if(!$linked) exit(65); } $target=$route["target"]; if($route["app_id"]!==(int)$argv[1] || $route["node_id"]!==null || $route["cluster_id"]!==(int)$argv[3] || $route["generation_basis_node_id"]!==null || $route["domain"]!=="e2e-dev.orbit" || $route["provenance"]!=="explicit" || $route["publication"]!=="private" || !is_array($target) || $target["app_instance_id"]!==$instanceId || $target["position"]!==0) exit(65); echo $route["id"];' "$app_id" "$1" "$cluster_id" "$2"
      }
      node_cluster_id=$(typed_node_cluster_id <<<"$nodes")
      clusters=$("$orbit" cluster:list --json)
      cluster_state=$(typed_cluster_state "$node_cluster_id" <<<"$clusters")
      read -r cluster_id cluster_phase <<<"$cluster_state"
      cluster_mutated=0
      if [[ "$cluster_phase" == create ]]; then
        cluster_response=$("$orbit" cluster:create "$typed_cluster_name" --json)
        cluster_response=$(typed_cluster_envelope <<<"$cluster_response")
        cluster_state=$(typed_cluster_state none <<<"$cluster_response")
        read -r cluster_id cluster_phase <<<"$cluster_state"
        [[ "$cluster_phase" == attach ]]
        cluster_mutated=1
        cluster_phase=attach
      fi
      if [[ "$cluster_phase" == attach ]]; then
        cluster_response=$("$orbit" cluster:node:add "$cluster_id" "$dev_id" --json)
        cluster_response=$(typed_cluster_envelope <<<"$cluster_response")
        mutation_state=$(typed_cluster_state "$cluster_id" <<<"$cluster_response")
        read -r mutation_cluster_id mutation_phase <<<"$mutation_state"
        [[ "$mutation_cluster_id" == "$cluster_id" && "$mutation_phase" == router ]]
        cluster_mutated=1
        cluster_phase=router
      fi
      if [[ "$cluster_phase" == router ]]; then
        cluster_response=$("$orbit" cluster:router:set "$cluster_id" "$dev_id" --json)
        cluster_response=$(typed_cluster_envelope <<<"$cluster_response")
        mutation_state=$(typed_cluster_state "$cluster_id" <<<"$cluster_response")
        read -r mutation_cluster_id mutation_phase <<<"$mutation_state"
        [[ "$mutation_cluster_id" == "$cluster_id" && "$mutation_phase" == activate ]]
        cluster_mutated=1
        cluster_phase=activate
      fi
      if [[ "$cluster_phase" == activate ]]; then
        cluster_response=$("$orbit" cluster:update "$cluster_id" --state=active --json)
        cluster_response=$(typed_cluster_envelope <<<"$cluster_response")
        mutation_state=$(typed_cluster_state "$cluster_id" <<<"$cluster_response")
        read -r mutation_cluster_id mutation_phase <<<"$mutation_state"
        [[ "$mutation_cluster_id" == "$cluster_id" && "$mutation_phase" == verified ]]
        cluster_mutated=1
      fi
      if [[ "$cluster_mutated" -eq 1 ]]; then
        typed_nodes=$("$orbit" node:list --json)
        node_cluster_id=$(typed_node_cluster_id <<<"$typed_nodes")
        clusters=$("$orbit" cluster:list --json)
        cluster_state=$(typed_cluster_state "$node_cluster_id" <<<"$clusters")
        read -r verified_cluster_id cluster_phase <<<"$cluster_state"
        [[ "$cluster_phase" == verified && "$verified_cluster_id" == "$cluster_id" ]]
      fi
      apps=$("$orbit" project:list --json)
      app_id=$(php -r '$v=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR); $items=$v["projects"] ?? $v["apps"] ?? null; if(!is_array($items) || !array_is_list($items)) exit(65); $m=array_values(array_filter($items, fn($x) => ($x["slug"] ?? null)===$argv[1])); if(count($m)>1) exit(65); if($m) { $x=$m[0]; $branch=array_key_exists("default_branch", $x) ? $x["default_branch"] : ($x["main_branch"] ?? null); if(($x["repository_url"] ?? null)!==$argv[2] || ($x["name"] ?? null)!==$argv[3] || ($x["root"] ?? null)!==$argv[4] || !is_string($branch) || $branch==="" || !is_int($x["id"] ?? null)) exit(65); } echo $m[0]["id"] ?? "";' laravel-typed https://github.com/laravel/laravel.git Laravel public <<<"$apps")
      typed_target_count=$(php -r '$v=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR); echo count(array_filter($v["instances"], fn($x) => ($x["name"] ?? null)===$argv[1]));' e2e-dev <<<"$initial_instances")
      [[ -n "$app_id" || "$typed_target_count" -eq 0 ]] || exit 65
      typed_instances=$initial_instances
      typed_count=$(php -r '$v=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR); echo count($v["instances"]);' <<<"$typed_instances")
      previous_checkout=
      if [[ -f "$sample_state" ]]; then
        previous_checkout=$(php -r '$v=json_decode($argv[1], true, 16, JSON_THROW_ON_ERROR); if(($v["shape"] ?? null)==="instances" && is_string($v["checkout_path"] ?? null)) echo $v["checkout_path"];' "$(sample_state_json)")
      fi
      if [[ "$typed_count" -gt 0 ]]; then
        typed_state=$(typed_app_instance_state <<<"$typed_instances")
      fi
      if [[ -z "$app_id" ]]; then
        app_id=$("$orbit" project:create laravel-typed laravel-app https://github.com/laravel/laravel.git --name=Laravel --root=public --json | php -r '$v=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR); if(!is_int($v["id"] ?? null)) exit(65); echo $v["id"];')
      fi
      if [[ "$typed_count" -eq 0 ]]; then
        "$orbit" instance:create "$app_id" "$dev_id" e2e-dev --domain=e2e-dev.orbit --json >/dev/null
        typed_instances=$(instance_list)
        typed_state=$(typed_app_instance_state <<<"$typed_instances")
      fi
      typed_instance_id=$(typed_app_instance_id <<<"$typed_instances")
      typed_routes=$("$orbit" route:list --json)
      typed_route=$(typed_route_id "$typed_instance_id" list <<<"$typed_routes")
      if [[ "$typed_route" == missing ]]; then
        route_response=$("$orbit" route:create "$app_id" e2e-dev.orbit --publication=private --target="$typed_instance_id" --json)
        created_route=$(typed_route_id "$typed_instance_id" response <<<"$route_response")
        typed_routes=$("$orbit" route:list --json)
        typed_route=$(typed_route_id "$typed_instance_id" list <<<"$typed_routes")
        [[ "$created_route" == "$typed_route" ]]
      fi
      production_state=
      if [[ "$candidate_contract" -eq 1 ]]; then
        previous_production=
        if [[ -f "$sample_state" ]]; then
          previous_production=$(php -r '$v=json_decode($argv[1], true, 16, JSON_THROW_ON_ERROR); if(is_array($v["production"] ?? null)) echo json_encode($v["production"], JSON_THROW_ON_ERROR);' "$(sample_state_json)")
        fi
        production_count=$(php -r '$v=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR); echo count(array_filter($v["instances"], fn($x) => ($x["name"] ?? null)==="e2e-prod"));' <<<"$typed_instances")
        if [[ "$production_count" -eq 0 && -z "$previous_production" ]]; then
          development_checkout=$(php -r '$v=json_decode($argv[1], true, 16, JSON_THROW_ON_ERROR); echo $v["checkout_path"];' "$typed_state")
          if [[ "$environment_contract" -eq 1 && -f "$development_checkout/.env" ]]; then
            for import_attempt in 1 2; do
              if import_response=$("$orbit" env:import --instance="$typed_instance_id" --json); then
                break
              fi
              import_code=$(php -r '$v=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR); echo $v["error"]["code"] ?? "unknown";' <<<"$import_response")
              case "$import_code" in
                env.import_conflict) break ;;
                instance.source_profile_missing)
                  [[ "$import_attempt" -eq 1 ]] || exit 65
                  recovery_args=(instance:create "$app_id" "$dev_id" e2e-dev --domain=e2e-dev.orbit --recover-source-profile)
                  recovery_branch=$(php -r '$v=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR); foreach($v["instances"] as $x) if(($x["id"] ?? null)===(int)$argv[1]) { $b=$x["branch_override"] ?? null; if($b!==null && !is_string($b)) exit(65); echo $b ?? ""; }' "$typed_instance_id" <<<"$typed_instances")
                  [[ -z "$recovery_branch" ]] || recovery_args+=(--branch="$recovery_branch")
                  "$orbit" "${recovery_args[@]}" --json >/dev/null
                  ;;
                *) echo 'sample environment import failed before production clone' >&2; exit 65 ;;
              esac
            done
          fi
          # These operations are deliberately unguarded. A selected supported
          # operation failure must never enter the older direct-create path.
          production_projects=$("$orbit" project:list --json)
          production_branch=$(php -r '$v=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR); $m=array_values(array_filter($v["projects"] ?? $v["apps"] ?? [], fn($x) => ($x["id"] ?? null)===(int)$argv[1])); if(count($m)!==1) exit(65); $branch=$m[0]["default_branch"] ?? $m[0]["main_branch"] ?? null; if(!is_string($branch) || $branch==="") exit(65); echo $branch;' "$app_id" <<<"$production_projects")
          "$orbit" instance:clone "$typed_instance_id" "$prod_id" e2e-prod --preview-name=e2e-prod --branch="$production_branch" --json >/dev/null
          typed_instances=$(instance_list)
          prod_instance_id=$(php -r '$v=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR); $m=array_values(array_filter($v["instances"], fn($x) => is_array($x) && ($x["name"] ?? null)==="e2e-prod")); if(count($m)!==1 || !is_int($m[0]["id"] ?? null)) exit(65); echo $m[0]["id"];' <<<"$typed_instances")
          if [[ "$environment_contract" -eq 1 ]]; then
            "$orbit" env:sync --instance="$prod_instance_id" --json >/dev/null
          fi
          "$orbit" instance:deploy-step:create "$prod_instance_id" composer-install --command='composer install --no-dev --no-interaction --no-progress' --timeout=900 --json >/dev/null
          "$orbit" instance:deploy-step:create "$prod_instance_id" migrate --command='php artisan migrate --force --no-interaction' --json >/dev/null
          "$orbit" instance:deploy "$prod_instance_id" --json >/dev/null
          typed_instances=$(instance_list)
          production_state=$(typed_production_state <<<"$typed_instances")
        else
          production_state=$(typed_production_state <<<"$typed_instances")
          if [[ -n "$previous_production" ]]; then
            php -r '$old=json_decode($argv[1], true, 16, JSON_THROW_ON_ERROR); $new=json_decode($argv[2], true, 16, JSON_THROW_ON_ERROR); foreach(["instance_id","user","home"] as $key) if(($old[$key] ?? null)!==$new[$key]) exit(65);' "$previous_production" "$production_state"
          fi
        fi
        typed_state=$(php -r '$state=json_decode($argv[1], true, 16, JSON_THROW_ON_ERROR); $production=json_decode($argv[2], true, 16, JSON_THROW_ON_ERROR); $state["production"]=$production; echo json_encode($state, JSON_THROW_ON_ERROR);' "$typed_state" "$production_state")
      fi
      state_tmp=$(mktemp "$sample_state.XXXXXX")
      printf '%s\n' "$typed_state" >"$state_tmp"
      mv -f "$state_tmp" "$sample_state"
      printf '%s\n' "$typed_state"
      exit 0
    fi
    apps=$("$orbit" project:list --json)
    app_id=$(php -r '$v=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR); $items=$v["projects"] ?? $v["apps"] ?? null; if(!is_array($items) || !array_is_list($items)) exit(65); $m=array_values(array_filter($items, fn($x) => ($x["slug"] ?? null)===$argv[1])); if(count($m)>1 || $m && (($m[0]["repository_url"] ?? null)!==$argv[2] || ($m[0]["name"] ?? null)!==$argv[3])) exit(65); echo $m[0]["id"] ?? "";' laravel https://github.com/laravel/laravel.git Laravel <<<"$apps")
    if [[ -z "$app_id" ]]; then
      app_id=$("$orbit" project:create laravel laravel-app https://github.com/laravel/laravel.git --name=Laravel --json | php -r '$v=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR); echo $v["id"];')
    fi
    instances=$(instance_list)
    dev_instance_id=$(php -r '$v=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR); $m=array_values(array_filter($v["instances"], fn($x) => ($x["name"] ?? null)===$argv[1])); if(count($m)>1 || $m && (($m[0]["app_id"] ?? null)!==(int)$argv[2] || ($m[0]["node_id"] ?? null)!==(int)$argv[3] || ($m[0]["environment"] ?? null)!==$argv[4])) exit(65); echo $m[0]["id"] ?? "";' e2e-dev "$app_id" "$dev_id" development <<<"$instances")
    if [[ -z "$dev_instance_id" ]]; then
      dev_instance_id=$("$orbit" instance:create "$app_id" "$dev_id" e2e-dev --environment=development --json | php -r '$v=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR); echo $v["id"];')
    fi
    prod_instance_id=$(php -r '$v=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR); $m=array_values(array_filter($v["instances"], fn($x) => ($x["name"] ?? null)===$argv[1])); if(count($m)>1 || $m && (($m[0]["app_id"] ?? null)!==(int)$argv[2] || ($m[0]["node_id"] ?? null)!==(int)$argv[3] || ($m[0]["environment"] ?? null)!==$argv[4] || ($m[0]["hostname"] ?? null)!==$argv[5])) exit(65); echo $m[0]["id"] ?? "";' e2e-prod "$app_id" "$prod_id" production laravel.internal <<<"$instances")
    if [[ -z "$prod_instance_id" ]]; then
      "$orbit" instance:create "$app_id" "$prod_id" e2e-prod --environment=production --hostname=laravel.internal --json >/dev/null
    fi
    workspaces=$("$orbit" workspace:list --json)
    workspace_id=$(php -r '$v=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR); $m=array_values(array_filter($v["workspaces"], fn($x) => ($x["name"] ?? null)===$argv[1])); if(count($m)>1 || $m && (($m[0]["instance_id"] ?? null)!==(int)$argv[2] || ($m[0]["branch"] ?? null)!==$argv[3])) exit(65); echo $m[0]["id"] ?? "";' e2e "$dev_instance_id" e2e <<<"$workspaces")
    if [[ -z "$workspace_id" ]]; then
      "$orbit" workspace:new "$dev_instance_id" e2e --branch=e2e --json >/dev/null
    fi
    ;;
  migrate-state)
    [[ "$(id -u)" -eq 0 ]] && exec sudo -u orbit -- env HOME=/home/orbit ORBIT_HOME=/home/orbit/.orbit DB_DATABASE=/home/orbit/.orbit/gateway.sqlite bash "$0" "$@"
    [[ $# -eq 1 && -f "$sample_state" && ! -L "$sample_state" ]] || exit 65
    state=$(bash "$0" inspect-state native)
    state_tmp=$(mktemp "$sample_state.XXXXXX")
    printf '%s\n' "$state" >"$state_tmp"
    mv -f "$state_tmp" "$sample_state"
    printf '%s\n' "$state"
    ;;
  inspect-state)
    [[ "$(id -u)" -eq 0 ]] && exec sudo -u orbit -- env HOME=/home/orbit ORBIT_HOME=/home/orbit/.orbit DB_DATABASE=/home/orbit/.orbit/gateway.sqlite bash "$0" "$@"
    [[ $# -eq 1 || ( $# -eq 2 && "$2" == native ) ]] || exit 64
    if [[ ! -e "$sample_state" ]]; then
      [[ "${2-}" != native ]] || exit 65
      printf '{"shape":"workspaces"}\n'
      exit 0
    fi
    [[ -f "$sample_state" ]] || exit 65
    sample_shape=$(php -r '$v=json_decode($argv[1], true, 16, JSON_THROW_ON_ERROR); $shape=$v["shape"] ?? null; if(!in_array($shape, ["workspaces", "instances"], true)) exit(65); echo $shape;' "$(sample_state_json)")
    if [[ "$sample_shape" == workspaces ]]; then
      [[ "${2-}" != native ]] || exit 65
      printf '{"shape":"workspaces"}\n'
      exit 0
    fi
    php -r '$s=json_decode($argv[1], true, 16, JSON_THROW_ON_ERROR); $path=$s["checkout_path"] ?? null; $base=["shape","app_id","node_id","name","checkout_path","effective_root"]; if(!in_array(array_keys($s), [$base,[...$base,"production"]], true) || array_key_exists("production", $s) && !is_array($s["production"]) || $s["shape"]!=="instances" || !is_int($s["app_id"]) || !is_int($s["node_id"]) || $s["name"]!=="e2e-dev" || !is_string($path) || !str_starts_with($path, "/") || str_contains($path, "//") || preg_match("#(?:\\A|/)\\.\\.?(/|\\z)#D", $path)===1 || $s["effective_root"]!=="public") exit(65); $v=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR); if(!is_array($v) || array_key_exists("app_instances", $v) || !array_key_exists("instances", $v) || !is_array($v["instances"]) || !array_is_list($v["instances"])) exit(65); $m=array_values(array_filter($v["instances"], fn($x) => is_array($x) && ($x["name"] ?? null)==="e2e-dev")); if(count($m)!==1) exit(65); $x=$m[0]; if(($x["app_id"] ?? null)!==$s["app_id"] || ($x["node_id"] ?? null)!==$s["node_id"] || ($x["status"] ?? null)!=="active" || ($x["checkout_path"] ?? null)!==$path || !is_string($x["selected_branch"] ?? null) || $x["selected_branch"]==="" || !is_string($x["starting_commit"] ?? null) || preg_match("/\\A[0-9a-f]{40}\\z/D", $x["starting_commit"])!==1 || ($x["effective_root"] ?? null)!=="public") exit(65); echo json_encode($s, JSON_THROW_ON_ERROR), "\n";' "$(sample_state_json)" < <(instance_list)
    ;;
  shared-cluster|verify-cluster)
    [[ "$(id -u)" -eq 0 ]] && exec sudo -u orbit -- env HOME=/home/orbit ORBIT_HOME=/home/orbit/.orbit DB_DATABASE=/home/orbit/.orbit/gateway.sqlite bash "$0" "$@"
    [[ $# -eq 4 ]] || exit 64
    php /dev/stdin "$orbit" "$1" "$2" "$3" "$4" <<'PHP'
<?php
// Use product commands for every mutation. Never edit the Gateway database.
[$script, $orbit, $mode, $gateway, $development, $production] = $argv;
$names = [$gateway, $development, $production];
foreach ($names as $name) {
    if (!preg_match('/\A[a-z][a-z0-9-]{0,22}\z/D', $name)) {
        exit(64);
    }
}
if (count(array_unique($names)) !== 3) {
    exit(64);
}
function command(array $arguments, int $attempt = 1): array
{
    global $orbit;
    $process = proc_open([$orbit, ...$arguments, '--json'], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => STDERR], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot run Orbit.');
    }
    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    if (proc_close($process) !== 0) {
        // Upgrading Caddy on Gateway can interrupt its own API response while
        // the Router operation finishes. This exact assignment is idempotent.
        $failure = json_decode($output, true);
        $code = is_array($failure) ? ($failure['error']['code'] ?? null) : null;
        if ($arguments[0] === 'cluster:router:set' && $attempt < 5
            && in_array($code, ['gateway.unreachable', 'gateway.request_failed', 'cluster.router_busy'], true)) {
            sleep(1);
            return command($arguments, $attempt + 1);
        }
        // CLI JSON may contain secrets. Report only a validated error code.
        $detail = is_string($code) && preg_match('/\A[a-z][a-z0-9._-]{0,127}\z/D', $code) ? ' ['.$code.']' : '';
        throw new RuntimeException('Orbit '.$arguments[0].' failed.'.$detail);
    }
    $value = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($value) || array_is_list($value)) {
        throw new RuntimeException('Invalid Orbit response.');
    }
    return $value;
}
function inspectCluster(bool $complete): array
{
    global $names, $gateway, $development, $production;
    $inventory = command(['node:list'])['nodes'] ?? null;
    $clusters = command(['cluster:list'])['clusters'] ?? null;
    if (!is_array($inventory) || !array_is_list($inventory) || !is_array($clusters) || !array_is_list($clusters)) {
        throw new RuntimeException('Invalid topology inventory.');
    }
    $nodes = [];
    foreach ($inventory as $node) {
        if (!is_array($node) || !is_string($node['name'] ?? null)) {
            throw new RuntimeException('Invalid Node record.');
        }
        if (!in_array($node['name'], $names, true)) {
            continue;
        }
        if (isset($nodes[$node['name']]) || !is_int($node['id'] ?? null) || $node['id'] < 1
            || ($node['status'] ?? null) !== 'active' || !array_key_exists('cluster_id', $node)
            || !is_array($node['roles'] ?? null) || !array_is_list($node['roles'])) {
            throw new RuntimeException('Invalid or inactive topology Node.');
        }
        $nodes[$node['name']] = $node;
    }
    if (count($nodes) !== 3 || count(array_unique(array_column($nodes, 'id'))) !== 3) {
        throw new RuntimeException('The shared Cluster requires three distinct Nodes.');
    }
    $matches = array_values(array_filter($clusters, fn ($cluster) => is_array($cluster) && ($cluster['name'] ?? null) === 'e2e-development'));
    if (count($matches) !== 1) {
        throw new RuntimeException('The sample Cluster is missing or ambiguous.');
    }
    $cluster = $matches[0];
    if (!is_int($cluster['id'] ?? null) || $cluster['id'] < 1 || ($cluster['state'] ?? null) !== 'active'
        || !array_key_exists('tld', $cluster) || $cluster['tld'] !== null || !is_array($cluster['nodes'] ?? null)
        || !array_is_list($cluster['nodes']) || !is_array($cluster['router'] ?? null)) {
        throw new RuntimeException('The sample Cluster must be active, TLD-less, and have a Router.');
    }
    $members = [];
    foreach ($cluster['nodes'] as $member) {
        $name = is_array($member) ? ($member['name'] ?? null) : null;
        if (!is_string($name) || !isset($nodes[$name]) || isset($members[$name])
            || ($member['id'] ?? null) !== $nodes[$name]['id'] || ($member['status'] ?? null) !== 'active') {
            throw new RuntimeException('Unexpected Cluster member.');
        }
        $members[$name] = true;
    }
    foreach ($nodes as $name => $node) {
        if ($node['cluster_id'] !== (isset($members[$name]) ? $cluster['id'] : null)) {
            throw new RuntimeException('Conflicting Cluster membership.');
        }
        if ($name !== $production && in_array('ingress', $node['roles'], true)) {
            throw new RuntimeException('Unexpected Ingress owner.');
        }
        if ($name !== $gateway && in_array('websocket', $node['roles'], true)) {
            throw new RuntimeException('Unexpected WebSocket owner.');
        }
    }
    $router = $cluster['router'];
    $routerName = $router['name'] ?? null;
    if (!in_array($routerName, [$development, $gateway], true) || !isset($members[$routerName])
        || ($router['id'] ?? null) !== $nodes[$routerName]['id'] || ($router['status'] ?? null) !== 'active'
        || !isset($members[$development])) {
        throw new RuntimeException('Unexpected Cluster Router.');
    }
    if ($complete) {
        if (count($members) !== 3 || $routerName !== $gateway) {
            throw new RuntimeException('The shared Cluster is incomplete.');
        }
        foreach ([$gateway => ['gateway', 'vpn', 'router', 'websocket'], $development => ['app-dev', 'metrics', 'database'], $production => ['app-prod', 'ingress']] as $name => $roles) {
            if (array_diff($roles, $nodes[$name]['roles']) !== []) {
                throw new RuntimeException('The shared Cluster is missing required roles.');
            }
        }
    }
    return [$cluster, $nodes];
}
try {
    [$cluster, $nodes] = inspectCluster($mode === 'verify-cluster');
    if ($mode === 'shared-cluster') {
        foreach ([$gateway, $production] as $name) {
            if ($nodes[$name]['cluster_id'] === null) {
                command(['cluster:node:add', (string) $cluster['id'], (string) $nodes[$name]['id']]);
            }
        }
        command(['cluster:router:set', (string) $cluster['id'], (string) $nodes[$gateway]['id']]);
        foreach ([$gateway => 'websocket', $development => 'database', $production => 'ingress'] as $name => $role) {
            command(['node:role:add', (string) $nodes[$name]['id'], $role, '--converge']);
        }
        inspectCluster(true);
    }
    echo "shared-cluster: verified\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'shared-cluster: '.$exception->getMessage()."\n");
    exit(65);
}
PHP
    ;;
  metrics)
    [[ "$(id -u)" -eq 0 ]] && exec sudo -u orbit -- env HOME=/home/orbit ORBIT_HOME=/home/orbit/.orbit DB_DATABASE=/home/orbit/.orbit/gateway.sqlite bash "$0" "$@"
    [[ $# -eq 2 && "$2" =~ ^[a-z][a-z0-9-]{0,22}$ ]] || exit 64
    status=$("$orbit" metrics:status --json)
    read -r action node_id < <(php -r '$v=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR); $a=$v["assignment"] ?? null; if ($a === null) { echo "enable -\n"; exit; } if (($a["node_name"] ?? null) !== $argv[1] || !is_int($a["node_id"] ?? null)) exit(65); $status=$a["status"] ?? null; if ($status === "active") { echo "noop ", $a["node_id"], "\n"; exit; } if ($status === "failed") { echo "recover ", $a["node_id"], "\n"; exit; } exit(65);' "$2" <<<"$status")
    case "$action" in
      enable) mutation=$("$orbit" metrics:enable "$2" --json) ;;
      recover) mutation=$("$orbit" node:role:add "$node_id" metrics --converge --json) ;;
      noop) exit 0 ;;
      *) exit 65 ;;
    esac
    php -r '$v=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR); $assignment=$v["assignment"] ?? null; $status=is_array($assignment) ? ($assignment["status"] ?? null) : ($v["status"] ?? null); $node=is_array($assignment) ? ($assignment["node_name"] ?? null) : ($v["node_name"] ?? null); if (!in_array($status, ["active", "enabled"], true) || ($node !== null && $node !== $argv[1])) exit(1);' "$2" <<<"$mutation"
    ;;
  metrics-publication)
    [[ "$(id -u)" -eq 0 ]] && exec sudo -u orbit -- env HOME=/home/orbit ORBIT_HOME=/home/orbit/.orbit DB_DATABASE=/home/orbit/.orbit/gateway.sqlite bash "$0" "$@"
    [[ $# -eq 2 && "$2" =~ ^[a-z][a-z0-9-]{0,22}$ ]] || exit 64
    status=$("$orbit" metrics:status --json)
    read -r action node_id assignment_id < <(php -r '$v=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR); $a=$v["assignment"] ?? null; if ($a === null) { echo "noop - -\n"; exit; } if (!is_array($a) || ($a["node_name"] ?? null) !== $argv[1] || !is_int($a["node_id"] ?? null) || !is_int($a["id"] ?? null) || ($a["status"] ?? null) !== "active") exit(65); echo "converge ", $a["node_id"], " ", $a["id"], "\n";' "$2" <<<"$status")
    [[ "$action" == noop ]] && exit 0
    [[ "$action" == converge && "$node_id" =~ ^[1-9][0-9]*$ && "$assignment_id" =~ ^[1-9][0-9]*$ ]] || exit 65
    mutation=$("$orbit" node:role:add "$node_id" metrics --converge --json)
    php -r '$v=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR); if (($v["node_id"] ?? null) !== (int)$argv[1] || ($v["node_name"] ?? null) !== $argv[3] || ($v["role"] ?? null) !== "metrics" || ($v["assignment"]["id"] ?? null) !== (int)$argv[2] || ($v["assignment"]["role"] ?? null) !== "metrics" || ($v["assignment"]["status"] ?? null) !== "active") exit(1);' "$node_id" "$assignment_id" "$2" <<<"$mutation"
    ;;
  internal-tls)
    # The sample production site serves Orbit's own certificates, and every
    # Orbit Caddy publisher writes the one global options block Caddy allows
    # (`auto_https disable_certs`). An older snapshot carries a second global
    # block, `local_certs`, as an unmanaged fragment of the managed version;
    # the publisher copies it forward, so every changed publish on app-prod
    # fails validation. This step removes that fragment and restores the
    # product symlink. Runs before re-projection so the publisher validates a
    # managed layout.
    [[ $# -eq 1 ]] || exit 64
    [[ "$(id -u)" -eq 0 ]] || exit 77
    live=/etc/caddy/Caddyfile
    legacy_wrapper=/etc/caddy/Caddyfile.orbit-e2e
    target=$(readlink -f "$live")
    if [[ "$target" == "$legacy_wrapper" ]]; then
      # A promoted snapshot may still carry the retired e2e wrapper; resolve
      # the managed version it imported and restore the product symlink.
      target=$(sed -n 's#^import \(/etc/caddy/orbit-versions/[0-9a-f]\{16\}/Caddyfile\)$#\1#p' "$target" | tail -n 1)
    fi
    case "$target" in
      /etc/caddy/orbit-versions/*/Caddyfile) ;;
      *) printf 'internal-tls: unexpected Caddyfile target: %s\n' "$target" >&2; exit 65 ;;
    esac
    [[ -f "$target" ]]
    fragment=$(dirname "$target")/fragments/00-orbit-e2e-global.caddy
    changed=0
    if [[ -e "$fragment" ]]; then
      rm -f -- "$fragment"
      changed=1
    fi
    if [[ "$(readlink -f "$live")" != "$target" ]]; then
      ln -sfn "$target" "$live"
      changed=1
    fi
    rm -f -- "$legacy_wrapper" /var/lib/orbit-e2e/caddy-rendered-path /var/lib/orbit-e2e/caddy-config-sha256
    caddy validate --config "$live" --adapter caddyfile
    if [[ "$changed" -eq 1 ]]; then
      systemctl reload caddy
    fi
    ;;
  reproject)
    # Re-project every managed role and instance through the product so the
    # rendered PHP-FPM pools, Caddy fragments, firewall rules, and DNS records
    # match the Gateway code in the checkout. Roles first, then instances with
    # development last: the app-dev runtime converger publishes the Gateway
    # DNS records for every active site, so it must run after every other
    # instance is active again.
    [[ "$(id -u)" -eq 0 ]] && exec sudo -u orbit -- env HOME=/home/orbit ORBIT_HOME=/home/orbit/.orbit DB_DATABASE=/home/orbit/.orbit/gateway.sqlite bash "$0" "$@"
    [[ $# -eq 1 ]] || exit 64
    "$orbit" node:list --json | php -r '$v=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR); foreach ($v["nodes"] as $n) { foreach ($n["roles"] ?? [] as $r) { if (in_array($r, ["app-dev", "app-prod"], true)) { printf("%d %s\n", $n["id"], $r); } } }' | while read -r node_id role; do
      "$orbit" node:role:add "$node_id" "$role" --converge --json | php -r '$v=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR); if (($v["assignment"]["status"] ?? null) !== "active") { fwrite(STDERR, "role is not active after re-projection\n"); exit(1); } printf("reprojected role %s on node %d\n", $v["role"], $v["node_id"]);' || exit 1
    done
    instances=$(instance_list)
    instance_shape=$(php -r '$v=json_decode(stream_get_contents(STDIN), false, 512, JSON_THROW_ON_ERROR); if(!is_object($v)) exit(65); if(property_exists($v, "app_instances") || !property_exists($v, "instances")) exit(65); $key="instances"; if(!is_array($v->{$key})) exit(65); echo $key;' <<<"$instances")
    command_surface=$("$orbit" list --raw)
    if awk '$1 == "workspace:new" {found=1} END {exit !found}' <<<"$command_surface"; then instance_shape=workspaces; fi
    if [[ "$instance_shape" == instances ]]; then
      [[ -f "$sample_state" ]]
      read -r app_id node_id checkout_path < <(php -r '$v=json_decode($argv[1], true, 16, JSON_THROW_ON_ERROR); $path=$v["checkout_path"] ?? null; $base=["shape","app_id","node_id","name","checkout_path","effective_root"]; if(!in_array(array_keys($v), [$base,[...$base,"production"]], true) || array_key_exists("production", $v) && !is_array($v["production"]) || $v["shape"]!=="instances" || !is_int($v["app_id"]) || !is_int($v["node_id"]) || $v["name"]!=="e2e-dev" || !is_string($path) || !str_starts_with($path, "/") || str_contains($path, "//") || preg_match("#(?:\\A|/)\\.\\.?(/|\\z)#D", $path)===1 || $v["effective_root"]!=="public") exit(65); echo $v["app_id"], " ", $v["node_id"], " ", $path, "\n";' "$(sample_state_json)")
      php -r '$v=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR); $m=array_values(array_filter($v["instances"], fn($x) => is_array($x) && ($x["name"] ?? null)==="e2e-dev")); if(count($m)!==1) exit(65); $x=$m[0]; if(($x["app_id"] ?? null)!==(int)$argv[1] || ($x["node_id"] ?? null)!==(int)$argv[2] || ($x["status"] ?? null)!=="active" || ($x["checkout_path"] ?? null)!==$argv[3] || !is_string($x["selected_branch"] ?? null) || $x["selected_branch"]==="" || !is_string($x["starting_commit"] ?? null) || preg_match("/\\A[0-9a-f]{40}\\z/D", $x["starting_commit"])!==1 || ($x["effective_root"] ?? null)!=="public") exit(65);' "$app_id" "$node_id" "$checkout_path" <<<"$instances"
      exit 0
    fi
    php -r '$v=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR); $i=$v["instances"]; usort($i, fn($a, $b) => [$a["environment"] === "development", $a["id"]] <=> [$b["environment"] === "development", $b["id"]]); foreach ($i as $x) { printf("%d %s\n", $x["id"], $x["php_version"]); }' <<<"$instances" | while read -r id version; do
      "$orbit" instance:php "$id" "$version" --json | php -r '$v=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR); if (($v["status"] ?? null) !== "active") { fwrite(STDERR, "instance is not active after re-projection\n"); exit(1); } printf("reprojected instance %d (%s) on node %d\n", $v["id"], $v["name"], $v["node_id"]);' || exit 1
    done
    ;;
  hydrate)
    [[ "$2" =~ ^[0-9a-f]{40}$ ]]
    [[ $# -eq 3 || ( $# -eq 4 && ( "$3" == app-dev || "$3" == app-prod ) ) ]]
    [[ $# -eq 4 && "$3" == app-dev && "$(id -u)" -eq 0 ]] && exec sudo -u orbit -- env HOME=/home/orbit ORBIT_HOME=/home/orbit/.orbit DB_DATABASE=/home/orbit/.orbit/gateway.sqlite bash "$0" "$@"
    if [[ $# -eq 4 && "$3" == app-dev ]]; then
      [[ -f "$sample_state" ]]
      hydration_preflight_attempts=30
      hydration_preflight_delay_seconds=1
      hydration_instance_preflight() {
        local attempt instances probe_exit validation_failure
        for ((attempt = 1; attempt <= hydration_preflight_attempts; attempt++)); do
          validation_failure=
          if instances=$(instance_list 2>&1); then
            probe_exit=0
            if ! php -r '$v=json_decode(stream_get_contents(STDIN), false, 512, JSON_THROW_ON_ERROR); if(!is_object($v)) exit(65); if(property_exists($v, "app_instances") || !property_exists($v, "instances")) exit(65); $shape="instances"; if(!is_array($v->{$shape}) || !array_is_list($v->{$shape})) exit(65); foreach($v->{$shape} as $instance) if(!is_object($instance)) exit(65);' <<<"$instances" >/dev/null 2>&1; then
              validation_failure='malformed or unsupported response envelope'
              probe_exit=65
            fi
          else
            probe_exit=$?
          fi
          if [[ "$probe_exit" -eq 0 ]]; then
            printf '%s' "$instances"
            return 0
          fi
          if [[ "$attempt" -lt "$hydration_preflight_attempts" ]]; then
            sleep "$hydration_preflight_delay_seconds"
          fi
        done
        if [[ -n "$validation_failure" ]]; then
          printf 'hydrate: instance:list --json failed after %d attempts; final attempt %d validation failure: %s\n' "$hydration_preflight_attempts" "$hydration_preflight_attempts" "$validation_failure" >&2
        else
          printf 'hydrate: instance:list --json failed after %d attempts; final attempt %d exited with code %d: %s\n' "$hydration_preflight_attempts" "$hydration_preflight_attempts" "$probe_exit" "$instances" >&2
        fi
        return "$probe_exit"
      }
      if typed_instances=$(hydration_instance_preflight); then
        :
      else
        exit $?
      fi
      if [[ "$3" == app-dev ]]; then
        php -r '$v=json_decode($argv[1], true, 16, JSON_THROW_ON_ERROR); $path=$v["checkout_path"] ?? null; $base=["shape","app_id","node_id","name","checkout_path","effective_root"]; if(!in_array(array_keys($v), [$base,[...$base,"production"]], true) || $v["shape"]!=="instances" || !is_int($v["app_id"]) || !is_int($v["node_id"]) || $v["name"]!=="e2e-dev" || !is_string($path) || !str_starts_with($path, "/") || str_contains($path, "//") || preg_match("#(?:\\A|/)\\.\\.?(/|\\z)#D", $path)===1 || $path!==$argv[2] || $v["effective_root"]!=="public") exit(65); $r=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR); if(!is_array($r) || array_key_exists("app_instances", $r) || !array_key_exists("instances", $r) || !is_array($r["instances"]) || !array_is_list($r["instances"])) exit(65); $m=array_values(array_filter($r["instances"], fn($x) => is_array($x) && ($x["name"] ?? null)==="e2e-dev")); if(count($m)!==1) exit(65); $x=$m[0]; if(($x["app_id"] ?? null)!==$v["app_id"] || ($x["node_id"] ?? null)!==$v["node_id"] || ($x["status"] ?? null)!=="active" || ($x["checkout_path"] ?? null)!==$path || !is_string($x["selected_branch"] ?? null) || $x["selected_branch"]==="" || !is_string($x["starting_commit"] ?? null) || preg_match("/\\A[0-9a-f]{40}\\z/D", $x["starting_commit"])!==1 || ($x["effective_root"] ?? null)!=="public") exit(65);' "$(sample_state_json)" "$4" <<<"$typed_instances"
      fi
      # Once the preflight boundary passes, remap the reserved retry status from
      # any later hydration command so callers never retry work that may have
      # already mutated the checkout.
      trap 'status=$?; trap - EXIT; if [[ "$status" -eq 75 ]]; then exit 1; fi; exit "$status"' EXIT
    fi
    case "$3" in
      app-dev)
        runtime_user=orbit
        runtime_home=/home/orbit
        if [[ $# -eq 4 ]]; then checkouts=("$4"); else checkouts=(/home/orbit/apps/laravel /home/orbit/.orbit/worktrees/laravel/e2e); fi
        ;;
      app-prod)
        if [[ $# -eq 4 ]]; then
          mapfile -t placement < <(php -r '$v=json_decode(base64_decode($argv[1], true), true, 16, JSON_THROW_ON_ERROR); if(!is_array($v) || !is_string($v["user"] ?? null) || !is_string($v["home"] ?? null) || !is_string($v["checkout_path"] ?? null) || !in_array($v["layout"] ?? null, ["flat","release"], true) || ($v["current_target"] ?? null)!==null && !is_string($v["current_target"])) exit(65); $ok=static fn(mixed $e): bool => is_string($e) && preg_match("/\\A[a-z0-9](?:[a-z0-9.-]{0,251}[a-z0-9])?\\z/D", $e)===1; if(!array_key_exists("domain", $v) || !$ok($v["domain"])) exit(65); echo $v["layout"], "\n", $v["user"], "\n", $v["home"], "\n", $v["checkout_path"], "\n", ($v["current_target"] ?? ""), "\n", $v["domain"], "\n";' "$4")
          [[ "${#placement[@]}" -eq 6 ]]
          production_layout=${placement[0]}
          runtime_user=${placement[1]}
          runtime_home=${placement[2]}
          checkouts=("${placement[3]}")
          production_current=${placement[4]}
          production_domain=${placement[5]}
        else
          production_layout=flat
          runtime_user=orbit-laravel
          runtime_home=/var/www/laravel
          checkouts=(/var/www/laravel/e2e-prod)
        fi
        ;;
      *) exit 64 ;;
    esac
    run_as_runtime() {
      if [[ "$(id -u)" -eq 0 ]]; then
        sudo -u "$runtime_user" -- env -u DB_DATABASE HOME="$runtime_home" "$@"
      else
        env -u DB_DATABASE "$@"
      fi
    }
    hydrate_composer_dependencies() {
      local checkout=$1
      local lock_hash marker marker_tmp
      marker="$checkout/vendor/.orbit-e2e-composer-lock"
      if [[ -f "$checkout/composer.lock" ]]; then
        lock_hash=$(sha256sum "$checkout/composer.lock" | awk '{print $1}')
        if [[ -s "$checkout/vendor/autoload.php" && -f "$marker" && "$(<"$marker")" == "$lock_hash" ]]; then
          return
        fi
      fi
      run_as_runtime composer install --working-dir="$checkout" --no-interaction --no-progress
      [[ -s "$checkout/vendor/autoload.php" && -f "$checkout/composer.lock" ]]
      lock_hash=$(sha256sum "$checkout/composer.lock" | awk '{print $1}')
      marker_tmp=$(run_as_runtime mktemp "$checkout/vendor/.orbit-e2e-composer-lock.XXXXXX")
      printf '%s' "$lock_hash" | run_as_runtime tee "$marker_tmp" >/dev/null
      run_as_runtime mv -f "$marker_tmp" "$marker"
    }
    for checkout in "${checkouts[@]}"; do
      [[ -d "$checkout/.git" || -f "$checkout/.git" ]] || exit 66
      [[ "$(run_as_runtime git -C "$checkout" remote get-url origin)" == https://github.com/laravel/laravel.git ]]
      if [[ "${production_layout:-flat}" == release ]]; then
        [[ -L "$checkout" && "$(readlink -f -- "$checkout")" == "$production_current" ]]
        [[ "$(run_as_runtime git -C "$checkout" rev-parse HEAD)" =~ ^[0-9a-f]{40}$ ]]
      elif [[ $# -eq 4 && "$3" == app-dev ]]; then
        starting_commit=$(php -r '$v=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR); foreach($v["instances"] as $x) if($x["name"]==="e2e-dev") echo $x["starting_commit"];' <<<"$typed_instances")
        if ! run_as_runtime git -C "$checkout" cat-file -e "$starting_commit^{commit}"; then
          run_as_runtime git -C "$checkout" fetch --quiet origin "$starting_commit"
        fi
        if ! run_as_runtime git -C "$checkout" merge-base --is-ancestor "$starting_commit" HEAD; then
          run_as_runtime git -C "$checkout" merge-base --is-ancestor HEAD "$starting_commit" || { echo 'hydrate: development source diverges from its registered starting commit' >&2; exit 65; }
          run_as_runtime git -C "$checkout" merge --ff-only "$starting_commit"
        fi
      else
        if ! run_as_runtime git -C "$checkout" cat-file -e "$2^{commit}"; then
          run_as_runtime git -C "$checkout" fetch --quiet origin "$2"
        fi
        run_as_runtime git -C "$checkout" reset --hard --quiet "$2"
        [[ "$(run_as_runtime git -C "$checkout" rev-parse HEAD)" == "$2" ]]
      fi
      [[ -f "$checkout/.env" ]] || run_as_runtime cp "$checkout/.env.example" "$checkout/.env"
      hydrate_composer_dependencies "$checkout"
      run_as_runtime grep -q '^APP_KEY=base64:' "$checkout/.env" || run_as_runtime php "$checkout/artisan" key:generate --force --no-interaction
      run_as_runtime install -d -m 0775 "$checkout/storage" "$checkout/bootstrap/cache"
      run_as_runtime chmod -R ug+rwX "$checkout/storage" "$checkout/bootstrap/cache"
      if run_as_runtime grep -q '^DB_CONNECTION=sqlite$' "$checkout/.env"; then
        run_as_runtime install -d -m 0775 "$checkout/database"
        [[ -f "$checkout/database/database.sqlite" ]] || run_as_runtime touch "$checkout/database/database.sqlite"
      fi
      run_as_runtime php "$checkout/artisan" migrate --force --no-interaction
    done
    if [[ "$3" == app-prod ]]; then
      # The product-managed Caddyfile serves the site with the internal CA that
      # `internal-tls` placed inside the managed version.
      ca=$(cat /var/lib/orbit-e2e/caddy-ca-path)
      [[ -s "$ca" ]]
      if [[ $# -eq 4 ]]; then
        ca=/usr/local/share/ca-certificates/orbit-managed-root-ca.crt
        curl --fail --silent --show-error --retry 10 --retry-delay 2 --retry-connrefused --retry-all-errors --connect-timeout 10 --max-time 30 --cacert "$ca" --resolve "$production_domain:443:10.44.0.1" "https://$production_domain/" >/dev/null
      else
        curl --fail --silent --show-error --retry 10 --retry-delay 2 --retry-connrefused --retry-all-errors --connect-timeout 10 --max-time 30 --cacert "$ca" --resolve laravel.internal:443:127.0.0.1 https://laravel.internal/ >/dev/null
      fi
    fi
    ;;
  *) exit 64 ;;
esac

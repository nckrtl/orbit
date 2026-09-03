#!/usr/bin/env bash
set -euo pipefail
umask 077
# incus exec starts in /root, which the orbit account cannot enter; child
# processes spawned by the CLI need a readable working directory.
cd /
orbit=/home/orbit/orbit/apps/cli/orbit
sample_state=/home/orbit/.orbit/e2e-sample-app-state.json
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
    if instances=$("$orbit" instance:list --json 2>&1); then
      :
    else
      probe_exit=$?
      printf 'instance-api-readiness: instance:list --json failed with exit code %d: %s\n' "$probe_exit" "$instances" >&2
      exit "$probe_exit"
    fi
    if ! instance_shape=$(php -r '$v=json_decode(stream_get_contents(STDIN), false, 512, JSON_THROW_ON_ERROR); if(!is_object($v)) exit(65); $properties=get_object_vars($v); if(count($properties)!==2 || !array_key_exists("request_id", $properties) || !is_string($v->request_id) || preg_match("/\\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\\z/Di", $v->request_id)!==1) exit(65); $legacy=property_exists($v, "instances"); $typed=property_exists($v, "app_instances"); if($legacy===$typed) exit(65); $shape=$legacy ? "instances" : "app_instances"; if(!is_array($v->{$shape})) exit(65); foreach($v->{$shape} as $instance) if(!is_object($instance)) exit(65); echo $shape;' <<<"$instances"); then
      printf 'instance-api-readiness: instance:list --json returned a malformed or unsupported response envelope\n' >&2
      exit 65
    fi
    printf 'instance-api-readiness: instance:list --json validated %s envelope\n' "$instance_shape"
    ;;
  create-resources)
    [[ "$(id -u)" -eq 0 ]] && exec sudo -u orbit -- env HOME=/home/orbit ORBIT_HOME=/home/orbit/.orbit DB_DATABASE=/home/orbit/.orbit/gateway.sqlite bash "$0" "$@"
    [[ $# -eq 4 && "$2" =~ ^[a-zA-Z0-9][a-zA-Z0-9.-]{0,62}$ && "$3" =~ ^[a-zA-Z0-9][a-zA-Z0-9.-]{0,62}$ && "$4" =~ ^[0-9a-f]{40}$ ]]
    nodes=$("$orbit" node:list --json)
    dev_id=$(php -r '$v=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR); $m=array_values(array_filter($v["nodes"], fn($x) => ($x["name"] ?? null)===$argv[1])); if(count($m)!==1 || !is_int($m[0]["id"] ?? null)) exit(65); echo $m[0]["id"];' "$2" <<<"$nodes")
    prod_id=$(php -r '$v=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR); $m=array_values(array_filter($v["nodes"], fn($x) => ($x["name"] ?? null)===$argv[1])); if(count($m)!==1 || !is_int($m[0]["id"] ?? null)) exit(65); echo $m[0]["id"];' "$3" <<<"$nodes")
    initial_instances=$("$orbit" instance:list --json)
    instance_shape=$(php -r '$v=json_decode(stream_get_contents(STDIN), false, 512, JSON_THROW_ON_ERROR); if(!is_object($v)) exit(65); $legacy=property_exists($v, "instances"); $typed=property_exists($v, "app_instances"); if($legacy===$typed) exit(65); $key=$legacy ? "instances" : "app_instances"; $items=$v->{$key}; if(!is_array($items)) exit(65); $targets=[]; foreach($items as $item) { if(!is_object($item)) exit(65); $name=$item->name ?? null; if(in_array($name, ["e2e-dev", "e2e-prod"], true)) { if(isset($targets[$name])) exit(65); $targets[$name]=true; } } echo $key;' <<<"$initial_instances")
    if [[ "$instance_shape" == app_instances ]]; then
      typed_cluster_name=e2e-development
      typed_dev_name=$2
      typed_node_cluster_id() {
        php -r '$v=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR); if(!is_array($v) || !array_key_exists("nodes", $v) || !is_array($v["nodes"]) || !array_is_list($v["nodes"])) exit(65); $m=[]; foreach($v["nodes"] as $x) { if(!is_array($x)) exit(65); if(($x["name"] ?? null)===$argv[1]) $m[]=$x; } if(count($m)!==1 || ($m[0]["id"] ?? null)!==(int)$argv[2] || ($m[0]["status"] ?? null)!=="active" || !array_key_exists("cluster_id", $m[0])) exit(65); $clusterId=$m[0]["cluster_id"]; if($clusterId!==null && (!is_int($clusterId) || $clusterId<1)) exit(65); echo $clusterId ?? "none";' "$typed_dev_name" "$dev_id"
      }
      typed_cluster_envelope() {
        php -r '$v=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR); if(!is_array($v) || array_is_list($v)) exit(65); echo json_encode(["clusters"=>[$v]], JSON_THROW_ON_ERROR);'
      }
      typed_cluster_state() {
        php -r '$v=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR); if(!is_array($v) || !array_key_exists("clusters", $v) || !is_array($v["clusters"]) || !array_is_list($v["clusters"])) exit(65); $matches=[]; foreach($v["clusters"] as $cluster) { if(!is_array($cluster) || !is_string($cluster["name"] ?? null)) exit(65); if($cluster["name"]===$argv[1]) $matches[]=$cluster; } if(count($matches)>1) exit(65); if($matches===[]) { if($argv[4]!=="none") exit(65); echo "0 create\n"; exit; } $cluster=$matches[0]; $id=$cluster["id"] ?? null; $state=$cluster["state"] ?? null; $nodes=$cluster["nodes"] ?? null; if(!is_int($id) || $id<1 || !array_key_exists("tld", $cluster) || $cluster["tld"]!==null || !in_array($state, ["inactive", "active"], true) || !is_array($nodes) || !array_is_list($nodes) || !array_key_exists("router", $cluster) || count($nodes)>1) exit(65); $hasNode=count($nodes)===1; if($hasNode && (!is_array($nodes[0]) || ($nodes[0]["id"] ?? null)!==(int)$argv[2] || ($nodes[0]["name"] ?? null)!==$argv[3] || ($nodes[0]["status"] ?? null)!=="active")) exit(65); $router=$cluster["router"]; $hasRouter=$router!==null; if($hasRouter && (!is_array($router) || ($router["id"] ?? null)!==(int)$argv[2] || ($router["name"] ?? null)!==$argv[3] || ($router["status"] ?? null)!=="active")) exit(65); $nodeClusterId=$argv[4]==="none" ? null : (int)$argv[4]; if(($hasNode && $nodeClusterId!==$id) || (!$hasNode && $nodeClusterId!==null)) exit(65); if($state==="inactive" && !$hasNode && !$hasRouter) $phase="attach"; elseif($state==="inactive" && $hasNode && !$hasRouter) $phase="router"; elseif($state==="inactive" && $hasNode && $hasRouter) $phase="activate"; elseif($state==="active" && $hasNode && $hasRouter) $phase="verified"; else exit(65); echo $id, " ", $phase, "\n";' "$typed_cluster_name" "$dev_id" "$typed_dev_name" "$1"
      }
      typed_app_instance_state() {
        php -r '$v=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR); if(!is_array($v) || array_key_exists("instances", $v) || !array_key_exists("app_instances", $v) || !is_array($v["app_instances"]) || !array_is_list($v["app_instances"])) exit(65); $m=array_values(array_filter($v["app_instances"], fn($x) => is_array($x) && ($x["name"] ?? null)===$argv[1])); if(count($m)!==1) exit(65); $x=$m[0]; $path=$x["checkout_path"] ?? null; $branch=$x["selected_branch"] ?? null; $commit=$x["starting_commit"] ?? null; if(($x["app_id"] ?? null)!==(int)$argv[2] || ($x["node_id"] ?? null)!==(int)$argv[3] || ($x["status"] ?? null)!=="active" || !is_string($path) || !str_starts_with($path, "/") || str_contains($path, "//") || preg_match("#(?:\\A|/)\\.\\.?(/|\\z)#D", $path)===1 || ($argv[4]!=="" && $path!==$argv[4]) || !is_string($branch) || $branch==="" || !is_string($commit) || preg_match("/\\A[0-9a-f]{40}\\z/D", $commit)!==1 || ($x["effective_root"] ?? null)!=="public") exit(65); echo json_encode(["shape"=>"app_instances", "app_id"=>(int)$argv[2], "node_id"=>(int)$argv[3], "name"=>$argv[1], "checkout_path"=>$path, "effective_root"=>"public"], JSON_THROW_ON_ERROR);' e2e-dev "$app_id" "$dev_id" "$previous_checkout"
      }
      node_cluster_id=$(typed_node_cluster_id <<<"$nodes")
      clusters=$("$orbit" cluster:list --json)
      cluster_state=$(typed_cluster_state "$node_cluster_id" <<<"$clusters")
      read -r cluster_id cluster_phase <<<"$cluster_state"
      cluster_mutated=0
      if [[ "$cluster_phase" == create ]]; then
        cluster_response=$("$orbit" cluster:new "$typed_cluster_name" --json)
        cluster_response=$(typed_cluster_envelope <<<"$cluster_response")
        cluster_state=$(typed_cluster_state none <<<"$cluster_response")
        read -r cluster_id cluster_phase <<<"$cluster_state"
        [[ "$cluster_phase" == attach ]]
        cluster_mutated=1
        cluster_phase=attach
      fi
      if [[ "$cluster_phase" == attach ]]; then
        cluster_response=$("$orbit" cluster:node:attach "$cluster_id" "$dev_id" --json)
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
      apps=$("$orbit" app:list --json)
      app_id=$(php -r '$v=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR); $m=array_values(array_filter($v["apps"], fn($x) => ($x["slug"] ?? null)===$argv[1])); if(count($m)>1 || $m && (($m[0]["repository_url"] ?? null)!==$argv[2] || ($m[0]["name"] ?? null)!==$argv[3] || ($m[0]["root"] ?? null)!==$argv[4] || !is_string($m[0]["main_branch"] ?? null) || $m[0]["main_branch"]==="") || $m && !is_int($m[0]["id"] ?? null)) exit(65); echo $m[0]["id"] ?? "";' laravel-typed https://github.com/laravel/laravel.git Laravel public <<<"$apps")
      typed_target_count=$(php -r '$v=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR); echo count(array_filter($v["app_instances"], fn($x) => ($x["name"] ?? null)===$argv[1]));' e2e-dev <<<"$initial_instances")
      [[ -n "$app_id" || "$typed_target_count" -eq 0 ]] || exit 65
      typed_instances=$initial_instances
      typed_count=$(php -r '$v=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR); echo count($v["app_instances"]);' <<<"$typed_instances")
      previous_checkout=
      if [[ -f "$sample_state" ]]; then
        previous_checkout=$(php -r '$v=json_decode(file_get_contents($argv[1]), true, 16, JSON_THROW_ON_ERROR); if(($v["shape"] ?? null)==="app_instances" && is_string($v["checkout_path"] ?? null)) echo $v["checkout_path"];' "$sample_state")
      fi
      if [[ "$typed_count" -gt 0 ]]; then
        typed_state=$(typed_app_instance_state <<<"$typed_instances")
      fi
      if [[ -z "$app_id" ]]; then
        app_id=$("$orbit" app:new laravel-typed https://github.com/laravel/laravel.git --name=Laravel --root=public --json | php -r '$v=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR); if(!is_int($v["id"] ?? null)) exit(65); echo $v["id"];')
      fi
      if [[ "$typed_count" -eq 0 ]]; then
        "$orbit" instance:new "$app_id" "$dev_id" e2e-dev --json >/dev/null
        typed_instances=$("$orbit" instance:list --json)
        typed_state=$(typed_app_instance_state <<<"$typed_instances")
      fi
      state_tmp=$(mktemp "$sample_state.XXXXXX")
      printf '%s\n' "$typed_state" >"$state_tmp"
      mv -f "$state_tmp" "$sample_state"
      printf '%s\n' "$typed_state"
      exit 0
    fi
    apps=$("$orbit" app:list --json)
    app_id=$(php -r '$v=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR); $m=array_values(array_filter($v["apps"], fn($x) => ($x["slug"] ?? null)===$argv[1])); if(count($m)>1 || $m && (($m[0]["repository_url"] ?? null)!==$argv[2] || ($m[0]["name"] ?? null)!==$argv[3])) exit(65); echo $m[0]["id"] ?? "";' laravel https://github.com/laravel/laravel.git Laravel <<<"$apps")
    if [[ -z "$app_id" ]]; then
      app_id=$("$orbit" app:new laravel https://github.com/laravel/laravel.git --name=Laravel --json | php -r '$v=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR); echo $v["id"];')
    fi
    instances=$("$orbit" instance:list --json)
    dev_instance_id=$(php -r '$v=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR); $m=array_values(array_filter($v["instances"], fn($x) => ($x["name"] ?? null)===$argv[1])); if(count($m)>1 || $m && (($m[0]["app_id"] ?? null)!==(int)$argv[2] || ($m[0]["node_id"] ?? null)!==(int)$argv[3] || ($m[0]["environment"] ?? null)!==$argv[4])) exit(65); echo $m[0]["id"] ?? "";' e2e-dev "$app_id" "$dev_id" development <<<"$instances")
    if [[ -z "$dev_instance_id" ]]; then
      dev_instance_id=$("$orbit" instance:new "$app_id" "$dev_id" e2e-dev --environment=development --json | php -r '$v=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR); echo $v["id"];')
    fi
    prod_instance_id=$(php -r '$v=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR); $m=array_values(array_filter($v["instances"], fn($x) => ($x["name"] ?? null)===$argv[1])); if(count($m)>1 || $m && (($m[0]["app_id"] ?? null)!==(int)$argv[2] || ($m[0]["node_id"] ?? null)!==(int)$argv[3] || ($m[0]["environment"] ?? null)!==$argv[4] || ($m[0]["hostname"] ?? null)!==$argv[5])) exit(65); echo $m[0]["id"] ?? "";' e2e-prod "$app_id" "$prod_id" production laravel.internal <<<"$instances")
    if [[ -z "$prod_instance_id" ]]; then
      "$orbit" instance:new "$app_id" "$prod_id" e2e-prod --environment=production --hostname=laravel.internal --json >/dev/null
    fi
    workspaces=$("$orbit" workspace:list --json)
    workspace_id=$(php -r '$v=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR); $m=array_values(array_filter($v["workspaces"], fn($x) => ($x["name"] ?? null)===$argv[1])); if(count($m)>1 || $m && (($m[0]["instance_id"] ?? null)!==(int)$argv[2] || ($m[0]["branch"] ?? null)!==$argv[3])) exit(65); echo $m[0]["id"] ?? "";' e2e "$dev_instance_id" e2e <<<"$workspaces")
    if [[ -z "$workspace_id" ]]; then
      "$orbit" workspace:new "$dev_instance_id" e2e --branch=e2e --json >/dev/null
    fi
    ;;
  inspect-state)
    [[ "$(id -u)" -eq 0 ]] && exec sudo -u orbit -- env HOME=/home/orbit ORBIT_HOME=/home/orbit/.orbit DB_DATABASE=/home/orbit/.orbit/gateway.sqlite bash "$0" "$@"
    [[ $# -eq 1 ]] || exit 64
    if [[ ! -e "$sample_state" ]]; then
      printf '{"shape":"instances"}\n'
      exit 0
    fi
    [[ -f "$sample_state" ]] || exit 65
    sample_shape=$(php -r '$v=json_decode(file_get_contents($argv[1]), true, 16, JSON_THROW_ON_ERROR); $shape=$v["shape"] ?? null; if(!in_array($shape, ["instances", "app_instances"], true)) exit(65); echo $shape;' "$sample_state")
    if [[ "$sample_shape" == instances ]]; then
      printf '{"shape":"instances"}\n'
      exit 0
    fi
    php -r '$s=json_decode(file_get_contents($argv[1]), true, 16, JSON_THROW_ON_ERROR); $path=$s["checkout_path"] ?? null; if(array_keys($s)!==["shape","app_id","node_id","name","checkout_path","effective_root"] || $s["shape"]!=="app_instances" || !is_int($s["app_id"]) || !is_int($s["node_id"]) || $s["name"]!=="e2e-dev" || !is_string($path) || !str_starts_with($path, "/") || str_contains($path, "//") || preg_match("#(?:\\A|/)\\.\\.?(/|\\z)#D", $path)===1 || $s["effective_root"]!=="public") exit(65); $v=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR); if(!is_array($v) || array_key_exists("instances", $v) || !array_key_exists("app_instances", $v) || !is_array($v["app_instances"]) || !array_is_list($v["app_instances"])) exit(65); $m=array_values(array_filter($v["app_instances"], fn($x) => is_array($x) && ($x["name"] ?? null)==="e2e-dev")); if(count($m)!==1) exit(65); $x=$m[0]; if(($x["app_id"] ?? null)!==$s["app_id"] || ($x["node_id"] ?? null)!==$s["node_id"] || ($x["status"] ?? null)!=="active" || ($x["checkout_path"] ?? null)!==$path || !is_string($x["selected_branch"] ?? null) || $x["selected_branch"]==="" || !is_string($x["starting_commit"] ?? null) || preg_match("/\\A[0-9a-f]{40}\\z/D", $x["starting_commit"])!==1 || ($x["effective_root"] ?? null)!=="public") exit(65); echo json_encode($s, JSON_THROW_ON_ERROR), "\n";' "$sample_state" < <("$orbit" instance:list --json)
    ;;
  metrics)
    [[ "$(id -u)" -eq 0 ]] && exec sudo -u orbit -- env HOME=/home/orbit ORBIT_HOME=/home/orbit/.orbit DB_DATABASE=/home/orbit/.orbit/gateway.sqlite bash "$0" "$@"
    [[ $# -eq 1 ]] || exit 64
    status=$("$orbit" metrics:status --json)
    read -r action node_id < <(php -r '$v=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR); $a=$v["assignment"] ?? null; if ($a === null) { echo "enable -\n"; exit; } if (($a["node_name"] ?? null) !== "app-dev" || !is_int($a["node_id"] ?? null)) exit(65); $status=$a["status"] ?? null; if ($status === "active") { echo "noop ", $a["node_id"], "\n"; exit; } if ($status === "failed") { echo "recover ", $a["node_id"], "\n"; exit; } exit(65);' <<<"$status")
    case "$action" in
      enable) mutation=$("$orbit" metrics:enable app-dev --json) ;;
      recover) mutation=$("$orbit" node:role:add "$node_id" metrics --converge --json) ;;
      noop) exit 0 ;;
      *) exit 65 ;;
    esac
    php -r '$v=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR); $status=$v["assignment"]["status"] ?? $v["status"] ?? null; if (!in_array($status, ["active", "enabled"], true)) exit(1);' <<<"$mutation"
    ;;
  metrics-publication)
    [[ "$(id -u)" -eq 0 ]] && exec sudo -u orbit -- env HOME=/home/orbit ORBIT_HOME=/home/orbit/.orbit DB_DATABASE=/home/orbit/.orbit/gateway.sqlite bash "$0" "$@"
    [[ $# -eq 1 ]] || exit 64
    status=$("$orbit" metrics:status --json)
    read -r action node_id assignment_id < <(php -r '$v=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR); $a=$v["assignment"] ?? null; if ($a === null) { echo "noop - -\n"; exit; } if (!is_array($a) || ($a["node_name"] ?? null) !== "app-dev" || !is_int($a["node_id"] ?? null) || !is_int($a["id"] ?? null) || ($a["status"] ?? null) !== "active") exit(65); echo "converge ", $a["node_id"], " ", $a["id"], "\n";' <<<"$status")
    [[ "$action" == noop ]] && exit 0
    [[ "$action" == converge && "$node_id" =~ ^[1-9][0-9]*$ && "$assignment_id" =~ ^[1-9][0-9]*$ ]] || exit 65
    mutation=$("$orbit" node:role:add "$node_id" metrics --converge --json)
    php -r '$v=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR); if (($v["node_id"] ?? null) !== (int)$argv[1] || ($v["node_name"] ?? null) !== "app-dev" || ($v["role"] ?? null) !== "metrics" || ($v["assignment"]["id"] ?? null) !== (int)$argv[2] || ($v["assignment"]["role"] ?? null) !== "metrics" || ($v["assignment"]["status"] ?? null) !== "active") exit(1);' "$node_id" "$assignment_id" <<<"$mutation"
    ;;
  internal-tls)
    # Internal TLS for the sample production site lives inside the product's
    # own Caddy layout: the `local_certs` global block becomes an unmanaged
    # fragment of the managed version behind /etc/caddy/Caddyfile, and the
    # product publisher carries unmanaged fragments forward on every publish.
    # Runs before re-projection so the publisher validates a managed layout.
    [[ $# -eq 1 ]] || exit 64
    [[ "$(id -u)" -eq 0 ]] || exit 77
    source=/etc/caddy/orbit-e2e-global.caddy
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
    [[ -f "$target" && -s "$source" ]]
    fragment=$(dirname "$target")/fragments/00-orbit-e2e-global.caddy
    changed=0
    if ! cmp -s -- "$source" "$fragment"; then
      install -m 0640 -- "$source" "$fragment"
      chown --reference="$target" -- "$fragment"
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
    instances=$("$orbit" instance:list --json)
    instance_shape=$(php -r '$v=json_decode(stream_get_contents(STDIN), false, 512, JSON_THROW_ON_ERROR); if(!is_object($v)) exit(65); $legacy=property_exists($v, "instances"); $typed=property_exists($v, "app_instances"); if($legacy===$typed) exit(65); $key=$legacy ? "instances" : "app_instances"; if(!is_array($v->{$key})) exit(65); echo $key;' <<<"$instances")
    if [[ "$instance_shape" == app_instances ]]; then
      [[ -f "$sample_state" ]]
      read -r app_id node_id checkout_path < <(php -r '$v=json_decode(file_get_contents($argv[1]), true, 16, JSON_THROW_ON_ERROR); $path=$v["checkout_path"] ?? null; if(array_keys($v)!==["shape","app_id","node_id","name","checkout_path","effective_root"] || $v["shape"]!=="app_instances" || !is_int($v["app_id"]) || !is_int($v["node_id"]) || $v["name"]!=="e2e-dev" || !is_string($path) || !str_starts_with($path, "/") || str_contains($path, "//") || preg_match("#(?:\\A|/)\\.\\.?(/|\\z)#D", $path)===1 || $v["effective_root"]!=="public") exit(65); echo $v["app_id"], " ", $v["node_id"], " ", $path, "\n";' "$sample_state")
      php -r '$v=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR); $m=array_values(array_filter($v["app_instances"], fn($x) => is_array($x) && ($x["name"] ?? null)==="e2e-dev")); if(count($m)!==1) exit(65); $x=$m[0]; if(($x["app_id"] ?? null)!==(int)$argv[1] || ($x["node_id"] ?? null)!==(int)$argv[2] || ($x["status"] ?? null)!=="active" || ($x["checkout_path"] ?? null)!==$argv[3] || !is_string($x["selected_branch"] ?? null) || $x["selected_branch"]==="" || !is_string($x["starting_commit"] ?? null) || preg_match("/\\A[0-9a-f]{40}\\z/D", $x["starting_commit"])!==1 || ($x["effective_root"] ?? null)!=="public") exit(65);' "$app_id" "$node_id" "$checkout_path" <<<"$instances"
      exit 0
    fi
    php -r '$v=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR); $i=$v["instances"]; usort($i, fn($a, $b) => [$a["environment"] === "development", $a["id"]] <=> [$b["environment"] === "development", $b["id"]]); foreach ($i as $x) { printf("%d %s\n", $x["id"], $x["php_version"]); }' <<<"$instances" | while read -r id version; do
      "$orbit" instance:php "$id" "$version" --json | php -r '$v=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR); if (($v["status"] ?? null) !== "active") { fwrite(STDERR, "instance is not active after re-projection\n"); exit(1); } printf("reprojected instance %d (%s) on node %d\n", $v["id"], $v["name"], $v["node_id"]);' || exit 1
    done
    ;;
  hydrate)
    [[ "$2" =~ ^[0-9a-f]{40}$ ]]
    [[ $# -eq 3 || ( $# -eq 4 && "$3" == app-dev ) ]]
    [[ $# -eq 4 && "$(id -u)" -eq 0 ]] && exec sudo -u orbit -- env HOME=/home/orbit ORBIT_HOME=/home/orbit/.orbit DB_DATABASE=/home/orbit/.orbit/gateway.sqlite bash "$0" "$@"
    if [[ $# -eq 4 ]]; then
      [[ -f "$sample_state" ]]
      hydration_preflight_attempts=30
      hydration_preflight_delay_seconds=1
      hydration_instance_preflight() {
        local attempt instances probe_exit validation_failure
        for ((attempt = 1; attempt <= hydration_preflight_attempts; attempt++)); do
          validation_failure=
          if instances=$("$orbit" instance:list --json 2>&1); then
            probe_exit=0
            if ! php -r '$v=json_decode(stream_get_contents(STDIN), false, 512, JSON_THROW_ON_ERROR); if(!is_object($v)) exit(65); $legacy=property_exists($v, "instances"); $typed=property_exists($v, "app_instances"); if($legacy===$typed) exit(65); $shape=$legacy ? "instances" : "app_instances"; if(!is_array($v->{$shape}) || !array_is_list($v->{$shape})) exit(65); foreach($v->{$shape} as $instance) if(!is_object($instance)) exit(65);' <<<"$instances" >/dev/null 2>&1; then
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
      php -r '$v=json_decode(file_get_contents($argv[1]), true, 16, JSON_THROW_ON_ERROR); $path=$v["checkout_path"] ?? null; if(array_keys($v)!==["shape","app_id","node_id","name","checkout_path","effective_root"] || $v["shape"]!=="app_instances" || !is_int($v["app_id"]) || !is_int($v["node_id"]) || $v["name"]!=="e2e-dev" || !is_string($path) || !str_starts_with($path, "/") || str_contains($path, "//") || preg_match("#(?:\\A|/)\\.\\.?(/|\\z)#D", $path)===1 || $path!==$argv[2] || $v["effective_root"]!=="public") exit(65); $r=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR); if(!is_array($r) || array_key_exists("instances", $r) || !array_key_exists("app_instances", $r) || !is_array($r["app_instances"]) || !array_is_list($r["app_instances"])) exit(65); $m=array_values(array_filter($r["app_instances"], fn($x) => is_array($x) && ($x["name"] ?? null)==="e2e-dev")); if(count($m)!==1) exit(65); $x=$m[0]; if(($x["app_id"] ?? null)!==$v["app_id"] || ($x["node_id"] ?? null)!==$v["node_id"] || ($x["status"] ?? null)!=="active" || ($x["checkout_path"] ?? null)!==$path || !is_string($x["selected_branch"] ?? null) || $x["selected_branch"]==="" || !is_string($x["starting_commit"] ?? null) || preg_match("/\\A[0-9a-f]{40}\\z/D", $x["starting_commit"])!==1 || ($x["effective_root"] ?? null)!=="public") exit(65);' "$sample_state" "$4" <<<"$typed_instances"
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
      app-prod) runtime_user=orbit-laravel; runtime_home=/var/www/laravel; checkouts=(/var/www/laravel/e2e-prod) ;;
      *) exit 64 ;;
    esac
    run_as_runtime() {
      if [[ "$(id -u)" -eq 0 ]]; then
        sudo -u "$runtime_user" -- env HOME="$runtime_home" "$@"
      else
        "$@"
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
      if ! run_as_runtime git -C "$checkout" cat-file -e "$2^{commit}"; then
        run_as_runtime git -C "$checkout" fetch --quiet origin "$2"
      fi
      run_as_runtime git -C "$checkout" reset --hard --quiet "$2"
      [[ "$(run_as_runtime git -C "$checkout" rev-parse HEAD)" == "$2" ]]
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
      curl --fail --silent --show-error --retry 10 --retry-delay 2 --retry-connrefused --retry-all-errors --connect-timeout 10 --max-time 30 --cacert "$ca" --resolve laravel.internal:443:127.0.0.1 https://laravel.internal/ >/dev/null
    fi
    ;;
  *) exit 64 ;;
esac

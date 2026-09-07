#!/usr/bin/env bash
set -euo pipefail
umask 077

orbit=/home/orbit/orbit/apps/cli/orbit
gateway=/home/orbit/orbit/apps/gateway
database=/home/orbit/.orbit/gateway.sqlite
app_slug=laravel-typed
legacy_branch=master
legacy_hostname=orb125-legacy.orbit
legacy_marker=orb-125-legacy.txt
legacy_marker_body='ORB-125 legacy default marker'
before_record=/tmp/orb-125-legacy-default-before.json

fail() {
    printf 'ORB-125 proof failed: %s\n' "$*" >&2
    exit 1
}

run_orbit() {
    sudo -u orbit -- env \
        HOME=/home/orbit \
        ORBIT_HOME=/home/orbit/.orbit \
        "$orbit" "$@"
}

load_app_node() {
    local apps nodes
    apps=$(run_orbit app:list --json)
    nodes=$(run_orbit node:list --json)
    read -r app_id default_branch repository_url node_id node_tld node_address < <(
        php -r '
            $apps=json_decode($argv[1],true,64,JSON_THROW_ON_ERROR)["apps"]??null;
            $nodes=json_decode($argv[2],true,64,JSON_THROW_ON_ERROR)["nodes"]??null;
            if(!is_array($apps)||!is_array($nodes)) exit(65);
            $apps=array_values(array_filter($apps,fn($x)=>is_array($x)&&($x["slug"]??null)===$argv[3]));
            $nodes=array_values(array_filter($nodes,fn($x)=>is_array($x)&&($x["name"]??null)==="app-dev"));
            if(count($apps)!==1||count($nodes)!==1) exit(65);
            $app=$apps[0]; $node=$nodes[0];
            foreach(["id","default_branch","repository_url"] as $key) if(!isset($app[$key])) exit(65);
            foreach(["id","tld","wireguard_ip"] as $key) if(!isset($node[$key])) exit(65);
            if(!is_int($app["id"])||!is_string($app["default_branch"])||$app["default_branch"]===""||!is_string($app["repository_url"])) exit(65);
            if(!is_int($node["id"])||!is_string($node["tld"])||$node["tld"]===""||!filter_var($node["wireguard_ip"],FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)) exit(65);
            foreach([$app["default_branch"],$app["repository_url"],$node["tld"],$node["wireguard_ip"]] as $value) if(strpbrk($value,"\t\r\n")!==false) exit(65);
            printf("%d\t%s\t%s\t%d\t%s\t%s\n",$app["id"],$app["default_branch"],$app["repository_url"],$node["id"],$node["tld"],$node["wireguard_ip"]);
        ' "$apps" "$nodes" "$app_slug"
    )
    [[ "$app_id" =~ ^[1-9][0-9]*$ && "$node_id" =~ ^[1-9][0-9]*$ ]] || fail 'invalid App or Node identity'
}

assert_source_path() {
    local checkout=$1
    [[ "$checkout" == "/home/orbit/apps/${app_slug}/"* ]] || fail 'checkout is outside the sample App placement'
    [[ "$checkout" != *//* && "$checkout" != */../* && "$checkout" != */./* ]] || fail 'checkout path is not normalized'
}

local_source_observation() {
    local checkout=$1 marker_path="$1/public/$legacy_marker"
    assert_source_path "$checkout"
    [[ -d "$checkout" && ! -L "$checkout" && -f "$marker_path" && ! -L "$marker_path" ]] || fail 'legacy source markers are unsafe'
    local real_path device_inode owner_mode top_level common_dir origin branch head marker_digest
    real_path=$(realpath -e -- "$checkout")
    device_inode=$(stat -c '%d:%i' -- "$checkout")
    owner_mode=$(stat -c '%U:%G:%a' -- "$checkout")
    top_level=$(git -C "$checkout" rev-parse --show-toplevel)
    common_dir=$(git -C "$checkout" rev-parse --path-format=absolute --git-common-dir)
    origin=$(git -C "$checkout" remote get-url origin)
    branch=$(git -C "$checkout" branch --show-current)
    head=$(git -C "$checkout" rev-parse HEAD)
    marker_digest=$(sha256sum -- "$marker_path" | cut -c1-64)
    php -r '
        $keys=["realpath","device_inode","owner_mode","git_toplevel","git_common_dir","origin","branch","head","marker_sha256"];
        $values=array_slice($argv,1);
        if(count($keys)!==count($values)) exit(65);
        foreach($values as $value) if($value===""||str_contains($value,"\0")||str_contains($value,"\n")||str_contains($value,"\r")) exit(65);
        echo json_encode(array_combine($keys,$values),JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),"\n";
    ' "$real_path" "$device_inode" "$owner_mode" "$top_level" "$common_dir" "$origin" "$branch" "$head" "$marker_digest"
}

remote_source_observation() {
    local checkout=$1 node_address=$2 quoted_checkout
    assert_source_path "$checkout"
    quoted_checkout=$(printf '%q' "$checkout")
    sudo -u orbit -- env HOME=/home/orbit ssh \
        -o BatchMode=yes \
        -o ConnectTimeout=10 \
        -o StrictHostKeyChecking=yes \
        -o UserKnownHostsFile=/home/orbit/.orbit/ssh/known_hosts \
        -i /home/orbit/.orbit/ssh/id_ed25519 \
        "orbit@${node_address}" \
        "bash -seu -- ${quoted_checkout}" <<'BASH'
checkout=$1
marker_path="$checkout/public/orb-125-legacy.txt"
[[ -d "$checkout" && ! -L "$checkout" && -f "$marker_path" && ! -L "$marker_path" ]]
real_path=$(realpath -e -- "$checkout")
device_inode=$(stat -c '%d:%i' -- "$checkout")
owner_mode=$(stat -c '%U:%G:%a' -- "$checkout")
top_level=$(git -C "$checkout" rev-parse --show-toplevel)
common_dir=$(git -C "$checkout" rev-parse --path-format=absolute --git-common-dir)
origin=$(git -C "$checkout" remote get-url origin)
branch=$(git -C "$checkout" branch --show-current)
head=$(git -C "$checkout" rev-parse HEAD)
marker_digest=$(sha256sum -- "$marker_path" | cut -c1-64)
php -r '
    $keys=["realpath","device_inode","owner_mode","git_toplevel","git_common_dir","origin","branch","head","marker_sha256"];
    $values=array_slice($argv,1);
    if(count($keys)!==count($values)) exit(65);
    foreach($values as $value) if($value===""||str_contains($value,"\0")||str_contains($value,"\n")||str_contains($value,"\r")) exit(65);
    echo json_encode(array_combine($keys,$values),JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),"\n";
' "$real_path" "$device_inode" "$owner_mode" "$top_level" "$common_dir" "$origin" "$branch" "$head" "$marker_digest"
BASH
}

https_observation() {
    local hostname=$1 address=$2 expected_body=$3 response status digest
    response=$(mktemp)
    status=$(curl --silent --show-error \
        --connect-timeout 10 \
        --max-time 30 \
        --cacert /etc/ssl/certs/ca-certificates.crt \
        --resolve "${hostname}:443:${address}" \
        --output "$response" \
        --write-out '%{http_code}' \
        "https://${hostname}/${legacy_marker}")
    [[ "$status" =~ ^[1-5][0-9][0-9]$ ]] || fail 'legacy Route returned no HTTP response'
    [[ "$(<"$response")" == "$expected_body" ]] || fail 'legacy Route marker changed'
    digest=$(sha256sum -- "$response" | cut -c1-64)
    rm -f -- "$response"
    php -r '
        if(preg_match("/\A[1-5][0-9]{2}\z/D",$argv[1])!==1||preg_match("/\A[0-9a-f]{64}\z/D",$argv[2])!==1) exit(65);
        echo json_encode(["status"=>(int)$argv[1],"body_sha256"=>$argv[2]],JSON_THROW_ON_ERROR),"\n";
    ' "$status" "$digest"
}

prepare_legacy_default() {
    load_app_node
    local response checkout
    git ls-remote --exit-code --heads "$repository_url" "refs/heads/$legacy_branch" >/dev/null || fail 'legacy fixture branch is unavailable'
    response=$(run_orbit instance:new "$app_id" "$node_id" "$legacy_branch" --hostname="$legacy_hostname" --json)
    checkout=$(php -r '
        $x=json_decode($argv[1],true,64,JSON_THROW_ON_ERROR);
        if(($x["name"]??null)!==$argv[2]||($x["source_layout"]??null)!=="checkout"||($x["selected_branch"]??null)!==$argv[2]||!array_key_exists("branch_override",$x)||$x["branch_override"]!==null||($x["migration_required"]??null)!==false||($x["status"]??null)!=="active"||($x["hostname"]??null)!==$argv[3]||($x["url"]??null)!=="https://".$argv[3]) exit(65);
        $route=$x["route"]??null;
        if(!is_array($route)||($route["hostname"]??null)!==$argv[3]||($route["status"]??null)!=="active"||($route["provenance"]??null)!=="explicit") exit(65);
        if(!is_string($x["checkout_path"]??null)) exit(65);
        echo $x["checkout_path"];
    ' "$response" "$legacy_branch" "$legacy_hostname")
    assert_source_path "$checkout"
    [[ "$checkout" == "/home/orbit/apps/${app_slug}/${legacy_branch}" ]] || fail 'legacy default placement changed'
    printf '%s\n' "$legacy_marker_body" | sudo -u orbit -- tee "$checkout/public/$legacy_marker" >/dev/null
    sudo -u orbit -- chmod 0644 "$checkout/public/$legacy_marker"
    local source https
    source=$(local_source_observation "$checkout")
    https=$(https_observation "$legacy_hostname" 127.0.0.1 "$legacy_marker_body")
    php -r '
        $source=json_decode($argv[1],true,32,JSON_THROW_ON_ERROR);
        $https=json_decode($argv[2],true,32,JSON_THROW_ON_ERROR);
        if(($source["branch"]??null)!==$argv[3]||($source["origin"]??null)!==$argv[4]||($https["status"]??null)!==200) exit(65);
    ' "$source" "$https" "$legacy_branch" "$repository_url"
    printf 'legacy default source %s serves at %s\n' "$checkout" "$legacy_hostname"
}

record_pre_upgrade() {
    local migration=$1
    [[ -f "$migration" && ! -L "$migration" ]] || fail 'migration file is unavailable'
    php -r '
        $pdo=new PDO("sqlite:".$argv[1],null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES,false);
        $s=$pdo->prepare("UPDATE apps SET default_branch = ? WHERE slug = ?");
        $s->execute([$argv[3],$argv[2]]);
        if($s->rowCount()!==1)exit(65);
        $s=$pdo->prepare("SELECT COUNT(*) FROM app_instances WHERE app_id=(SELECT id FROM apps WHERE slug=?) AND name=? AND branch=? AND branch_override IS NULL AND migration_required=0");
        $s->execute([$argv[2],$argv[3],$argv[3]]);
        if((int)$s->fetchColumn()!==1)exit(65);
    ' "$database" "$app_slug" "$legacy_branch"
    (
        cd "$gateway"
        php artisan migrate:rollback --force --path="${migration#"$gateway"/}"
    )
    local legacy_values checkout hostname node_address source https source_file https_file
    legacy_values=$(php -r '
        $pdo=new PDO("sqlite:".$argv[1],null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $columns=fn(string $table):array=>$pdo->query("PRAGMA table_info(".$table.")")->fetchAll(PDO::FETCH_COLUMN,1);
        $apps=$columns("apps"); $instances=$columns("app_instances");
        if(!in_array("main_branch",$apps,true)||in_array("default_branch",$apps,true)||!in_array("source_kind",$instances,true)||in_array("source_layout",$instances,true)||in_array("branch_override",$instances,true)||in_array("migration_required",$instances,true)) exit(65);
        $app=$pdo->prepare("SELECT * FROM apps WHERE slug = ?"); $app->execute([$argv[2]]); $app=$app->fetchAll(PDO::FETCH_ASSOC);
        if(count($app)!==1||!is_string($app[0]["main_branch"]??null)||$app[0]["main_branch"]==="") exit(65);
        $instance=$pdo->prepare("SELECT ai.*, n.wireguard_ip FROM app_instances ai JOIN nodes n ON n.id=ai.node_id WHERE ai.app_id=? AND ai.name=?");
        $instance->execute([$app[0]["id"],$app[0]["main_branch"]]); $instance=$instance->fetchAll(PDO::FETCH_ASSOC);
        if(count($instance)!==1||($instance[0]["source_kind"]??null)!=="managed_clone"||($instance[0]["branch"]??null)!==$app[0]["main_branch"]||($instance[0]["status"]??null)!=="active") exit(65);
        foreach(["checkout_path","wireguard_ip"] as $key) if(!is_string($instance[0][$key]??null)||strpbrk($instance[0][$key],"\t\r\n")!==false) exit(65);
        $route=$pdo->prepare("SELECT r.hostname FROM routes r JOIN route_targets rt ON rt.route_id=r.id WHERE rt.app_instance_id=?"); $route->execute([$instance[0]["id"]]); $route=$route->fetchAll(PDO::FETCH_ASSOC);
        if(count($route)!==1||!is_string($route[0]["hostname"]??null)||strpbrk($route[0]["hostname"],"\t\r\n")!==false) exit(65);
        printf("%s\t%s\t%s\n",$instance[0]["checkout_path"],$route[0]["hostname"],$instance[0]["wireguard_ip"]);
    ' "$database" "$app_slug")
    IFS=$'\t' read -r checkout hostname node_address <<<"$legacy_values"
    [[ "$hostname" == "$legacy_hostname" ]] || fail 'legacy hostname identity changed before recording'
    source=$(remote_source_observation "$checkout" "$node_address")
    https=$(https_observation "$hostname" "$node_address" "$legacy_marker_body")
    source_file=$(mktemp)
    https_file=$(mktemp)
    printf '%s\n' "$source" >"$source_file"
    printf '%s\n' "$https" >"$https_file"
    php -r '
        $pdo=new PDO("sqlite:".$argv[1],null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $one=function(string $sql,array $bindings=[])use($pdo):array{$s=$pdo->prepare($sql);$s->execute($bindings);$r=$s->fetchAll(PDO::FETCH_ASSOC);if(count($r)!==1)exit(65);return $r[0];};
        $app=$one("SELECT * FROM apps WHERE slug = ?",[$argv[2]]);
        $instance=$one("SELECT * FROM app_instances WHERE app_id = ? AND name = ?",[$app["id"],$app["main_branch"]]);
        $route=$one("SELECT r.* FROM routes r JOIN route_targets rt ON rt.route_id=r.id WHERE rt.app_instance_id=?",[$instance["id"]]);
        $target=$one("SELECT * FROM route_targets WHERE route_id=? AND app_instance_id=?",[$route["id"],$instance["id"]]);
        if($instance["source_kind"]!=="managed_clone"||$route["hostname"]!==$argv[3]||$route["status"]!=="active"||(int)$target["position"]!==0)exit(65);
        $record=["app"=>$app,"app_instance"=>$instance,"route"=>$route,"route_target"=>$target,"source"=>json_decode(file_get_contents($argv[4]),true,32,JSON_THROW_ON_ERROR),"https"=>json_decode(file_get_contents($argv[5]),true,32,JSON_THROW_ON_ERROR)];
        $json=json_encode($record,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
        if(file_put_contents($argv[6],$json,LOCK_EX)!==strlen($json))exit(65);
        chmod($argv[6],0600);
    ' "$database" "$app_slug" "$legacy_hostname" "$source_file" "$https_file" "$before_record"
    rm -f -- "$source_file" "$https_file"
    printf 'recorded exact pre-upgrade legacy graph at %s\n' "$before_record"
}

migrate_and_verify_legacy_default() {
    local migration=$1
    [[ -f "$migration" && ! -L "$migration" && -f "$before_record" && ! -L "$before_record" ]] || fail 'migration proof input is unavailable'
    (
        cd "$gateway"
        php artisan migrate --force --path="${migration#"$gateway"/}"
    )
    local values checkout hostname node_address source https source_file https_file live
    values=$(php -r '
        $r=json_decode(file_get_contents($argv[1]),true,64,JSON_THROW_ON_ERROR);
        foreach(["app","app_instance","route","route_target","source","https"] as $key)if(!is_array($r[$key]??null))exit(65);
        foreach([$r["app_instance"]["checkout_path"]??null,$r["route"]["hostname"]??null] as $value)if(!is_string($value)||strpbrk($value,"\t\r\n")!==false)exit(65);
        $pdo=new PDO("sqlite:".$argv[2],null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $s=$pdo->prepare("SELECT wireguard_ip FROM nodes WHERE id=?");$s->execute([$r["app_instance"]["node_id"]]);$ip=$s->fetchColumn();
        if(!is_string($ip)||!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4))exit(65);
        printf("%s\t%s\t%s\n",$r["app_instance"]["checkout_path"],$r["route"]["hostname"],$ip);
    ' "$before_record" "$database")
    IFS=$'\t' read -r checkout hostname node_address <<<"$values"
    source=$(remote_source_observation "$checkout" "$node_address")
    https=$(https_observation "$hostname" "$node_address" "$legacy_marker_body")
    source_file=$(mktemp)
    https_file=$(mktemp)
    live=$(mktemp)
    printf '%s\n' "$source" >"$source_file"
    printf '%s\n' "$https" >"$https_file"
    run_orbit instance:show "$(php -r '$r=json_decode(file_get_contents($argv[1]),true,64,JSON_THROW_ON_ERROR);echo $r["app_instance"]["id"]??"";' "$before_record")" --json >"$live"
    php -r '
        function refuse(bool $ok,string $message):void{if(!$ok){fwrite(STDERR,"ORB-125 migration proof refused: {$message}\n");exit(65);}}
        $before=json_decode(file_get_contents($argv[1]),true,64,JSON_THROW_ON_ERROR);
        $pdo=new PDO("sqlite:".$argv[2],null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $columns=fn(string $table):array=>$pdo->query("PRAGMA table_info(".$table.")")->fetchAll(PDO::FETCH_COLUMN,1);
        $apps=$columns("apps");$instances=$columns("app_instances");
        refuse(in_array("default_branch",$apps,true)&&!in_array("main_branch",$apps,true),"App columns");
        foreach(["source_layout","branch_override","migration_required"] as $column)refuse(in_array($column,$instances,true),"AppInstance columns");
        refuse(!in_array("source_kind",$instances,true),"legacy AppInstance column");
        $one=function(string $table,int $id)use($pdo):array{$s=$pdo->prepare("SELECT * FROM ".$table." WHERE id=?");$s->execute([$id]);$r=$s->fetchAll(PDO::FETCH_ASSOC);refuse(count($r)===1,$table." identity");return$r[0];};
        $app=$one("apps",(int)$before["app"]["id"]);
        $instance=$one("app_instances",(int)$before["app_instance"]["id"]);
        $route=$one("routes",(int)$before["route"]["id"]);
        $target=$one("route_targets",(int)$before["route_target"]["id"]);
        refuse($app["default_branch"]===$before["app"]["main_branch"],"default branch mapping");
        $expectedApp=$before["app"];$actualApp=$app;unset($expectedApp["main_branch"],$actualApp["default_branch"]);
        refuse($actualApp===$expectedApp,"App row changed");
        refuse($instance["source_layout"]==="checkout"&&$instance["branch_override"]===null&&(int)$instance["migration_required"]===1,"migration fields");
        refuse($before["app_instance"]["source_kind"]==="managed_clone","legacy source kind");
        $expectedInstance=$before["app_instance"];$actualInstance=$instance;
        unset($expectedInstance["source_kind"],$actualInstance["source_layout"],$actualInstance["branch_override"],$actualInstance["migration_required"]);
        refuse($actualInstance===$expectedInstance,"AppInstance row changed");
        refuse($route===$before["route"],"Route row changed");
        refuse($target===$before["route_target"],"Route target row changed");
        $source=json_decode(file_get_contents($argv[3]),true,32,JSON_THROW_ON_ERROR);
        $https=json_decode(file_get_contents($argv[4]),true,32,JSON_THROW_ON_ERROR);
        refuse($source===$before["source"],"source evidence changed");
        refuse($https===$before["https"],"HTTPS evidence changed");
        $live=json_decode(file_get_contents($argv[5]),true,64,JSON_THROW_ON_ERROR);
        refuse(($live["id"]??null)===(int)$instance["id"]&&($live["name"]??null)===$instance["name"]&&($live["checkout_path"]??null)===$instance["checkout_path"],"read model identity");
        refuse(($live["source_layout"]??null)==="checkout"&&array_key_exists("branch_override",$live)&&$live["branch_override"]===null&&($live["migration_required"]??null)===true,"reported migration state");
        refuse(($live["selected_branch"]??null)===$instance["branch"]&&($live["hostname"]??null)===$route["hostname"]&&($live["url"]??null)==="https://".$route["hostname"],"read model source or Route");
    ' "$before_record" "$database" "$source_file" "$https_file" "$live"
    rm -f -- "$source_file" "$https_file" "$live"
    printf 'legacy default migration preserved exact database, source, Route, and HTTPS evidence\n'
}

stable_default_instance() {
    load_app_node
    local expected_hostname="${app_slug}.${node_tld}" response checkout status body
    response=$(run_orbit instance:new "$app_id" "$node_id" default --json)
    checkout=$(php -r '
        $x=json_decode($argv[1],true,64,JSON_THROW_ON_ERROR);$route=$x["route"]??null;
        if(($x["name"]??null)!=="default"||($x["source_layout"]??null)!=="checkout"||($x["selected_branch"]??null)!==$argv[2]||!array_key_exists("branch_override",$x)||$x["branch_override"]!==null||($x["migration_required"]??null)!==false||($x["status"]??null)!=="active")exit(65);
        if(($x["hostname"]??null)!==$argv[3]||($x["url"]??null)!=="https://".$argv[3]||!is_array($route)||($route["hostname"]??null)!==$argv[3]||($route["provenance"]??null)!=="generated"||($route["status"]??null)!=="active")exit(65);
        if(!is_string($x["checkout_path"]??null)||!is_string($x["starting_commit"]??null))exit(65);
        echo $x["checkout_path"],"\t",$x["starting_commit"];
    ' "$response" "$default_branch" "$expected_hostname")
    IFS=$'\t' read -r checkout starting_commit <<<"$checkout"
    [[ "$checkout" == "/home/orbit/apps/${app_slug}/default" && -d "$checkout/.git" && ! -L "$checkout" ]] || fail 'default placement or checkout layout is invalid'
    [[ "$(git -C "$checkout" branch --show-current)" == "$default_branch" && "$(git -C "$checkout" rev-parse HEAD)" == "$starting_commit" ]] || fail 'default source identity is invalid'
    [[ ! -e "$checkout/vendor/autoload.php" ]] || fail 'default proof unexpectedly has installed Laravel dependencies'
    [[ -f "$checkout/.env" && ! -L "$checkout/.env" ]] || fail 'Laravel environment is unavailable'
    [[ "$(grep -c '^APP_URL=' "$checkout/.env")" -eq 1 ]] || fail 'Laravel APP_URL is not unique'
    grep -Fx "APP_URL=https://${expected_hostname}" "$checkout/.env" >/dev/null || fail 'Laravel APP_URL does not match the Route'
    body=$(mktemp)
    status=$(curl --silent --show-error --connect-timeout 10 --max-time 30 --cacert /etc/ssl/certs/ca-certificates.crt --resolve "${expected_hostname}:443:127.0.0.1" --output "$body" --write-out '%{http_code}' "https://${expected_hostname}/")
    rm -f -- "$body"
    [[ "$status" == 500 ]] || fail "dependency-free Laravel endpoint returned ${status}, expected 500"
    printf 'stable default %s uses %s at %s and serves the expected application error\n' "$checkout" "$default_branch" "$expected_hostname"
}

explicit_instance_branch() {
    load_app_node
    local name=orb125-explicit selected_branch=12.x expected_hostname response checkout starting_commit
    [[ "$selected_branch" != "$default_branch" ]] || fail 'explicit proof branch unexpectedly equals the App default'
    git ls-remote --exit-code --heads "$repository_url" "refs/heads/$selected_branch" >/dev/null || fail 'explicit proof branch is unavailable'
    expected_hostname="${name}.${app_slug}.${node_tld}"
    response=$(run_orbit instance:new "$app_id" "$node_id" "$name" --branch="$selected_branch" --json)
    read -r checkout starting_commit < <(php -r '
        $x=json_decode($argv[1],true,64,JSON_THROW_ON_ERROR);$route=$x["route"]??null;
        if(($x["name"]??null)!==$argv[2]||($x["source_layout"]??null)!=="checkout"||($x["selected_branch"]??null)!==$argv[3]||($x["branch_override"]??null)!==$argv[3]||($x["migration_required"]??null)!==false||($x["status"]??null)!=="active")exit(65);
        if(($x["hostname"]??null)!==$argv[4]||($x["url"]??null)!=="https://".$argv[4]||!is_array($route)||($route["hostname"]??null)!==$argv[4]||($route["provenance"]??null)!=="generated"||($route["status"]??null)!=="active")exit(65);
        if(!is_string($x["checkout_path"]??null)||!is_string($x["starting_commit"]??null))exit(65);
        printf("%s %s\n",$x["checkout_path"],$x["starting_commit"]);
    ' "$response" "$name" "$selected_branch" "$expected_hostname")
    [[ "$checkout" == "/home/orbit/apps/${app_slug}/${name}" && -d "$checkout/.git" && ! -L "$checkout" ]] || fail 'explicit branch changed placement identity'
    [[ "$(git -C "$checkout" branch --show-current)" == "$selected_branch" && "$(git -C "$checkout" rev-parse HEAD)" == "$starting_commit" ]] || fail 'explicit branch source identity is invalid'
    grep -Fx "APP_URL=https://${expected_hostname}" "$checkout/.env" >/dev/null || fail 'explicit branch changed Route URL alignment'
    printf 'explicit branch %s uses stable placement %s and hostname %s\n' "$selected_branch" "$checkout" "$expected_hostname"
}

explicit_branch_missing() {
    load_app_node
    local name=orb125-missing missing_branch=orb125-no-such-branch output checkout
    output=$(mktemp)
    if run_orbit instance:new "$app_id" "$node_id" "$name" --branch="$missing_branch" --json >"$output" 2>&1; then
        fail 'missing explicit branch unexpectedly succeeded'
    fi
    grep -F 'instance.branch_resolution_failed' "$output" >/dev/null || fail 'missing explicit branch returned the wrong error'
    rm -f -- "$output"
    local instances
    instances=$(run_orbit instance:list --json)
    checkout=$(php -r '
        $x=json_decode($argv[1],true,64,JSON_THROW_ON_ERROR)["app_instances"]??null;
        if(!is_array($x))exit(65);$x=array_values(array_filter($x,fn($i)=>is_array($i)&&($i["name"]??null)===$argv[2]));if(count($x)!==1)exit(65);$i=$x[0];$route=$i["route"]??null;
        if(!array_key_exists("selected_branch",$i)||$i["selected_branch"]!==null||($i["branch_override"]??null)!==$argv[3]||!array_key_exists("starting_commit",$i)||$i["starting_commit"]!==null||($i["status"]??null)!=="checkout_prepared"||($i["migration_required"]??null)!==false)exit(65);
        if(!is_array($route)||($route["status"]??null)!=="failed"||($route["error_code"]??null)!=="instance.branch_resolution_failed")exit(65);
        if(!is_string($i["checkout_path"]??null))exit(65);echo $i["checkout_path"];
    ' "$instances" "$name" "$missing_branch")
    [[ "$checkout" == "/home/orbit/apps/${app_slug}/${name}" && -d "$checkout/.git" ]] || fail 'failed explicit branch placement is invalid'
    ! git -C "$checkout" show-ref --verify --quiet "refs/heads/$missing_branch" || fail 'missing explicit branch was created locally'
    [[ ! -e "$checkout/public/index.php" ]] || fail 'missing explicit branch selected fallback worktree content'
    printf 'missing explicit branch refused fallback and left no active AppInstance or Route\n'
}

case "${1-}" in
    prepare-legacy-default)
        [[ $# -eq 1 ]] || fail 'prepare-legacy-default takes no extra arguments'
        prepare_legacy_default
        ;;
    record-pre-upgrade)
        [[ $# -eq 2 ]] || fail 'record-pre-upgrade requires the migration path'
        record_pre_upgrade "$2"
        ;;
    migrate-and-verify-legacy-default)
        [[ $# -eq 2 ]] || fail 'migrate-and-verify-legacy-default requires the migration path'
        migrate_and_verify_legacy_default "$2"
        ;;
    stable-default-instance)
        [[ $# -eq 1 ]] || fail 'stable-default-instance takes no extra arguments'
        stable_default_instance
        ;;
    explicit-instance-branch)
        [[ $# -eq 1 ]] || fail 'explicit-instance-branch takes no extra arguments'
        explicit_instance_branch
        ;;
    explicit-branch-missing)
        [[ $# -eq 1 ]] || fail 'explicit-branch-missing takes no extra arguments'
        explicit_branch_missing
        ;;
    *)
        fail "unknown action: ${1-}"
        ;;
esac

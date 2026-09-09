#!/usr/bin/env bash
set -euo pipefail

scenario=${1:-}
repository=/home/orbit/orbit
gateway="$repository/apps/gateway"
fixture=/var/lib/orbit-e2e/proof/orb197-production.php

if [[ ! -f "$fixture" ]]; then
    fixture="$repository/.loop/proof/orb197-production.php"
fi

gateway_fixture() {
    (
        cd "$gateway"
        php "$fixture" "$@"
    )
}

json_field() {
    php -r '
        $value = json_decode(stream_get_contents(STDIN), true, 64, JSON_THROW_ON_ERROR);
        $path = explode(".", $argv[1]);
        foreach ($path as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) exit(65);
            $value = $value[$part];
        }
        if (is_bool($value)) echo $value ? "true" : "false";
        elseif (is_int($value) || is_string($value)) echo $value;
        elseif ($value === null) echo "null";
        else exit(65);
    ' "$1"
}

cli_app_id() {
    orbit app:list --json | php -r '
        $value=json_decode(stream_get_contents(STDIN), true, 64, JSON_THROW_ON_ERROR);
        $matches=array_values(array_filter($value["apps"] ?? [], static fn($app) => is_array($app) && ($app["slug"] ?? null)===$argv[1]));
        if(count($matches)!==1 || !is_int($matches[0]["id"] ?? null)) exit(65);
        echo $matches[0]["id"];
    ' "$1"
}

cli_node_id() {
    orbit node:list --json | php -r '
        $value=json_decode(stream_get_contents(STDIN), true, 64, JSON_THROW_ON_ERROR);
        $matches=array_values(array_filter($value["nodes"] ?? [], static fn($node) => is_array($node) && ($node["name"] ?? null)===$argv[1]));
        if(count($matches)!==1 || !is_int($matches[0]["id"] ?? null)) exit(65);
        echo $matches[0]["id"];
    ' "$1"
}

fixture_app_id() {
    gateway_fixture state | php -r '
        $value=json_decode(stream_get_contents(STDIN), true, 64, JSON_THROW_ON_ERROR);
        $id=$value["apps"][$argv[1]] ?? null;
        if(!is_int($id)) exit(65);
        echo $id;
    ' "$1"
}

fixture_node_field() {
    gateway_fixture node "$1" | json_field "$2"
}

expect_error() {
    local expected=$1
    shift
    local output status
    set +e
    output=$("$@" --json 2>&1)
    status=$?
    set -e
    [[ "$status" -ne 0 ]]
    php -r '
        $value=json_decode($argv[1], true, 64, JSON_THROW_ON_ERROR);
        $error=$value["error"] ?? null;
        if(!is_array($error) || ($error["code"] ?? null)!==$argv[2] || !is_string($error["message"] ?? null) || $error["message"]==="" || !is_string($error["request_id"] ?? null) || $error["request_id"]==="") exit(65);
    ' "$output" "$expected"
}

assert_instance() {
    local output=$1 app_id=$2 node_id=$3 name=$4 hostname=$5 branch=$6 override=$7 root=$8
    php -r '
        $value=json_decode($argv[1], true, 64, JSON_THROW_ON_ERROR);
        $required=["id","app_id","node_id","name","environment","source_layout","checkout_path","production_user","production_home","root","effective_root","selected_branch","branch_override","migration_required","starting_commit","status","route","hostname","url","removal","request_id"];
        foreach($required as $key) if(!array_key_exists($key,$value)) exit(65);
        $id=$value["id"] ?? null;
        $user="orbit-app-".$argv[2];
        $home="/home/".$user;
        $override=$argv[7]==="null" ? null : $argv[7];
        $root=$argv[8]==="null" ? null : $argv[8];
        $effective=$home."/".($root ?? "public");
        $route=$value["route"] ?? null;
        if(!is_int($id) || $id<1 || ($value["app_id"] ?? null)!==(int)$argv[2] || ($value["node_id"] ?? null)!==(int)$argv[3] || ($value["name"] ?? null)!==$argv[4] || ($value["environment"] ?? null)!=="production" || ($value["source_layout"] ?? null)!=="checkout" || ($value["checkout_path"] ?? null)!==$home || ($value["production_user"] ?? null)!==$user || ($value["production_home"] ?? null)!==$home || ($value["root"] ?? null)!==$root || ($value["effective_root"] ?? null)!==$effective || ($value["selected_branch"] ?? null)!==$argv[6] || ($value["branch_override"] ?? null)!==$override || ($value["migration_required"] ?? null)!==false || !is_string($value["starting_commit"] ?? null) || preg_match("/\\A[0-9a-f]{40}\\z/D",$value["starting_commit"])!==1 || ($value["status"] ?? null)!=="active" || !is_array($route) || ($route["app_id"] ?? null)!==(int)$argv[2] || ($route["node_id"] ?? null)!==(int)$argv[3] || ($route["cluster_id"] ?? null)!==null || ($route["hostname"] ?? null)!==$argv[5] || ($route["publication"] ?? null)!=="private" || ($route["status"] ?? null)!=="active" || ($route["target"]["app_instance_id"] ?? null)!==$id || ($value["hostname"] ?? null)!==$argv[5] || ($value["url"] ?? null)!=="https://".$argv[5] || ($value["removal"] ?? null)!==null || !is_string($value["request_id"] ?? null) || $value["request_id"]==="") exit(65);
    ' "$output" "$app_id" "$node_id" "$name" "$hostname" "$branch" "$override" "$root"
}

remote_script() {
    local node=$1
    shift
    local ip
    ip=$(fixture_node_field "$node" wireguard_ip)
    ssh -i /home/orbit/.orbit/ssh/id_ed25519 \
        -o UserKnownHostsFile=/home/orbit/.orbit/ssh/known_hosts \
        -o BatchMode=yes \
        -o StrictHostKeyChecking=yes \
        -- "orbit@$ip" bash -seu -- "$@"
}

inspect_single() {
    gateway_fixture inspect "$1" | php -r '
        $value=json_decode(stream_get_contents(STDIN), true, 64, JSON_THROW_ON_ERROR);
        if(count($value["instances"] ?? [])!==1) exit(65);
        echo json_encode($value["instances"][0], JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),"\n";
    '
}

case "$scenario" in
    setup-node)
        [[ $# -eq 2 && "$2" =~ ^app-prod(-2)?$ && "$(id -u)" -eq 1000 ]]
        base=/var/www/orb197
        sudo install -d -o orbit -g orbit -m 0755 "$base"
        for name in create initial missing generated nonphp cluster laravel safety-home safety-existing safety-root retry active remove; do
            work=$(mktemp -d)
            trap 'rm -rf -- "$work"' EXIT
            git -C "$work" init --initial-branch=main --quiet
            git -C "$work" config user.name 'Orbit E2E'
            git -C "$work" config user.email orbit@example.test
            mkdir -p "$work/public" "$work/web"
            case "$name" in
                nonphp)
                    printf 'orb197-nonphp\n' > "$work/public/index.html"
                    ;;
                laravel)
                    printf '{"require":{"php":"^8.4","laravel/framework":"^13.0"}}\n' > "$work/composer.json"
                    printf '#!/usr/bin/env php\n<?php echo "artisan";\n' > "$work/artisan"
                    chmod +x "$work/artisan"
                    printf '<?php echo "orb197-laravel";\n' > "$work/public/index.php"
                    ;;
                retry)
                    printf '{"require":{"php":"<8.4"}}\n' > "$work/composer.json"
                    printf '<?php echo "orb197-retry";\n' > "$work/public/index.php"
                    ;;
                safety-root)
                    printf '{"require":{"php":"^8.4"}}\n' > "$work/composer.json"
                    rm -rf "$work/public"
                    ln -s /tmp "$work/public"
                    ;;
                *)
                    printf '{"require":{"php":"^8.4"}}\n' > "$work/composer.json"
                    printf '<?php echo "orb197-%s";\n' "$name" > "$work/public/index.php"
                    printf '<?php echo "orb197-%s-web";\n' "$name" > "$work/web/index.php"
                    ;;
            esac
            printf '%s-main\n' "$name" > "$work/branch.txt"
            git -C "$work" add .
            GIT_AUTHOR_DATE=2026-01-01T00:00:00Z GIT_COMMITTER_DATE=2026-01-01T00:00:00Z git -C "$work" commit --quiet -m main
            git -C "$work" checkout --quiet -b release
            printf '%s-release\n' "$name" > "$work/branch.txt"
            git -C "$work" add branch.txt
            GIT_AUTHOR_DATE=2026-01-02T00:00:00Z GIT_COMMITTER_DATE=2026-01-02T00:00:00Z git -C "$work" commit --quiet -m release
            git -C "$work" checkout --quiet main
            rm -rf -- "$base/$name.git"
            git clone --quiet --bare "$work" "$base/$name.git"
            git -C "$base/$name.git" update-server-info
            chmod -R a+rX "$base/$name.git"
            rm -rf -- "$work"
            trap - EXIT
        done
        live=$(readlink -f /etc/caddy/Caddyfile)
        case "$live" in /etc/caddy/orbit-versions/*/Caddyfile) ;; *) exit 65 ;; esac
        fragments=$(dirname "$live")/fragments
        candidate=$(mktemp)
        cat > "$candidate" <<'CADDY'
https://localhost {
    tls internal
    root * /var/www
    file_server
}
CADDY
        sudo install -o root -g caddy -m 0640 "$candidate" "$fragments/01-orb197-source.caddy"
        rm -f -- "$candidate"
        sudo caddy validate --config /etc/caddy/Caddyfile --adapter caddyfile >/dev/null
        sudo systemctl reload caddy
        curl --fail --silent --show-error --insecure --retry 10 --retry-delay 1 https://localhost/orb197/create.git/HEAD >/dev/null
        ca=/var/lib/caddy/.local/share/caddy/pki/authorities/local/root.crt
        sudo test -s "$ca"
        sudo install -o root -g root -m 0644 "$ca" /usr/local/share/ca-certificates/orb197-source.crt
        sudo update-ca-certificates >/dev/null
        git ls-remote --exit-code https://localhost/orb197/create.git refs/heads/main >/dev/null
        status=$(sudo ufw status)
        grep -Fq 'operator:orb197' <<<"$status" || sudo ufw allow in proto tcp from 10.44.0.99 to any port 4242 comment 'operator:orb197' >/dev/null
        grep -Fq 'orbit:app-prod-http' <<<"$status" || sudo ufw allow in proto tcp to any port 80 comment 'orbit:app-prod-http' >/dev/null
        grep -Fq 'orbit:app-prod-https' <<<"$status" || sudo ufw allow in proto tcp to any port 443 comment 'orbit:app-prod-https' >/dev/null
        printf '%s source repositories ready\n' "$2"
        ;;

    setup-gateway)
        [[ $# -eq 1 && "$(id -u)" -eq 1000 ]]
        (cd "$gateway" && php artisan migrate --force --no-interaction >/dev/null)
        gateway_fixture setup >/dev/null
        ;;

    setup-observation)
        [[ $# -eq 1 && "$(id -u)" -eq 1000 ]]
        orbit app:list --json | php -r '$v=json_decode(stream_get_contents(STDIN),true,64,JSON_THROW_ON_ERROR); if(!is_array($v["apps"]??null)) exit(65);'
        ;;

    standalone-create)
        [[ $# -eq 1 && "$(id -u)" -eq 1000 ]]
        app_id=$(cli_app_id orb197-create)
        node_id=$(cli_node_id app-prod)
        output=$(orbit instance:new "$app_id" "$node_id" production --root=web --hostname=orb197-create.test --json)
        assert_instance "$output" "$app_id" "$node_id" production orb197-create.test main null web
        expect_error instance.production_placement_conflict orbit instance:new "$app_id" "$node_id" duplicate --root=web --hostname=orb197-duplicate.test
        printf 'standalone production response and placement cardinality passed\n'
        ;;

    initial-source)
        [[ $# -eq 1 && "$(id -u)" -eq 1000 ]]
        app_id=$(fixture_app_id orb197-initial)
        node_id=$(fixture_node_field app-prod-2 id)
        output=$(orbit instance:new "$app_id" "$node_id" blue --branch=release --hostname=orb197-initial.test --json)
        assert_instance "$output" "$app_id" "$node_id" blue orb197-initial.test release release null
        user=orbit-app-$app_id
        expected_commit=$(php -r '$v=json_decode($argv[1],true,64,JSON_THROW_ON_ERROR); echo $v["starting_commit"];' "$output")
        remote_script app-prod-2 "$user" "$expected_commit" <<'REMOTE'
user=$1
expected=$2
home=/home/$user
test "$(sudo -u "$user" -H git -C "$home" branch --show-current)" = release
test "$(sudo -u "$user" -H git -C "$home" rev-parse HEAD)" = "$expected"
test "$(sudo -u "$user" -H git -C "$home" remote get-url origin)" = https://localhost/orb197/initial.git
test "$(cat "$home/branch.txt")" = initial-release
test -z "$(find -P "$home" -xdev ! -user "$user" -print -quit)"
REMOTE
        printf 'explicit production branch and initial source evidence passed\n'
        ;;

    branch-refusal)
        [[ $# -eq 1 && "$(id -u)" -eq 1000 ]]
        app_id=$(fixture_app_id orb197-missing)
        node_id=$(fixture_node_field app-prod id)
        expect_error route.tld_required orbit instance:new "$app_id" "$node_id" default
        evidence=$(gateway_fixture inspect orb197-missing)
        [[ "$(json_field route_count <<<"$evidence")" == 0 ]]
        [[ "$(php -r '$v=json_decode($argv[1],true,64,JSON_THROW_ON_ERROR); echo count($v["instances"]);' "$evidence")" == 0 ]]
        expect_error instance.branch_resolution_failed orbit instance:new "$app_id" "$node_id" default --branch=absent --hostname=orb197-missing.test
        instance=$(inspect_single orb197-missing)
        [[ "$(json_field provisioning_step <<<"$instance")" == source-prepared ]]
        [[ "$(json_field error_code <<<"$instance")" == instance.branch_resolution_failed ]]
        [[ "$(gateway_fixture inspect orb197-missing | json_field route_count)" == 0 ]]
        generated_app=$(fixture_app_id orb197-generated)
        generated_node=$(fixture_node_field app-prod-2 id)
        output=$(orbit instance:new "$generated_app" "$generated_node" default --json)
        assert_instance "$output" "$generated_app" "$generated_node" default orb197-generated.prodtest main null null
        printf 'branch refusal and hostname preflight passed\n'
        ;;

    non-laravel)
        [[ $# -eq 1 && "$(id -u)" -eq 1000 ]]
        app_id=$(fixture_app_id orb197-nonphp)
        node_id=$(fixture_node_field app-prod-2 id)
        output=$(orbit instance:new "$app_id" "$node_id" static --hostname=orb197-nonphp.test --json)
        assert_instance "$output" "$app_id" "$node_id" static orb197-nonphp.test main null null
        instance_id=$(json_field id <<<"$output")
        create_id=$(fixture_app_id orb197-create)
        create_instance=$(inspect_single orb197-create)
        create_instance_id=$(json_field id <<<"$create_instance")
        remote_script app-prod-2 "$instance_id" <<'REMOTE'
instance=$1
test ! -S "/run/php/orbit-app-instance-$instance.sock"
! sudo grep -R -Fq -- "[orbit-app-instance-$instance]" /etc/php/*/fpm/pool.d
REMOTE
        remote_script app-prod "$create_instance_id" "$create_id" <<'REMOTE'
instance=$1
app=$2
test -S "/run/php/orbit-app-instance-$instance.sock"
sudo grep -R -Fq -- "[orbit-app-instance-$instance]" /etc/php/8.5/fpm/pool.d
sudo grep -R -Fq -- "user = orbit-app-$app" /etc/php/8.5/fpm/pool.d
REMOTE
        printf 'plain PHP selection and non-PHP runtime omission passed\n'
        ;;

    intermediate-gates)
        [[ $# -eq 1 && "$(id -u)" -eq 1000 ]]
        cluster_app=$(fixture_app_id orb197-cluster)
        node_id=$(fixture_node_field app-prod-2 id)
        gateway_fixture cluster on >/dev/null
        trap 'gateway_fixture cluster off >/dev/null' EXIT
        expect_error instance.cluster_production_unavailable orbit instance:new "$cluster_app" "$node_id" default --hostname=orb197-cluster.test
        [[ "$(gateway_fixture inspect orb197-cluster | json_field route_count)" == 0 ]]
        [[ "$(gateway_fixture inspect orb197-cluster | php -r '$v=json_decode(stream_get_contents(STDIN),true,64,JSON_THROW_ON_ERROR); echo count($v["instances"]);')" == 0 ]]
        gateway_fixture cluster off >/dev/null
        trap - EXIT
        laravel_app=$(fixture_app_id orb197-laravel)
        expect_error app-prod.laravel_activation_unavailable orbit instance:new "$laravel_app" "$node_id" default --hostname=orb197-laravel.test
        instance=$(inspect_single orb197-laravel)
        [[ "$(json_field provisioning_step <<<"$instance")" == source-classified ]]
        [[ "$(json_field source_is_laravel <<<"$instance")" == true ]]
        [[ "$(gateway_fixture inspect orb197-laravel | json_field route_count)" == 0 ]]
        printf 'cluster and Laravel gates passed without an active Route\n'
        ;;

    source-safety)
        [[ $# -eq 1 && "$(id -u)" -eq 1000 ]]
        node_id=$(fixture_node_field app-prod id)
        home_app=$(fixture_app_id orb197-safety-home)
        home_user=orbit-app-$home_app
        remote_script app-prod "$home_user" <<'REMOTE'
user=$1
sudo rm -rf -- "/home/$user"
sudo ln -s /tmp "/home/$user"
REMOTE
        expect_error app-prod.user_conflict orbit instance:new "$home_app" "$node_id" default --hostname=orb197-safety-home.test
        [[ "$(gateway_fixture inspect orb197-safety-home | json_field route_count)" == 0 ]]
        existing_app=$(fixture_app_id orb197-safety-existing)
        existing_user=orbit-app-$existing_app
        remote_script app-prod "$existing_user" <<'REMOTE'
user=$1
home=/home/$user
sudo useradd --system --user-group --home-dir "$home" --shell /usr/sbin/nologin -- "$user"
sudo install -d -o "$user" -g "$user" -m 0700 -- "$home"
sudo -u "$user" -H git clone --quiet --no-checkout https://localhost/orb197/safety-existing.git "$home"
REMOTE
        expect_error instance.clone_failed orbit instance:new "$existing_app" "$node_id" default --hostname=orb197-safety-existing.test
        [[ "$(gateway_fixture inspect orb197-safety-existing | json_field route_count)" == 0 ]]
        root_app=$(fixture_app_id orb197-safety-root)
        expect_error app-prod.source_metadata_unsafe orbit instance:new "$root_app" "$node_id" default --hostname=orb197-safety-root.test
        root_instance=$(inspect_single orb197-safety-root)
        [[ "$(json_field provisioning_step <<<"$root_instance")" == source-resolved ]]
        [[ "$(json_field error_code <<<"$root_instance")" == app-prod.source_metadata_unsafe ]]
        [[ "$(gateway_fixture inspect orb197-safety-root | json_field route_count)" == 0 ]]
        printf 'production user, existing source, ownership, and root containment gates passed\n'
        ;;

    standalone-retry)
        [[ $# -eq 1 && "$(id -u)" -eq 1000 ]]
        app_id=$(fixture_app_id orb197-retry)
        node_id=$(fixture_node_field app-prod-2 id)
        expect_error app-prod.php_version_unsupported orbit instance:new "$app_id" "$node_id" default --hostname=orb197-retry.test
        instance=$(inspect_single orb197-retry)
        [[ "$(json_field provisioning_step <<<"$instance")" == source-resolved ]]
        [[ "$(json_field error_code <<<"$instance")" == app-prod.php_version_unsupported ]]
        user=orbit-app-$app_id
        remote_script app-prod-2 "$user" <<'REMOTE'
user=$1
home=/home/$user
sudo -u "$user" -H mv "$home/.git" "$home/.git.operator"
printf '%s\n' '{"require":{"php":"^8.4"}}' | sudo -u "$user" -H tee "$home/composer.json" >/dev/null
REMOTE
        output=$(orbit instance:new "$app_id" "$node_id" default --hostname=orb197-retry.test --json)
        assert_instance "$output" "$app_id" "$node_id" default orb197-retry.test main null null
        [[ "$(inspect_single orb197-retry | json_field provisioning_step)" == active ]]
        printf 'durable production checkpoint retry passed after Git became unavailable\n'
        ;;

    creation-after-deployment)
        [[ $# -eq 1 && "$(id -u)" -eq 1000 ]]
        app_id=$(fixture_app_id orb197-active)
        node_id=$(fixture_node_field app-prod id)
        first=$(orbit instance:new "$app_id" "$node_id" live --hostname=orb197-active.test --json)
        assert_instance "$first" "$app_id" "$node_id" live orb197-active.test main null null
        user=orbit-app-$app_id
        remote_script app-prod "$user" <<'REMOTE'
user=$1
home=/home/$user
sudo -u "$user" -H mv "$home/.git" "$home/.git.operator"
rm_target="$home/composer.operator"
sudo -u "$user" -H mv "$home/composer.json" "$rm_target"
sudo -u "$user" -H ln -s /tmp/orb197-invalid-composer "$home/composer.json"
REMOTE
        gateway_fixture rename orb197-active orb197-active-renamed >/dev/null
        second=$(orbit instance:new "$app_id" "$node_id" live --hostname=orb197-active.test --json)
        assert_instance "$second" "$app_id" "$node_id" live orb197-active.test main null null
        [[ "$(json_field id <<<"$first")" == "$(json_field id <<<"$second")" ]]
        [[ "$(json_field production_user <<<"$first")" == "$(json_field production_user <<<"$second")" ]]
        expect_error route.retry_conflict orbit instance:new "$app_id" "$node_id" live --hostname=orb197-active-changed.test
        [[ "$(inspect_single orb197-active | json_field status)" == active ]]
        printf 'active retry skipped changed Git and source-profile state\n'
        ;;

    standalone-exposure)
        [[ $# -eq 1 && "$(id -u)" -eq 1000 ]]
        create=$(inspect_single orb197-create)
        instance_id=$(json_field id <<<"$create")
        remote_script app-prod "$instance_id" <<'REMOTE'
instance=$1
live=$(readlink -f /etc/caddy/Caddyfile)
grep -Fq -- "orb197-create.test" "$(dirname "$live")/fragments/app-dev.caddy"
sudo test -s "/etc/caddy/orbit-certificates/app-instance-$instance/current/cert.pem"
status=$(sudo ufw status numbered)
grep -Fq 'operator:orb197' <<<"$status"
! grep -Fq 'orbit:app-prod-http' <<<"$status"
! grep -Fq 'orbit:app-prod-https' <<<"$status"
REMOTE
        curl --fail --silent --show-error --retry 10 --retry-delay 1 --cacert /home/orbit/.orbit/ca/root.pem --resolve orb197-create.test:443:10.44.0.3 https://orb197-create.test/ | grep -Fq orb197-create-web
        printf 'private Orbit-CA HTTPS and app-prod firewall retirement passed\n'
        ;;

    create-and-remove)
        [[ $# -eq 1 && "$(id -u)" -eq 1000 ]]
        app_id=$(fixture_app_id orb197-remove)
        node_id=$(fixture_node_field app-prod-2 id)
        created=$(orbit instance:new "$app_id" "$node_id" default --hostname=orb197-remove.test --json)
        assert_instance "$created" "$app_id" "$node_id" default orb197-remove.test main null null
        instance_id=$(json_field id <<<"$created")
        user=orbit-app-$app_id
        removed=$(orbit instance:remove "$instance_id" --json)
        php -r '
            $value=json_decode($argv[1],true,64,JSON_THROW_ON_ERROR);
            $removal=$value["removal"] ?? null;
            if(!is_array($removal) || ($removal["app_instance_id"] ?? null)!==(int)$argv[2] || ($removal["completed"] ?? null)!==($removal["total"] ?? null) || ($removal["remaining"] ?? null)!==0 || ($removal["failed_step"] ?? null)!==null || ($removal["error_code"] ?? null)!==null || !is_string($value["request_id"] ?? null) || $value["request_id"]==="") exit(65);
        ' "$removed" "$instance_id"
        gateway_fixture assert-removed orb197-remove >/dev/null
        remote_script app-prod-2 "$user" <<'REMOTE'
user=$1
home=/home/$user
getent passwd "$user" >/dev/null
test -d "$home"
test -d "$home/.git"
test "$(stat -c %U:%G "$home")" = "$user:$user"
test "$(sudo -u "$user" -H git -C "$home" remote get-url origin)" = https://localhost/orb197/remove.git
REMOTE
        printf 'production removal retained user, home, and initial source while deleting Route state\n'
        ;;

    *)
        exit 64
        ;;
esac

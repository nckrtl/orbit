#!/usr/bin/env bash
set -euo pipefail

scenario=${1:-}
repository=/home/orbit/orbit
gateway="$repository/apps/gateway"
fixture=/var/lib/orbit-e2e/proof/route-hostname-change.php
ssh_key=/home/orbit/.orbit/ssh/id_ed25519
known_hosts=/home/orbit/.orbit/ssh/known_hosts
ca=/home/orbit/.orbit/ca/root.pem

if [[ ! -f "$fixture" ]]; then
    fixture="$repository/.loop/proof/route-hostname-change.php"
fi

gateway_fixture() {
    (
        cd "$gateway"
        php "$fixture" "$@"
    )
}

json_field() {
    php -r '
        $value=json_decode(stream_get_contents(STDIN), true, 32, JSON_THROW_ON_ERROR);
        foreach(explode(".", $argv[1]) as $part) {
            if(!is_array($value) || !array_key_exists($part, $value)) exit(65);
            $value=$value[$part];
        }
        if(is_bool($value)) echo $value ? "true" : "false";
        elseif(is_int($value) || is_string($value)) echo $value;
        elseif($value===null) echo "null";
        else exit(65);
    ' "$1"
}

fixture_field() {
    gateway_fixture state | json_field "$1"
}

remote_command() {
    local node_ip=$1
    shift
    ssh \
        -i "$ssh_key" \
        -o BatchMode=yes \
        -o IdentitiesOnly=yes \
        -o StrictHostKeyChecking=yes \
        -o "UserKnownHostsFile=$known_hosts" \
        "orbit@$node_ip" \
        "$@"
}

remote_script() {
    local node_ip=$1
    shift
    remote_command "$node_ip" bash -seu -- "$@"
}

app_dev_route() {
    local node_ip=$1
    local route_id=$2
    remote_command "$node_ip" orbit route:show "$route_id" --json
}

assert_route() {
    local value=$1
    local hostname=$2
    local target=$3
    local direction=$4
    local step=$5
    local failed_step=$6
    local error_code=$7
    php -r '
        $value=json_decode($argv[1], true, 32, JSON_THROW_ON_ERROR);
        foreach(["id","hostname","status","failed_step","error_code","hostname_change_previous","hostname_change_target","hostname_change_direction","hostname_change_step","request_id"] as $key) {
            if(!array_key_exists($key, $value)) exit(65);
        }
        $expected=["hostname"=>$argv[2],"status"=>"active","hostname_change_target"=>$argv[3]==="null" ? null : $argv[3],"hostname_change_direction"=>$argv[4]==="null" ? null : $argv[4],"hostname_change_step"=>$argv[5]==="null" ? null : $argv[5],"failed_step"=>$argv[6]==="null" ? null : $argv[6],"error_code"=>$argv[7]==="null" ? null : $argv[7]];
        foreach($expected as $key=>$expectedValue) if(($value[$key] ?? null)!==$expectedValue) exit(65);
        if(!is_int($value["id"]) || !is_string($value["request_id"]) || $value["request_id"]==="") exit(65);
    ' "$value" "$hostname" "$target" "$direction" "$step" "$failed_step" "$error_code"
}

assert_dns_owner() {
    local hostname=$1
    local expected_ip=$2
    grep -Fxq "host-record=$hostname,$expected_ip" /etc/dnsmasq.d/orbit-records.conf
}

assert_dns_absent() {
    local hostname=$1
    ! grep -Fq "host-record=$hostname," /etc/dnsmasq.d/orbit-records.conf
}

update_route() {
    local route_id=$1
    local hostname=$2
    orbit route:update "$route_id" --hostname="$hostname" --json
}

restore_original_route() {
    local route_id=$1
    local original=$2
    local current
    current=$(fixture_field route.hostname)
    if [[ "$current" != "$original" ]]; then
        update_route "$route_id" "$original" >/dev/null
    fi
}

normalized_route() {
    local node_ip=$1
    local route_id=$2
    app_dev_route "$node_ip" "$route_id" | php -r '
        $value=json_decode(stream_get_contents(STDIN), true, 32, JSON_THROW_ON_ERROR);
        unset($value["request_id"]);
        ksort($value);
        echo json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), "\n";
    '
}

remote_tree_digest() {
    local node_ip=$1
    local path=$2
    remote_script "$node_ip" "$path" <<'REMOTE'
path=$1
sudo find "$path" -xdev -printf '%P\t%y\t%l\n' | LC_ALL=C sort
sudo find "$path" -xdev -type f -print0 | LC_ALL=C sort -z | sudo xargs -0 -r sha256sum
REMOTE
}

traffic_digest() {
    local hostname=$1
    local node_ip=$2
    local body
    local status
    body=$(mktemp)
    status=$(curl --silent --show-error --output "$body" --write-out '%{http_code}' --cacert "$ca" --resolve "$hostname:443:$node_ip" "https://$hostname/")
    printf '%s\t%s\n' "$status" "$(sha256sum "$body" | awk '{print $1}')"
    rm -f -- "$body"
}

refusal_snapshot() {
    local route_id=$1
    local occupied_route_id=$2
    local hostname=$3
    local checkout=$4
    local node_ip=$5
    printf 'route\t%s\n' "$(normalized_route "$node_ip" "$route_id")"
    printf 'occupied-route\t%s\n' "$(normalized_route "$node_ip" "$occupied_route_id")"
    printf 'laravel-url\t%s\n' "$(remote_command "$node_ip" grep -F 'APP_URL=' "$checkout/.env")"
    printf 'caddy-link\t%s\n' "$(remote_command "$node_ip" readlink -f /etc/caddy/Caddyfile)"
    printf 'caddy\n%s\n' "$(remote_tree_digest "$node_ip" /etc/caddy/orbit-versions)"
    printf 'certificates\n%s\n' "$(remote_tree_digest "$node_ip" /etc/caddy/orbit-certificates)"
    printf 'dns\t%s\n' "$(sha256sum /etc/dnsmasq.d/orbit-records.conf | awk '{print $1}')"
    printf 'traffic\t%s\n' "$(traffic_digest "$hostname" "$node_ip")"
}

assert_update_refused() {
    local route_id=$1
    local hostname=$2
    local error_code=$3
    local output
    local status
    set +e
    output=$(update_route "$route_id" "$hostname" 2>&1)
    status=$?
    set -e
    [[ "$status" -ne 0 ]]
    grep -Fq "$error_code" <<<"$output"
}

case "$scenario" in
    setup)
        [[ $# -eq 1 && "$(id -u)" -eq 1000 ]]
        (cd "$gateway" && php artisan migrate --force --no-interaction >/dev/null)
        state=$(gateway_fixture setup)
        route_id=$(json_field route.id <<<"$state")
        original=$(json_field original_hostname <<<"$state")
        checkout=$(json_field instance.checkout_path <<<"$state")
        node_ip=$(json_field instance.node_ip <<<"$state")
        [[ "$route_id" =~ ^[1-9][0-9]*$ && "$original" == e2e-dev.orbit && "$node_ip" == 10.44.0.2 ]]
        remote_script "$node_ip" "$checkout" <<'REMOTE'
checkout=$1
cd "$checkout"
DB_DATABASE="$checkout/database/database.sqlite" php -d auto_prepend_file= artisan migrate:fresh --force --no-interaction >/dev/null
REMOTE
        assert_route "$(app_dev_route "$node_ip" "$route_id")" "$original" null null null null null
        assert_dns_owner "$original" "$node_ip"
        curl --fail --silent --show-error --cacert "$ca" --resolve "$original:443:$node_ip" "https://$original/" >/dev/null
        printf 'active development Route fixture ready\n'
        ;;

    explicit-route-hostname-update)
        [[ $# -eq 1 && "$(id -u)" -eq 1000 ]]
        route_id=$(fixture_field route.id)
        original=$(fixture_field original_hostname)
        checkout=$(fixture_field instance.checkout_path)
        node_ip=$(fixture_field instance.node_ip)
        occupied_route_id=$(fixture_field occupied_route.id)
        occupied=$(fixture_field occupied_route.hostname)
        candidate=orb188-explicit.orbit
        trap 'restore_original_route "$route_id" "$original"' EXIT

        before_invalid=$(refusal_snapshot "$route_id" "$occupied_route_id" "$original" "$checkout" "$node_ip")
        assert_update_refused "$route_id" bad_name validation.failed
        after_invalid=$(refusal_snapshot "$route_id" "$occupied_route_id" "$original" "$checkout" "$node_ip")
        [[ "$after_invalid" == "$before_invalid" ]]

        before_occupied=$(refusal_snapshot "$route_id" "$occupied_route_id" "$original" "$checkout" "$node_ip")
        assert_update_refused "$route_id" "$occupied" route.hostname_conflict
        after_occupied=$(refusal_snapshot "$route_id" "$occupied_route_id" "$original" "$checkout" "$node_ip")
        [[ "$after_occupied" == "$before_occupied" ]]

        output=$(update_route "$route_id" "$candidate")
        assert_route "$output" "$candidate" null null null null null
        assert_route "$(app_dev_route "$node_ip" "$route_id")" "$candidate" null null null null null
        assert_dns_owner "$candidate" "$node_ip"
        assert_dns_absent "$original"
        curl --fail --silent --show-error --cacert "$ca" --resolve "$candidate:443:$node_ip" "https://$candidate/" >/dev/null
        restore_original_route "$route_id" "$original"
        trap - EXIT
        assert_dns_owner "$original" "$node_ip"
        assert_dns_absent "$candidate"
        printf 'invalid and occupied hostnames were side-effect free before explicit active Route convergence\n'
        ;;

    route-url-rollback)
        [[ $# -eq 1 && "$(id -u)" -eq 1000 ]]
        route_id=$(fixture_field route.id)
        original=$(fixture_field original_hostname)
        checkout=$(fixture_field instance.checkout_path)
        node_ip=$(fixture_field instance.node_ip)
        candidate=orb188-rollback.orbit
        trap 'gateway_fixture cutover-failure off >/dev/null; restore_original_route "$route_id" "$original"' EXIT
        gateway_fixture cutover-failure on >/dev/null
        set +e
        update_route "$route_id" "$candidate" >/tmp/orb188-rollback-output 2>&1
        status=$?
        set -e
        [[ "$status" -ne 0 ]]
        rolled_back=$(app_dev_route "$node_ip" "$route_id")
        assert_route "$rolled_back" "$original" "$candidate" rollback rolled-back database-cutover route.hostname_change_failed
        assert_dns_owner "$original" "$node_ip"
        assert_dns_absent "$candidate"
        remote_script "$node_ip" "$checkout" "$original" <<'REMOTE'
checkout=$1
hostname=$2
grep -Fxq "APP_URL=https://$hostname" "$checkout/.env"
REMOTE
        gateway_fixture cutover-failure off >/dev/null
        retried=$(update_route "$route_id" "$candidate")
        assert_route "$retried" "$candidate" null null null null null
        assert_route "$(app_dev_route "$node_ip" "$route_id")" "$candidate" null null null null null
        restore_original_route "$route_id" "$original"
        trap - EXIT
        printf 'database failure restored URL and DNS, then the same request resumed\n'
        ;;

    route-change-with-application-error)
        [[ $# -eq 1 && "$(id -u)" -eq 1000 ]]
        route_id=$(fixture_field route.id)
        original=$(fixture_field original_hostname)
        checkout=$(fixture_field instance.checkout_path)
        node_ip=$(fixture_field instance.node_ip)
        candidate=orb188-error.orbit
        backup=/home/orbit/.orbit/orb188-index.php
        remote_script "$node_ip" "$checkout" "$backup" <<'REMOTE'
checkout=$1
backup=$2
cp -a -- "$checkout/public/index.php" "$backup"
cat > "$checkout/public/index.php" <<'PHP'
<?php
http_response_code(500);
echo "orb188-application-error\n";
PHP
REMOTE
        trap 'remote_script "$node_ip" "$checkout" "$backup" <<'"'"'REMOTE'"'"'
checkout=$1
backup=$2
cp -a -- "$backup" "$checkout/public/index.php"
rm -f -- "$backup"
REMOTE
restore_original_route "$route_id" "$original"' EXIT
        before_status=$(curl --silent --show-error --output /tmp/orb188-before-body --write-out '%{http_code}' --cacert "$ca" --resolve "$original:443:$node_ip" "https://$original/")
        [[ "$before_status" == 500 ]]
        grep -Fxq orb188-application-error /tmp/orb188-before-body
        output=$(update_route "$route_id" "$candidate")
        assert_route "$output" "$candidate" null null null null null
        assert_route "$(app_dev_route "$node_ip" "$route_id")" "$candidate" null null null null null
        after_status=$(curl --silent --show-error --output /tmp/orb188-after-body --write-out '%{http_code}' --cacert "$ca" --resolve "$candidate:443:$node_ip" "https://$candidate/")
        [[ "$after_status" == 500 ]]
        grep -Fxq orb188-application-error /tmp/orb188-after-body
        remote_script "$node_ip" "$checkout" "$backup" <<'REMOTE'
checkout=$1
backup=$2
cp -a -- "$backup" "$checkout/public/index.php"
rm -f -- "$backup"
REMOTE
        restore_original_route "$route_id" "$original"
        trap - EXIT
        printf 'hostname cutover accepted a serving application HTTP 500\n'
        ;;

    route-url-without-framework-boot)
        [[ $# -eq 1 && "$(id -u)" -eq 1000 ]]
        route_id=$(fixture_field route.id)
        original=$(fixture_field original_hostname)
        checkout=$(fixture_field instance.checkout_path)
        node_ip=$(fixture_field instance.node_ip)
        candidate=orb188-no-boot.orbit
        backup=/home/orbit/.orbit/orb188-framework
        remote_script "$node_ip" "$checkout" "$backup" <<'REMOTE'
checkout=$1
backup=$2
rm -rf -- "$backup"
mkdir -m 0700 -- "$backup"
cp -a -- "$checkout/artisan" "$backup/artisan"
cp -a -- "$checkout/bootstrap/app.php" "$backup/app.php"
rm -f -- /tmp/orb188-artisan-ran /tmp/orb188-bootstrap-ran
cat > "$checkout/artisan" <<'PHP'
#!/usr/bin/env php
<?php
file_put_contents('/tmp/orb188-artisan-ran', "ran\n");
exit(97);
PHP
chmod --reference="$backup/artisan" "$checkout/artisan"
cat > "$checkout/bootstrap/app.php" <<'PHP'
<?php
file_put_contents('/tmp/orb188-bootstrap-ran', "ran\n");
throw new RuntimeException('ORB-188 framework boot is unavailable.');
PHP
chmod --reference="$backup/app.php" "$checkout/bootstrap/app.php"
REMOTE
        trap 'remote_script "$node_ip" "$checkout" "$backup" <<'"'"'REMOTE'"'"'
checkout=$1
backup=$2
cp -a -- "$backup/artisan" "$checkout/artisan"
cp -a -- "$backup/app.php" "$checkout/bootstrap/app.php"
rm -rf -- "$backup"
REMOTE
restore_original_route "$route_id" "$original"' EXIT
        output=$(update_route "$route_id" "$candidate")
        assert_route "$output" "$candidate" null null null null null
        assert_route "$(app_dev_route "$node_ip" "$route_id")" "$candidate" null null null null null
        remote_script "$node_ip" "$checkout" "$candidate" <<'REMOTE'
checkout=$1
hostname=$2
test ! -e /tmp/orb188-artisan-ran
test ! -e /tmp/orb188-bootstrap-ran
grep -Fxq "APP_URL=https://$hostname" "$checkout/.env"
REMOTE
        remote_script "$node_ip" "$checkout" "$backup" <<'REMOTE'
checkout=$1
backup=$2
cp -a -- "$backup/artisan" "$checkout/artisan"
cp -a -- "$backup/app.php" "$checkout/bootstrap/app.php"
rm -rf -- "$backup"
REMOTE
        restore_original_route "$route_id" "$original"
        trap - EXIT
        printf 'Laravel URL changed without Composer, Artisan, or framework boot\n'
        ;;

    *)
        exit 64
        ;;
esac

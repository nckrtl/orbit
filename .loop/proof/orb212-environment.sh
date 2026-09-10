#!/usr/bin/env bash
set -euo pipefail

scenario=${1:-}
repository=/home/orbit/orbit
gateway="$repository/apps/gateway"
fixture=/var/lib/orbit-e2e/proof/orb212-environment.php

if [[ ! -f "$fixture" ]]; then
    fixture="$repository/.loop/proof/orb212-environment.php"
fi

gateway_fixture() {
    (cd "$gateway" && php "$fixture" "$@")
}

node_ip() {
    gateway_fixture node-ip "$1"
}

remote_node() {
    local node=$1
    shift
    ssh -i /home/orbit/.orbit/ssh/id_ed25519 \
        -o UserKnownHostsFile=/home/orbit/.orbit/ssh/known_hosts \
        -o BatchMode=yes -o StrictHostKeyChecking=yes \
        -- "orbit@$(node_ip "$node")" bash -seu -- "$@"
}

probe_app_dev_cli() {
    remote_node app-dev <<'REMOTE'
orbit node:list --json | php -r '$value=json_decode(stream_get_contents(STDIN),true,32,JSON_THROW_ON_ERROR); if(!is_array($value["nodes"]??null)) exit(65);'
REMOTE
}

api_request() {
    local label=$1 selector=$2 method=$3 body=$4
    test "$label" = development -o "$label" = production
    remote_node app-dev "$selector" "$method" "$body" <<'REMOTE'
selector=$1
method=$2
body=$3
curl --insecure --silent --show-error \
    --resolve gateway.orbit:443:10.44.0.1 \
    -H 'Content-Type: application/json' \
    -X "$method" --data-binary "$body" \
    "https://gateway.orbit/api/v1/instances/$selector/environment${API_SUFFIX:-/sync}"
REMOTE
}

sync_instance() {
    api_request "$1" "$2" POST '{}'
}

update_instance() {
    local label=$1 selector=$2 key=$3 value=$4
    test "$label" = development -o "$label" = production
    remote_node app-dev "$selector" "$key" "$value" <<'REMOTE'
selector=$1
key=$2
value=$3
body=$(php -r 'echo json_encode(["value"=>$argv[1]], JSON_THROW_ON_ERROR);' "$value")
curl --insecure --silent --show-error \
    --resolve gateway.orbit:443:10.44.0.1 \
    -H 'Content-Type: application/json' \
    -X PUT --data-binary "$body" \
    "https://gateway.orbit/api/v1/instances/$selector/environment/$key"
REMOTE
}

assert_success() {
    local response=$1 changed=$2 count=$3
    RESPONSE="$response" php -r '
        $v=json_decode(getenv("RESPONSE"),true,32,JSON_THROW_ON_ERROR);
        if(array_keys($v)!==["data","meta"] || array_keys($v["data"]??[])!==["app_instance_id","operation","changed","key_count"] || array_keys($v["meta"]??[])!==["request_id"] || !is_int($v["data"]["app_instance_id"]??null) || ($v["data"]["operation"]??null)!=="sync" || ($v["data"]["changed"]??null)!==filter_var($argv[1],FILTER_VALIDATE_BOOL) || ($v["data"]["key_count"]??null)!==(int)$argv[2] || !is_string($v["meta"]["request_id"]??null)) exit(65);
    ' "$changed" "$count"
}

assert_update_success() {
    local response=$1
    RESPONSE="$response" php -r '$v=json_decode(getenv("RESPONSE"),true,32,JSON_THROW_ON_ERROR); if(($v["data"]["operation"]??null)!=="update" || ($v["data"]["changed"]??null)!==true) exit(65);'
}

assert_error() {
    local response=$1 code=$2
    RESPONSE="$response" php -r '$v=json_decode(getenv("RESPONSE"),true,32,JSON_THROW_ON_ERROR); if(($v["error"]["code"]??null)!==$argv[1]) exit(65);' "$code"
}

cleanup_remote_files() {
    remote_node app-dev <<'REMOTE'
sudo rm -rf -- /home/orbit/orb212-environment-dev
REMOTE
    remote_node app-prod <<'REMOTE'
sudo rm -rf -- /home/orbit-app-orb212
if getent passwd orbit-app-orb212 >/dev/null; then sudo userdel orbit-app-orb212; fi
REMOTE
}

setup_remote_files() {
    remote_node app-dev <<'REMOTE'
sudo install -d -o orbit -g orbit -m 0700 /home/orbit/orb212-environment-dev
printf 'LOCAL_ONLY=development-before\n' > /home/orbit/orb212-environment-dev/.env
chmod 0600 /home/orbit/orb212-environment-dev/.env
REMOTE
    remote_node app-prod <<'REMOTE'
if getent passwd orbit-app-orb212 >/dev/null; then
    sudo usermod -d /home/orbit-app-orb212 -s /usr/sbin/nologin orbit-app-orb212
else
    sudo useradd -M -d /home/orbit-app-orb212 -s /usr/sbin/nologin orbit-app-orb212
fi
sudo install -d -o orbit-app-orb212 -g orbit-app-orb212 -m 0700 /home/orbit-app-orb212
printf 'LOCAL_ONLY=production-before\n' | sudo tee /home/orbit-app-orb212/.env >/dev/null
sudo chown orbit-app-orb212:orbit-app-orb212 /home/orbit-app-orb212/.env
sudo chmod 0600 /home/orbit-app-orb212/.env
REMOTE
}

case "$scenario" in
    setup)
        [[ $# -eq 1 && "$(id -u)" -eq 1000 ]]
        (cd "$gateway" && php artisan migrate --force --no-interaction >/dev/null)
        cleanup_remote_files
        setup_remote_files
        gateway_fixture setup
        probe_app_dev_cli
        remote_node app-dev <<'REMOTE'
curl --insecure --fail --silent --show-error --resolve gateway.orbit:443:10.44.0.1 https://gateway.orbit/up >/dev/null
REMOTE
        ;;
    setup-observations)
        [[ $# -eq 1 && "$(id -u)" -eq 1000 ]]
        orbit node:list --json | php -r '$v=json_decode(stream_get_contents(STDIN),true,32,JSON_THROW_ON_ERROR); if(!is_array($v["nodes"]??null)) exit(65);'
        curl --fail --silent --show-error https://gateway.orbit/up >/dev/null
        ;;
    environment-sync-placement-and-preflight)
        [[ $# -eq 1 && "$(id -u)" -eq 1000 ]]
        probe_app_dev_cli
        gateway_fixture reset-values development baseline
        gateway_fixture reset-values production baseline
        remote_node app-dev <<'REMOTE'
printf 'LOCAL_ONLY=placement-reset\n' > /home/orbit/orb212-environment-dev/.env
chmod 0600 /home/orbit/orb212-environment-dev/.env
REMOTE
        remote_node app-prod <<'REMOTE'
printf 'LOCAL_ONLY=placement-reset\n' | sudo tee /home/orbit-app-orb212/.env >/dev/null
sudo chown orbit-app-orb212:orbit-app-orb212 /home/orbit-app-orb212/.env
sudo chmod 0600 /home/orbit-app-orb212/.env
REMOTE
        test "$(gateway_fixture describe development)" = 'development:app-dev:orbit:/home/orbit/orb212-environment-dev'
        test "$(gateway_fixture describe production)" = 'production:app-prod:orbit-app-orb212:/home/orbit-app-orb212'
        dev_id=$(gateway_fixture id development)
        prod_hostname=$(gateway_fixture hostname production)
        response=$(sync_instance development "$dev_id")
        assert_success "$response" true 2
        response=$(sync_instance production "$prod_hostname")
        assert_success "$response" true 2
        remote_node app-dev <<'REMOTE'
test "$(stat -c '%U:%G:%a' /home/orbit/orb212-environment-dev/.env)" = orbit:orbit:600
REMOTE
        remote_node app-prod <<'REMOTE'
test "$(getent passwd orbit-app-orb212 | cut -d: -f6-7)" = /home/orbit-app-orb212:/usr/sbin/nologin
test "$(sudo -u orbit-app-orb212 stat -c '%U:%G:%a' /home/orbit-app-orb212/.env)" = orbit-app-orb212:orbit-app-orb212:600
REMOTE
        before=$(remote_node app-dev <<'REMOTE'
sha256sum /home/orbit/orb212-environment-dev/.env | cut -d ' ' -f 1
REMOTE
)
        gateway_fixture corrupt-values development
        remote_node app-dev <<'REMOTE'
chmod 0500 /home/orbit/orb212-environment-dev
chmod 0400 /home/orbit/orb212-environment-dev/.env
REMOTE
        response=$(sync_instance development "$dev_id")
        assert_error "$response" env.write_preflight_failed
        remote_node app-dev <<'REMOTE'
chmod 0700 /home/orbit/orb212-environment-dev
chmod 0600 /home/orbit/orb212-environment-dev/.env
REMOTE
        response=$(sync_instance development "$dev_id")
        assert_error "$response" env.configuration_unreadable
        test "$(remote_node app-dev <<'REMOTE'
sha256sum /home/orbit/orb212-environment-dev/.env | cut -d ' ' -f 1
REMOTE
)" = "$before"
        gateway_fixture delete-values development
        response=$(sync_instance development "$dev_id")
        assert_error "$response" env.configuration_missing
        gateway_fixture reset-values development baseline
        ;;
    environment-sync-complete-file)
        [[ $# -eq 1 && "$(id -u)" -eq 1000 ]]
        probe_app_dev_cli
        gateway_fixture reset-values development complete
        gateway_fixture reset-values production complete
        before_dev=$(gateway_fixture storage-digest development)
        before_prod=$(gateway_fixture storage-digest production)
        remote_node app-dev <<'REMOTE'
printf 'LOCAL_ONLY=remove-me\nAPP_KEY=local-edit\n' > /home/orbit/orb212-environment-dev/.env
chmod 0644 /home/orbit/orb212-environment-dev/.env
REMOTE
        remote_node app-prod <<'REMOTE'
sudo rm -f /home/orbit-app-orb212/.env
REMOTE
        dev_id=$(gateway_fixture id development)
        prod_hostname=$(gateway_fixture hostname production)
        dev_response=$(sync_instance development "$dev_id")
        prod_response=$(sync_instance production "$prod_hostname")
        assert_success "$dev_response" true 6
        assert_success "$prod_response" true 6
        [[ "$dev_response$prod_response" != *complete-proof-sentinel* ]]
        remote_node app-dev <<'REMOTE'
expected=$(mktemp)
trap 'rm -f "$expected"' EXIT
cat >"$expected" <<'ENV'
APP_ENV="development"
APP_KEY="complete-proof-sentinel"
APP_URL="https://orb212-development.orbit"
EMPTY=""
LITERAL="dollar \$ backslash \\ quote \""
MULTILINE="line one\nline two"
ENV
cmp "$expected" /home/orbit/orb212-environment-dev/.env
test "$(stat -c '%U:%G:%a' /home/orbit/orb212-environment-dev/.env)" = orbit:orbit:600
REMOTE
        remote_node app-prod <<'REMOTE'
expected=$(mktemp)
trap 'rm -f "$expected"' EXIT
cat >"$expected" <<'ENV'
APP_ENV="production"
APP_KEY="complete-proof-sentinel"
APP_URL="https://orb212-production.orbit"
EMPTY=""
LITERAL="dollar \$ backslash \\ quote \""
MULTILINE="line one\nline two"
ENV
sudo cmp "$expected" /home/orbit-app-orb212/.env
test "$(sudo -u orbit-app-orb212 stat -c '%U:%G:%a' /home/orbit-app-orb212/.env)" = orbit-app-orb212:orbit-app-orb212:600
REMOTE
        test "$(gateway_fixture storage-digest development)" = "$before_dev"
        test "$(gateway_fixture storage-digest production)" = "$before_prod"
        gateway_fixture assert-no-activity-secret complete-proof-sentinel
        ;;
    environment-sync-retry-and-repeat)
        [[ $# -eq 1 && "$(id -u)" -eq 1000 ]]
        probe_app_dev_cli
        gateway_fixture reset-values development retry
        dev_id=$(gateway_fixture id development)
        remote_node app-dev <<'REMOTE'
printf 'KEY=stale\n' > /home/orbit/orb212-environment-dev/.env
chmod 0600 /home/orbit/orb212-environment-dev/.env
REMOTE
        response=$(sync_instance development "$dev_id")
        assert_success "$response" true 2
        inode=$(remote_node app-dev <<'REMOTE'
stat -c %i /home/orbit/orb212-environment-dev/.env
REMOTE
)
        response=$(sync_instance development "$dev_id")
        assert_success "$response" false 2
        test "$(remote_node app-dev <<'REMOTE'
stat -c %i /home/orbit/orb212-environment-dev/.env
REMOTE
)" = "$inode"
        remote_node app-dev <<'REMOTE'
chmod 0644 /home/orbit/orb212-environment-dev/.env
REMOTE
        response=$(sync_instance development "$dev_id")
        assert_success "$response" true 2
        remote_node app-dev <<'REMOTE'
test "$(stat -c %a /home/orbit/orb212-environment-dev/.env)" = 600
REMOTE
        update_response=$(update_instance development "$dev_id" KEY changed)
        assert_update_success "$update_response"
        response=$(sync_instance development "$dev_id")
        assert_success "$response" true 2
        remote_node app-dev <<'REMOTE'
test "$(cat /home/orbit/orb212-environment-dev/.env)" = $'APP_KEY="retry-proof-key"\nKEY="changed"'
REMOTE
        ;;
    environment-sync-without-application-bootstrap)
        [[ $# -eq 1 && "$(id -u)" -eq 1000 ]]
        probe_app_dev_cli
        gateway_fixture reset-values development bootstrap
        gateway_fixture reset-values production bootstrap
        remote_node app-dev <<'REMOTE'
path=/home/orbit/orb212-environment-dev
rm -rf -- "$path/artisan" "$path/vendor" "$path/composer.json" "$path/database"
mkdir -p "$path/.git" "$path/bootstrap/cache"
printf source > "$path/source.php"
printf git > "$path/.git/HEAD"
printf cache > "$path/bootstrap/cache/config.php"
printf database > "$path/application.sqlite"
REMOTE
        remote_node app-prod <<'REMOTE'
path=/home/orbit-app-orb212
sudo rm -rf -- "$path/artisan" "$path/vendor" "$path/composer.json" "$path/database"
sudo install -d -o orbit-app-orb212 -g orbit-app-orb212 -m 0700 "$path/.git" "$path/bootstrap/cache"
printf source | sudo -u orbit-app-orb212 tee "$path/source.php" >/dev/null
printf git | sudo -u orbit-app-orb212 tee "$path/.git/HEAD" >/dev/null
printf cache | sudo -u orbit-app-orb212 tee "$path/bootstrap/cache/config.php" >/dev/null
printf database | sudo -u orbit-app-orb212 tee "$path/application.sqlite" >/dev/null
REMOTE
        before=$(remote_node app-dev <<'REMOTE'
path=/home/orbit/orb212-environment-dev
printf '%s:%s:%s:%s:%s:%s\n' "$(sha256sum "$path/source.php" | cut -d ' ' -f1)" "$(sha256sum "$path/.git/HEAD" | cut -d ' ' -f1)" "$(sha256sum "$path/bootstrap/cache/config.php" | cut -d ' ' -f1)" "$(sha256sum "$path/application.sqlite" | cut -d ' ' -f1)" "$(systemctl show -p MainPID --value php8.5-fpm)" "$(systemctl show -p MainPID --value caddy)"
REMOTE
)
        prod_before=$(remote_node app-prod <<'REMOTE'
path=/home/orbit-app-orb212
printf '%s:%s:%s:%s:%s:%s\n' "$(sudo -u orbit-app-orb212 sha256sum "$path/source.php" | cut -d ' ' -f1)" "$(sudo -u orbit-app-orb212 sha256sum "$path/.git/HEAD" | cut -d ' ' -f1)" "$(sudo -u orbit-app-orb212 sha256sum "$path/bootstrap/cache/config.php" | cut -d ' ' -f1)" "$(sudo -u orbit-app-orb212 sha256sum "$path/application.sqlite" | cut -d ' ' -f1)" "$(systemctl show -p MainPID --value php8.5-fpm)" "$(systemctl show -p MainPID --value caddy)"
REMOTE
)
        dev_response=$(sync_instance development "$(gateway_fixture id development)")
        prod_response=$(sync_instance production "$(gateway_fixture hostname production)")
        assert_success "$dev_response" true 2
        assert_success "$prod_response" true 2
        test "$(remote_node app-dev <<'REMOTE'
path=/home/orbit/orb212-environment-dev
printf '%s:%s:%s:%s:%s:%s\n' "$(sha256sum "$path/source.php" | cut -d ' ' -f1)" "$(sha256sum "$path/.git/HEAD" | cut -d ' ' -f1)" "$(sha256sum "$path/bootstrap/cache/config.php" | cut -d ' ' -f1)" "$(sha256sum "$path/application.sqlite" | cut -d ' ' -f1)" "$(systemctl show -p MainPID --value php8.5-fpm)" "$(systemctl show -p MainPID --value caddy)"
REMOTE
)" = "$before"
        test "$(remote_node app-prod <<'REMOTE'
path=/home/orbit-app-orb212
printf '%s:%s:%s:%s:%s:%s\n' "$(sudo -u orbit-app-orb212 sha256sum "$path/source.php" | cut -d ' ' -f1)" "$(sudo -u orbit-app-orb212 sha256sum "$path/.git/HEAD" | cut -d ' ' -f1)" "$(sudo -u orbit-app-orb212 sha256sum "$path/bootstrap/cache/config.php" | cut -d ' ' -f1)" "$(sudo -u orbit-app-orb212 sha256sum "$path/application.sqlite" | cut -d ' ' -f1)" "$(systemctl show -p MainPID --value php8.5-fpm)" "$(systemctl show -p MainPID --value caddy)"
REMOTE
)" = "$prod_before"
        gateway_fixture cleanup
        cleanup_remote_files
        gateway_fixture assert-clean
        ;;
    *)
        printf 'Unknown ORB-212 proof scenario: %s\n' "$scenario" >&2
        exit 64
        ;;
esac

printf 'ORB-212 %s: ok\n' "$scenario"

#!/usr/bin/env bash
set -euo pipefail

scenario=${1:-}
repository=/home/orbit/orbit
gateway="$repository/apps/gateway"
fixture=/var/lib/orbit-e2e/proof/orb207-environment.php

if [[ ! -f "$fixture" ]]; then
    fixture="$repository/.loop/proof/orb207-environment.php"
fi

gateway_fixture() {
    (
        cd "$gateway"
        php "$fixture" "$@"
    )
}

node_ip() {
    gateway_fixture node-ip "$1"
}

remote_node() {
    local node=$1
    shift
    ssh \
        -i /home/orbit/.orbit/ssh/id_ed25519 \
        -o UserKnownHostsFile=/home/orbit/.orbit/ssh/known_hosts \
        -o BatchMode=yes \
        -o StrictHostKeyChecking=yes \
        -- "orbit@$(node_ip "$node")" bash -seu -- "$@"
}

api_call() {
    local method=$1 endpoint=$2 payload=$3 response
    response=$(mktemp)
    HTTP_STATUS=$(curl \
        --insecure \
        --silent \
        --show-error \
        --resolve gateway.orbit:443:10.44.0.1 \
        --request "$method" \
        --header 'Content-Type: application/json' \
        --data-binary "$payload" \
        --output "$response" \
        --write-out '%{http_code}' \
        "https://gateway.orbit/api/v1/$endpoint")
    HTTP_BODY=$(cat "$response")
    rm -f -- "$response"
}

expect_success() {
    local method=$1 endpoint=$2 payload=$3 operation=$4 changed=$5 count=$6 id=$7
    api_call "$method" "$endpoint" "$payload"
    test "$HTTP_STATUS" = 200
    php -r '
        $value=json_decode($argv[1], true, 32, JSON_THROW_ON_ERROR);
        $data=$value["data"] ?? null;
        $meta=$value["meta"] ?? null;
        if(!is_array($data) || array_keys($data)!==["app_instance_id","operation","changed","key_count"] || ($data["app_instance_id"] ?? null)!==(int)$argv[2] || ($data["operation"] ?? null)!==$argv[3] || ($data["changed"] ?? null)!==($argv[4]==="true") || ($data["key_count"] ?? null)!==(int)$argv[5] || !is_array($meta) || !is_string($meta["request_id"] ?? null) || $meta["request_id"]==="") exit(65);
    ' "$HTTP_BODY" "$id" "$operation" "$changed" "$count"
}

expect_error() {
    local method=$1 endpoint=$2 payload=$3 status=$4 code=$5
    api_call "$method" "$endpoint" "$payload"
    test "$HTTP_STATUS" = "$status"
    php -r '
        $value=json_decode($argv[1], true, 32, JSON_THROW_ON_ERROR);
        $error=$value["error"] ?? null;
        if(!is_array($error) || ($error["code"] ?? null)!==$argv[2] || !is_string($error["message"] ?? null) || $error["message"]==="") exit(65);
    ' "$HTTP_BODY" "$code"
    ! grep -Eq 'dev-import|prod-import|dev-store|prod-store|dev-offline|prod-offline' <<<"$HTTP_BODY"
}

probe_app_dev_cli() {
    remote_node app-dev <<'REMOTE'
orbit node:list --json | php -r '$value=json_decode(stream_get_contents(STDIN),true,32,JSON_THROW_ON_ERROR); if(!is_array($value["nodes"]??null)) exit(65);'
REMOTE
}

setup_remote_files() {
    remote_node app-dev <<'REMOTE'
for path in /home/orbit/orb207-placement-dev /home/orbit/orb207-store-dev; do
    sudo rm -rf -- "$path"
    sudo install -d -o orbit -g orbit -m 0700 "$path"
done
cat > /home/orbit/orb207-placement-dev/.env <<'ENV'
DEV_LITERAL=dev-import
EXPANDED=${DEV_LITERAL}-expanded
ENV
cat > /home/orbit/orb207-store-dev/.env <<'ENV'
DEV_FILE=dev-store
ENV
chmod 0400 /home/orbit/orb207-placement-dev/.env /home/orbit/orb207-store-dev/.env
REMOTE

    remote_node app-prod <<'REMOTE'
for name in orbit-app-orb207-placement orbit-app-orb207-store; do
    home="/home/$name"
    sudo rm -rf -- "$home"
    if getent passwd "$name" >/dev/null; then
        sudo usermod -d "$home" -s /usr/sbin/nologin "$name"
    else
        sudo useradd -M -d "$home" -s /usr/sbin/nologin "$name"
    fi
    sudo install -d -o "$name" -g "$name" -m 0500 "$home"
done
printf 'PROD_LITERAL=prod-import\n' | sudo tee /home/orbit-app-orb207-placement/.env >/dev/null
printf 'PROD_FILE=prod-store\n' | sudo tee /home/orbit-app-orb207-store/.env >/dev/null
sudo chown orbit-app-orb207-placement:orbit-app-orb207-placement /home/orbit-app-orb207-placement/.env
sudo chown orbit-app-orb207-store:orbit-app-orb207-store /home/orbit-app-orb207-store/.env
sudo chmod 0400 /home/orbit-app-orb207-placement/.env /home/orbit-app-orb207-store/.env
REMOTE
}

cleanup_remote_files() {
    remote_node app-dev <<'REMOTE'
sudo rm -rf \
    /home/orbit/orb207-placement-dev \
    /home/orbit/orb207-placement-dev-target \
    /home/orbit/orb207-store-dev
REMOTE

    remote_node app-prod <<'REMOTE'
for name in orbit-app-orb207-placement orbit-app-orb207-store; do
    sudo rm -rf -- "/home/$name"
    if getent passwd "$name" >/dev/null; then
        sudo userdel "$name"
    fi
done
REMOTE
}

case "$scenario" in
    setup)
        [[ $# -eq 1 && "$(id -u)" -eq 1000 ]]
        (cd "$gateway" && php artisan migrate --force --no-interaction >/dev/null)
        cleanup_remote_files
        setup_remote_files
        gateway_fixture setup
        curl --insecure --fail --silent --show-error \
            --resolve gateway.orbit:443:10.44.0.1 \
            https://gateway.orbit/up >/dev/null
        ;;

    setup-observations)
        [[ $# -eq 1 && "$(id -u)" -eq 1000 ]]
        orbit node:list --json | php -r '$value=json_decode(stream_get_contents(STDIN),true,32,JSON_THROW_ON_ERROR); if(!is_array($value["nodes"]??null)) exit(65);'
        curl --fail --silent --show-error https://gateway.orbit/up >/dev/null
        ;;

    environment-import-placement)
        [[ $# -eq 1 && "$(id -u)" -eq 1000 ]]
        probe_app_dev_cli
        dev_id=$(gateway_fixture id placement-dev)
        prod_id=$(gateway_fixture id placement-prod)
        dev_path=/home/orbit/orb207-placement-dev

        dev_before=$(remote_node app-dev "$dev_path" <<'REMOTE'
sha256sum "$1/.env" | cut -d ' ' -f 1
REMOTE
)
        expect_success POST instances/orb207-placement-dev.orbit/environment/import '{}' import true 2 "$dev_id"
        test "$(remote_node app-dev "$dev_path" <<'REMOTE'
sha256sum "$1/.env" | cut -d ' ' -f 1
REMOTE
)" = "$dev_before"
        gateway_fixture assert-values placement-dev

        prod_before=$(remote_node app-prod <<'REMOTE'
test "$(getent passwd orbit-app-orb207-placement | cut -d: -f6-7)" = /home/orbit-app-orb207-placement:/usr/sbin/nologin
sudo -u orbit-app-orb207-placement -H sha256sum /home/orbit-app-orb207-placement/.env | cut -d ' ' -f 1
REMOTE
)
        expect_success POST "instances/$prod_id/environment/import" '{}' import true 1 "$prod_id"
        test "$(remote_node app-prod <<'REMOTE'
sudo -u orbit-app-orb207-placement -H sha256sum /home/orbit-app-orb207-placement/.env | cut -d ' ' -f 1
REMOTE
)" = "$prod_before"
        gateway_fixture assert-values placement-prod

        remote_node app-dev "$dev_path" <<'REMOTE'
mv "$1/.env" "$1/.env.saved"
printf 'FALLBACK=must-not-import\n' > "$1/.env.example"
REMOTE
        expect_error POST "instances/$dev_id/environment/import" '{}' 409 env.import_preflight_failed
        remote_node app-dev "$dev_path" <<'REMOTE'
test -f "$1/.env.example"
rm -f "$1/.env.example"
ln -s .env.saved "$1/.env"
REMOTE
        expect_error POST "instances/$dev_id/environment/import" '{}' 409 env.import_preflight_failed
        remote_node app-dev "$dev_path" <<'REMOTE'
rm "$1/.env"
mkfifo "$1/.env"
REMOTE
        expect_error POST "instances/$dev_id/environment/import" '{}' 409 env.import_preflight_failed
        remote_node app-dev "$dev_path" <<'REMOTE'
rm "$1/.env"
cp "$1/.env.saved" "$1/.env"
sudo chown root:root "$1/.env"
REMOTE
        expect_error POST "instances/$dev_id/environment/import" '{}' 409 env.import_preflight_failed
        remote_node app-dev "$dev_path" <<'REMOTE'
sudo chown orbit:orbit "$1/.env"
chmod 0000 "$1/.env"
REMOTE
        expect_error POST "instances/$dev_id/environment/import" '{}' 409 env.import_preflight_failed
        remote_node app-dev "$dev_path" <<'REMOTE'
rm "$1/.env"
python3 -c 'import sys; from pathlib import Path; Path(sys.argv[1], ".env").write_bytes(b"x" * 1048577)' "$1"
REMOTE
        expect_error POST "instances/$dev_id/environment/import" '{}' 409 env.import_preflight_failed
        remote_node app-dev "$dev_path" <<'REMOTE'
rm "$1/.env"
mv "$1/.env.saved" "$1/.env"
chmod 0400 "$1/.env"
mv "$1" "$1-target"
ln -s "$1-target" "$1"
REMOTE
        expect_error POST "instances/$dev_id/environment/import" '{}' 409 env.import_preflight_failed
        remote_node app-dev "$dev_path" <<'REMOTE'
rm "$1"
mv "$1-target" "$1"
REMOTE

        gateway_fixture set-path placement-dev /home/orbit/orb207-placement-dev/../orb207-placement-dev
        expect_error POST "instances/$dev_id/environment/import" '{}' 409 env.owner_unavailable
        gateway_fixture set-path placement-dev "$dev_path"
        gateway_fixture set-node-user app-dev 'INVALID USER'
        expect_error POST "instances/$dev_id/environment/import" '{}' 409 env.owner_unavailable
        gateway_fixture set-node-user app-dev orbit
        gateway_fixture set-node-user app-dev missing-orb207-user
        expect_error POST "instances/$dev_id/environment/import" '{}' 409 env.import_preflight_failed
        gateway_fixture set-node-user app-dev orbit

        remote_node app-dev "$dev_path" <<'REMOTE'
chmod 0500 "$1"
chmod 0400 "$1/.env"
REMOTE
        expect_success POST "instances/$dev_id/environment/import" '{"replace":true}' import false 2 "$dev_id"

        gateway_fixture cleanup placement
        remote_node app-dev <<'REMOTE'
sudo rm -rf /home/orbit/orb207-placement-dev /home/orbit/orb207-placement-dev-target
REMOTE
        remote_node app-prod <<'REMOTE'
sudo rm -rf /home/orbit-app-orb207-placement
if getent passwd orbit-app-orb207-placement >/dev/null; then sudo userdel orbit-app-orb207-placement; fi
REMOTE
        gateway_fixture assert-clean placement
        ;;

    environment-store-without-application-bootstrap)
        [[ $# -eq 1 && "$(id -u)" -eq 1000 ]]
        probe_app_dev_cli
        dev_id=$(gateway_fixture id store-dev)
        prod_id=$(gateway_fixture id store-prod)

        dev_snapshot=$(remote_node app-dev <<'REMOTE'
path=/home/orbit/orb207-store-dev
test -f "$path/.env"
test ! -e "$path/artisan"
test ! -e "$path/vendor"
test ! -e "$path/composer.json"
test ! -e "$path/database"
printf '%s:%s:%s\n' "$(sha256sum "$path/.env" | cut -d ' ' -f 1)" "$(systemctl is-active php8.5-fpm)" "$(systemctl is-active caddy)"
REMOTE
)
        prod_snapshot=$(remote_node app-prod <<'REMOTE'
path=/home/orbit-app-orb207-store
sudo -u orbit-app-orb207-store -H test -f "$path/.env"
sudo test ! -e "$path/artisan"
sudo test ! -e "$path/vendor"
sudo test ! -e "$path/composer.json"
sudo test ! -e "$path/database"
printf '%s:%s:%s\n' "$(sudo -u orbit-app-orb207-store -H sha256sum "$path/.env" | cut -d ' ' -f 1)" "$(systemctl is-active php8.5-fpm)" "$(systemctl is-active caddy)"
REMOTE
)

        expect_success POST "instances/$dev_id/environment/import" '{}' import true 1 "$dev_id"
        expect_success POST instances/orb207-store-prod.orbit/environment/import '{}' import true 1 "$prod_id"

        remote_node app-dev <<'REMOTE'
mv /home/orbit/orb207-store-dev/.env /home/orbit/orb207-store-dev/.env.saved
REMOTE
        expect_success PUT "instances/$dev_id/environment/UPDATED" '{"value":"dev-offline"}' update true 2 "$dev_id"
        remote_node app-dev <<'REMOTE'
mv /home/orbit/orb207-store-dev/.env.saved /home/orbit/orb207-store-dev/.env
REMOTE

        gateway_fixture set-node-status app-prod failed
        expect_success PUT "instances/$prod_id/environment/UPDATED" '{"value":"prod-offline"}' update true 2 "$prod_id"
        gateway_fixture set-node-status app-prod active

        gateway_fixture assert-values store-dev
        gateway_fixture assert-values store-prod
        test "$(remote_node app-dev <<'REMOTE'
path=/home/orbit/orb207-store-dev
test ! -e "$path/artisan"
test ! -e "$path/vendor"
test ! -e "$path/bootstrap"
printf '%s:%s:%s\n' "$(sha256sum "$path/.env" | cut -d ' ' -f 1)" "$(systemctl is-active php8.5-fpm)" "$(systemctl is-active caddy)"
REMOTE
)" = "$dev_snapshot"
        test "$(remote_node app-prod <<'REMOTE'
path=/home/orbit-app-orb207-store
sudo test ! -e "$path/artisan"
sudo test ! -e "$path/vendor"
sudo test ! -e "$path/bootstrap"
printf '%s:%s:%s\n' "$(sudo -u orbit-app-orb207-store -H sha256sum "$path/.env" | cut -d ' ' -f 1)" "$(systemctl is-active php8.5-fpm)" "$(systemctl is-active caddy)"
REMOTE
)" = "$prod_snapshot"

        gateway_fixture cleanup store
        remote_node app-dev <<'REMOTE'
sudo rm -rf /home/orbit/orb207-store-dev
REMOTE
        remote_node app-prod <<'REMOTE'
sudo rm -rf /home/orbit-app-orb207-store
if getent passwd orbit-app-orb207-store >/dev/null; then sudo userdel orbit-app-orb207-store; fi
REMOTE
        gateway_fixture assert-clean all
        ;;

    *)
        printf 'Unknown ORB-207 proof scenario: %s\n' "$scenario" >&2
        exit 64
        ;;
esac

printf 'ORB-207 %s: ok\n' "$scenario"

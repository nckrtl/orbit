#!/usr/bin/env bash
set -euo pipefail

scenario=${1:-}
repository=/home/orbit/orbit
gateway="$repository/apps/gateway"
fixture=/var/lib/orbit-e2e/proof/orb211-environment.php
original_app_dev_ip=

if [[ ! -f "$fixture" ]]; then
    fixture="$repository/.loop/proof/orb211-environment.php"
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

expect_preflight_failure() {
    local output
    if output=$(gateway_fixture preflight "$@" 2>&1); then
        return 1
    fi
    test "$output" = env.write_preflight_failed
}

write_file() {
    local label=$1 changed=$2 contents=$3
    printf %s "$contents" | gateway_fixture write "$label" "$changed"
}

write_fault() {
    local label=$1 fault=$2 contents=$3 output
    if ! output=$(printf %s "$contents" | gateway_fixture write-fault "$label" "$fault" 2>&1); then
        return 1
    fi
    test -z "$output"
}

write_unconfirmed() {
    local label=$1 boundary=$2 contents=$3 output
    if ! output=$(printf %s "$contents" | gateway_fixture write-unconfirmed "$label" "$boundary" 2>&1); then
        return 1
    fi
    test -z "$output"
}

probe_app_dev_cli() {
    remote_node app-dev <<'REMOTE'
orbit node:list --json | php -r '$value=json_decode(stream_get_contents(STDIN),true,32,JSON_THROW_ON_ERROR); if(!is_array($value["nodes"]??null)) exit(65);'
REMOTE
}

restore_app_dev_ip() {
    if [[ -n "$original_app_dev_ip" ]]; then
        gateway_fixture set-node-ip app-dev "$original_app_dev_ip"
        original_app_dev_ip=
    fi
}

cleanup_remote_files() {
    remote_node app-dev <<'REMOTE'
if mountpoint -q /home/orbit/orb211-readonly; then
    sudo umount /home/orbit/orb211-readonly
fi
sudo rm -rf -- \
    /home/orbit/orb211-environment-dev \
    /home/orbit/orb211-environment-dev-actor \
    /home/orbit/orb211-environment-dev-original \
    /home/orbit/orb211-readonly
REMOTE

    remote_node app-prod <<'REMOTE'
sudo rm -rf -- /home/orbit-app-orb211
if getent passwd orbit-app-orb211 >/dev/null; then
    sudo userdel orbit-app-orb211
fi
REMOTE
}

setup_remote_files() {
    remote_node app-dev <<'REMOTE'
sudo install -d -o orbit -g orbit -m 0700 /home/orbit/orb211-environment-dev
printf 'KEY=development-before\n' > /home/orbit/orb211-environment-dev/.env
chmod 0600 /home/orbit/orb211-environment-dev/.env
REMOTE

    remote_node app-prod <<'REMOTE'
if getent passwd orbit-app-orb211 >/dev/null; then
    sudo usermod -d /home/orbit-app-orb211 -s /usr/sbin/nologin orbit-app-orb211
else
    sudo useradd -M -d /home/orbit-app-orb211 -s /usr/sbin/nologin orbit-app-orb211
fi
sudo install -d -o orbit-app-orb211 -g orbit-app-orb211 -m 0700 /home/orbit-app-orb211
printf 'KEY=production-before\n' | sudo tee /home/orbit-app-orb211/.env >/dev/null
sudo chown orbit-app-orb211:orbit-app-orb211 /home/orbit-app-orb211/.env
sudo chmod 0600 /home/orbit-app-orb211/.env
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

    environment-file-placement)
        [[ $# -eq 1 && "$(id -u)" -eq 1000 ]]
        probe_app_dev_cli
        test "$(gateway_fixture describe development)" = 'development:app-dev:orbit:/home/orbit/orb211-environment-dev'
        test "$(gateway_fixture describe production)" = 'production:app-prod:orbit-app-orb211:/home/orbit-app-orb211'
        gateway_fixture preflight development write 4096
        gateway_fixture preflight production write 4096
        remote_node app-prod <<'REMOTE'
test "$(getent passwd orbit-app-orb211 | cut -d: -f6-7)" = /home/orbit-app-orb211:/usr/sbin/nologin
REMOTE
        curl --insecure --fail --silent --show-error \
            --resolve gateway.orbit:443:10.44.0.1 \
            https://gateway.orbit/up >/dev/null
        ;;

    environment-file-preflight)
        [[ $# -eq 1 && "$(id -u)" -eq 1000 ]]
        before=$(remote_node app-dev <<'REMOTE'
sha256sum /home/orbit/orb211-environment-dev/.env | cut -d ' ' -f 1
REMOTE
)
        gateway_fixture preflight development write 4096
        gateway_fixture preflight production write 4096

        remote_node app-dev <<'REMOTE'
chmod 0500 /home/orbit/orb211-environment-dev
chmod 0400 /home/orbit/orb211-environment-dev/.env
REMOTE
        gateway_fixture preflight development read
        expect_preflight_failure development write 4096
        remote_node app-dev <<'REMOTE'
chmod 0700 /home/orbit/orb211-environment-dev
chmod 0600 /home/orbit/orb211-environment-dev/.env
REMOTE

        gateway_fixture set-path development /home/orbit/orb211-missing
        expect_preflight_failure development write 4096
        gateway_fixture set-path development /home/orbit/orb211-environment-dev

        remote_node app-dev <<'REMOTE'
mv /home/orbit/orb211-environment-dev /home/orbit/orb211-environment-dev-original
ln -s /home/orbit/orb211-environment-dev-original /home/orbit/orb211-environment-dev
REMOTE
        expect_preflight_failure development write 4096
        remote_node app-dev <<'REMOTE'
rm /home/orbit/orb211-environment-dev
mv /home/orbit/orb211-environment-dev-original /home/orbit/orb211-environment-dev
mv /home/orbit/orb211-environment-dev/.env /home/orbit/orb211-environment-dev/.env.saved
mkfifo /home/orbit/orb211-environment-dev/.env
REMOTE
        expect_preflight_failure development write 4096
        remote_node app-dev <<'REMOTE'
rm /home/orbit/orb211-environment-dev/.env
mv /home/orbit/orb211-environment-dev/.env.saved /home/orbit/orb211-environment-dev/.env
sudo chown root:root /home/orbit/orb211-environment-dev/.env
REMOTE
        expect_preflight_failure development write 4096
        remote_node app-dev <<'REMOTE'
sudo chown orbit:orbit /home/orbit/orb211-environment-dev/.env
sudo install -d -o orbit -g orbit -m 0700 /home/orbit/orb211-readonly
sudo mount --bind /home/orbit/orb211-readonly /home/orbit/orb211-readonly
sudo mount -o remount,bind,ro /home/orbit/orb211-readonly
REMOTE
        gateway_fixture set-path development /home/orbit/orb211-readonly
        expect_preflight_failure development write 4096
        gateway_fixture set-path development /home/orbit/orb211-environment-dev
        remote_node app-dev <<'REMOTE'
sudo umount /home/orbit/orb211-readonly
sudo rm -rf -- /home/orbit/orb211-readonly
REMOTE

        expect_preflight_failure development write 9223372036854775807
        gateway_fixture preflight-observation development failed
        gateway_fixture preflight-observation development malformed
        original_app_dev_ip=$(node_ip app-dev)
        trap restore_app_dev_ip EXIT
        gateway_fixture set-node-ip app-dev 10.44.0.254
        expect_preflight_failure development write 4096
        restore_app_dev_ip
        trap - EXIT

        test "$(remote_node app-dev <<'REMOTE'
sha256sum /home/orbit/orb211-environment-dev/.env | cut -d ' ' -f 1
REMOTE
)" = "$before"
        ;;

    environment-file-replacement)
        [[ $# -eq 1 && "$(id -u)" -eq 1000 ]]
        remote_node app-dev <<'REMOTE'
rm /home/orbit/orb211-environment-dev/.env
REMOTE
        write_file development true $'KEY=development-created\nOPAQUE=!@#$%^&*()[]{}\n'
        remote_node app-dev <<'REMOTE'
test "$(stat -c '%U:%G:%a' /home/orbit/orb211-environment-dev/.env)" = orbit:orbit:600
test "$(cat /home/orbit/orb211-environment-dev/.env)" = $'KEY=development-created\nOPAQUE=!@#$%^&*()[]{}'
REMOTE
        write_file production true $'KEY=production-replaced\n'
        remote_node app-prod <<'REMOTE'
test "$(sudo -u orbit-app-orb211 stat -c '%U:%G:%a' /home/orbit-app-orb211/.env)" = orbit-app-orb211:orbit-app-orb211:600
test "$(sudo -u orbit-app-orb211 cat /home/orbit-app-orb211/.env)" = 'KEY=production-replaced'
REMOTE
        ;;

    environment-file-failure)
        [[ $# -eq 1 && "$(id -u)" -eq 1000 ]]
        current=$'KEY=must-not-install\n'
        remote_node app-dev <<'REMOTE'
printf 'KEY=preserved\n' > /home/orbit/orb211-environment-dev/.env
chmod 0600 /home/orbit/orb211-environment-dev/.env
printf foreign > /home/orbit/orb211-environment-dev/.env.orbit-foreign
REMOTE
        for fault in candidate-write permission rename; do
            write_fault development "$fault" "$current"
            remote_node app-dev <<'REMOTE'
test "$(cat /home/orbit/orb211-environment-dev/.env)" = 'KEY=preserved'
test "$(cat /home/orbit/orb211-environment-dev/.env.orbit-foreign)" = foreign
test "$(find /home/orbit/orb211-environment-dev -maxdepth 1 -name '.env.orbit-*' -printf '%f\n')" = .env.orbit-foreign
REMOTE
        done

        gateway_fixture preflight development write 4096
        remote_node app-dev <<'REMOTE'
mv /home/orbit/orb211-environment-dev /home/orbit/orb211-environment-dev-original
sudo install -d -o orbit -g orbit -m 0700 /home/orbit/orb211-environment-dev-actor
printf 'KEY=actor\n' > /home/orbit/orb211-environment-dev-actor/.env
chmod 0600 /home/orbit/orb211-environment-dev-actor/.env
ln -s /home/orbit/orb211-environment-dev-actor /home/orbit/orb211-environment-dev
REMOTE
        output=''
        if output=$(printf %s "$current" | gateway_fixture write development true 2>&1); then
            exit 1
        fi
        test "$output" = env.write_failed
        remote_node app-dev <<'REMOTE'
test "$(cat /home/orbit/orb211-environment-dev-original/.env)" = 'KEY=preserved'
test "$(cat /home/orbit/orb211-environment-dev-actor/.env)" = 'KEY=actor'
test -z "$(find /home/orbit/orb211-environment-dev-original /home/orbit/orb211-environment-dev-actor -maxdepth 1 -name '.env.orbit-*' ! -name '.env.orbit-foreign' -print -quit)"
rm /home/orbit/orb211-environment-dev
sudo rm -rf -- /home/orbit/orb211-environment-dev-actor
mv /home/orbit/orb211-environment-dev-original /home/orbit/orb211-environment-dev
rm /home/orbit/orb211-environment-dev/.env.orbit-foreign
REMOTE
        ;;

    environment-file-retry)
        [[ $# -eq 1 && "$(id -u)" -eq 1000 ]]
        synced=$'KEY=retry-sync\n'
        first=$'KEY=retry-after\n'
        second=$'KEY=retry-before\n'
        write_unconfirmed development sync "$synced"
        synced_inode=$(remote_node app-dev <<'REMOTE'
test "$(cat /home/orbit/orb211-environment-dev/.env)" = 'KEY=retry-sync'
stat -c %i /home/orbit/orb211-environment-dev/.env
REMOTE
)
        write_file development false "$synced"
        test "$(remote_node app-dev <<'REMOTE'
stat -c %i /home/orbit/orb211-environment-dev/.env
REMOTE
)" = "$synced_inode"

        write_unconfirmed development after "$first"
        installed_inode=$(remote_node app-dev <<'REMOTE'
test "$(cat /home/orbit/orb211-environment-dev/.env)" = 'KEY=retry-after'
stat -c %i /home/orbit/orb211-environment-dev/.env
REMOTE
)
        write_file development false "$first"
        test "$(remote_node app-dev <<'REMOTE'
stat -c %i /home/orbit/orb211-environment-dev/.env
REMOTE
)" = "$installed_inode"

        write_unconfirmed development before "$second"
        test "$(remote_node app-dev <<'REMOTE'
cat /home/orbit/orb211-environment-dev/.env
REMOTE
)" = 'KEY=retry-after'
        write_file development true "$second"
        ;;

    environment-file-repeat)
        [[ $# -eq 1 && "$(id -u)" -eq 1000 ]]
        contents=$'KEY=repeat\n'
        write_file development true "$contents"
        before=$(remote_node app-dev <<'REMOTE'
stat -c %i /home/orbit/orb211-environment-dev/.env
REMOTE
)
        write_file development false "$contents"
        test "$(remote_node app-dev <<'REMOTE'
stat -c %i /home/orbit/orb211-environment-dev/.env
REMOTE
)" = "$before"
        remote_node app-dev <<'REMOTE'
chmod 0644 /home/orbit/orb211-environment-dev/.env
REMOTE
        write_file development true "$contents"
        remote_node app-dev <<'REMOTE'
test "$(stat -c %a /home/orbit/orb211-environment-dev/.env)" = 600
REMOTE
        write_file development true $'KEY=different\n'
        ;;

    environment-file-without-application-bootstrap)
        [[ $# -eq 1 && "$(id -u)" -eq 1000 ]]
        probe_app_dev_cli
        remote_node app-dev <<'REMOTE'
path=/home/orbit/orb211-environment-dev
test ! -e "$path/artisan"
test ! -e "$path/vendor"
test ! -e "$path/composer.json"
test ! -e "$path/database"
mkdir -p "$path/.git" "$path/bootstrap/cache"
printf source > "$path/source.php"
printf git > "$path/.git/HEAD"
printf cache > "$path/bootstrap/cache/config.php"
REMOTE
        remote_node app-prod <<'REMOTE'
path=/home/orbit-app-orb211
sudo test ! -e "$path/artisan"
sudo test ! -e "$path/vendor"
sudo test ! -e "$path/composer.json"
sudo install -d -o orbit-app-orb211 -g orbit-app-orb211 -m 0700 "$path/.git" "$path/bootstrap" "$path/bootstrap/cache"
printf source | sudo -u orbit-app-orb211 tee "$path/source.php" >/dev/null
printf git | sudo -u orbit-app-orb211 tee "$path/.git/HEAD" >/dev/null
printf cache | sudo -u orbit-app-orb211 tee "$path/bootstrap/cache/config.php" >/dev/null
sudo install -o orbit-app-orb211 -g orbit-app-orb211 -m 0600 /dev/null "$path/application.sqlite"
printf database | sudo -u orbit-app-orb211 tee "$path/application.sqlite" >/dev/null
REMOTE
        before=$(remote_node app-dev <<'REMOTE'
path=/home/orbit/orb211-environment-dev
printf '%s:%s:%s:%s:%s\n' \
    "$(sha256sum "$path/source.php" | cut -d ' ' -f 1)" \
    "$(sha256sum "$path/.git/HEAD" | cut -d ' ' -f 1)" \
    "$(sha256sum "$path/bootstrap/cache/config.php" | cut -d ' ' -f 1)" \
    "$(systemctl show -p MainPID --value php8.5-fpm)" \
    "$(systemctl show -p MainPID --value caddy)"
REMOTE
)
        prod_before=$(remote_node app-prod <<'REMOTE'
path=/home/orbit-app-orb211
printf '%s:%s:%s:%s:%s:%s\n' \
    "$(sudo -u orbit-app-orb211 sha256sum "$path/source.php" | cut -d ' ' -f 1)" \
    "$(sudo -u orbit-app-orb211 sha256sum "$path/.git/HEAD" | cut -d ' ' -f 1)" \
    "$(sudo -u orbit-app-orb211 sha256sum "$path/bootstrap/cache/config.php" | cut -d ' ' -f 1)" \
    "$(sudo -u orbit-app-orb211 sha256sum "$path/application.sqlite" | cut -d ' ' -f 1)" \
    "$(systemctl show -p MainPID --value php8.5-fpm)" \
    "$(systemctl show -p MainPID --value caddy)"
REMOTE
)
        secret=$'APP_KEY=proof-sentinel-never-diagnosed\nSYMBOLS=!@#$%^&*()[]{}\n'
        fault_secret=$'APP_KEY=distinct-fault-sentinel-never-diagnosed\n'
        write_file development true "$secret"
        write_file production true $'APP_KEY=production-proof-sentinel\n'
        output=''
        if ! output=$(printf %s "$fault_secret" | gateway_fixture write-fault development rename 2>&1); then
            exit 1
        fi
        test -z "$output"
        test "$(remote_node app-dev <<'REMOTE'
path=/home/orbit/orb211-environment-dev
printf '%s:%s:%s:%s:%s\n' \
    "$(sha256sum "$path/source.php" | cut -d ' ' -f 1)" \
    "$(sha256sum "$path/.git/HEAD" | cut -d ' ' -f 1)" \
    "$(sha256sum "$path/bootstrap/cache/config.php" | cut -d ' ' -f 1)" \
    "$(systemctl show -p MainPID --value php8.5-fpm)" \
    "$(systemctl show -p MainPID --value caddy)"
REMOTE
)" = "$before"
        test "$(remote_node app-prod <<'REMOTE'
path=/home/orbit-app-orb211
printf '%s:%s:%s:%s:%s:%s\n' \
    "$(sudo -u orbit-app-orb211 sha256sum "$path/source.php" | cut -d ' ' -f 1)" \
    "$(sudo -u orbit-app-orb211 sha256sum "$path/.git/HEAD" | cut -d ' ' -f 1)" \
    "$(sudo -u orbit-app-orb211 sha256sum "$path/bootstrap/cache/config.php" | cut -d ' ' -f 1)" \
    "$(sudo -u orbit-app-orb211 sha256sum "$path/application.sqlite" | cut -d ' ' -f 1)" \
    "$(systemctl show -p MainPID --value php8.5-fpm)" \
    "$(systemctl show -p MainPID --value caddy)"
REMOTE
)" = "$prod_before"
        gateway_fixture cleanup
        cleanup_remote_files
        gateway_fixture assert-clean
        ;;

    *)
        printf 'Unknown ORB-211 proof scenario: %s\n' "$scenario" >&2
        exit 64
        ;;
esac

printf 'ORB-211 %s: ok\n' "$scenario"

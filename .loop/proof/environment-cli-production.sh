#!/usr/bin/env bash
set -euo pipefail

orbit=/home/orbit/orbit/apps/cli/orbit
selector=orb210-prod.orbit
production_user=orb210prod
production_home=/home/orb210prod
production_id_file=/home/orbit/.orbit/orb210-prod-id
ssh_key=/home/orbit/.orbit/ssh/id_ed25519
known_hosts=/home/orbit/.orbit/ssh/known_hosts
remote=(ssh -i "$ssh_key" -o BatchMode=yes -o StrictHostKeyChecking=yes -o UserKnownHostsFile="$known_hosts" orbit@10.44.0.3)

fail() {
    printf 'environment-cli-production: %s\n' "$1" >&2
    exit 1
}

assert_result() {
    local result=$1
    local operation=$2
    local changed=$3
    local stored_only=${4:-false}
    local suffix=

    if [[ "$stored_only" == true ]]; then
        suffix=',"workload_file_changed":false'
    fi

    grep -Eq "^\\{\"app_instance_id\":[1-9][0-9]*,\"operation\":\"${operation}\",\"changed\":${changed},\"key_count\":[0-9]+,\"request_id\":\"[0-9a-f-]{36}\"${suffix}\\}$" <<<"$result" \
        || fail "${operation} returned an unexpected result"
}

remote_file_sha() {
    "${remote[@]}" "sudo -n -u '$production_user' -- sha256sum '$production_home/.env' | cut -d' ' -f1"
}

remote_key_sha() {
    "${remote[@]}" "sudo -n -u '$production_user' -- php8.5 -r '\$values = parse_ini_file(\$argv[1], false, INI_SCANNER_RAW); echo hash(\"sha256\", \$values[\"APP_KEY\"] ?? \"\");' '$production_home/.env'"
}

[[ -x "$orbit" ]] || fail 'CLI entrypoint is unavailable'
[[ -f "$ssh_key" ]] || fail 'Gateway SSH identity is unavailable'

production_id=$(<"$production_id_file")
[[ "$production_id" =~ ^[1-9][0-9]*$ ]] || fail 'production AppInstance fixture was not recorded'
"${remote[@]}" "test ! -e '$production_home/database'" || fail 'production fixture unexpectedly has a database'

before_file=$(remote_file_sha)
before_key=$(remote_key_sha)
before_fpm=$("${remote[@]}" 'systemctl show php8.5-fpm.service --property=MainPID --value')

import_result=$("$orbit" env:import --instance="$production_id" --json)
assert_result "$import_result" import true true
update_result=$("$orbit" env:update --instance="$selector" --key=APP_DEBUG --value=0 --json)
assert_result "$update_result" update true true
[[ "$(remote_file_sha)" == "$before_file" ]] || fail 'stored operations changed the production workload file'

sync_result=$("$orbit" env:sync --instance="$production_id" --json)
assert_result "$sync_result" sync true
[[ "$(remote_key_sha)" == "$before_key" ]] || fail 'production synchronization changed APP_KEY'
"${remote[@]}" "sudo -n -u '$production_user' -- grep -qx 'APP_URL=\"https://orb210-prod.orbit\"' '$production_home/.env'" || fail 'production APP_URL did not use the Route hostname'
"${remote[@]}" "sudo -n -u '$production_user' -- grep -qx 'APP_DEBUG=\"0\"' '$production_home/.env'" || fail 'production update did not reach the workload file'
[[ "$("${remote[@]}" 'systemctl show php8.5-fpm.service --property=MainPID --value')" == "$before_fpm" ]] || fail 'synchronization restarted PHP-FPM'
"${remote[@]}" "test ! -e '$production_home/database'" || fail 'synchronization created a database'

synced_file=$(remote_file_sha)
repeat_result=$("$orbit" env:sync --instance="$selector" --json)
assert_result "$repeat_result" sync false
[[ "$(remote_file_sha)" == "$synced_file" ]] || fail 'idempotent production synchronization changed the file'

printf 'environment-cli-production ok\n'

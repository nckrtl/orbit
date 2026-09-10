#!/usr/bin/env bash
set -euo pipefail

orbit=/home/orbit/orbit/apps/cli/orbit
environment_file=/home/orbit/apps/laravel-typed/e2e-dev/.env
selector=e2e-dev.orbit

fail() {
    printf 'environment-cli-lifecycle: %s\n' "$1" >&2
    exit 1
}

file_sha() {
    sha256sum "$environment_file" | cut -d' ' -f1
}

key_sha() {
    php8.5 -r '$values = parse_ini_file($argv[1], false, INI_SCANNER_RAW); echo hash("sha256", $values["APP_KEY"] ?? "");' "$environment_file"
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

[[ -x "$orbit" ]] || fail 'CLI entrypoint is unavailable'
[[ -f "$environment_file" ]] || fail 'development environment file is absent'
grep -q '^APP_KEY=' "$environment_file" || fail 'development APP_KEY is absent'

before_file=$(file_sha)
before_key=$(key_sha)

import_result=$("$orbit" env:import --instance="$selector" --json)
assert_result "$import_result" import true true
[[ "$(file_sha)" == "$before_file" ]] || fail 'import changed the workload file'

update_result=$("$orbit" env:update --instance="$selector" --key=ORB_ENV_PROOF --value=false --json)
assert_result "$update_result" update true true
[[ "$(file_sha)" == "$before_file" ]] || fail 'update changed the workload file'

sync_result=$("$orbit" env:sync --instance="$selector" --json)
assert_result "$sync_result" sync true
[[ "$(file_sha)" != "$before_file" ]] || fail 'first synchronization did not change the workload file'
[[ "$(key_sha)" == "$before_key" ]] || fail 'synchronization changed APP_KEY'
grep -qx 'APP_URL="https://e2e-dev.orbit"' "$environment_file" || fail 'APP_URL did not use the selected Route hostname'
grep -qx 'ORB_ENV_PROOF="false"' "$environment_file" || fail 'stored update did not reach the workload file'

synced_file=$(file_sha)
repeat_result=$("$orbit" env:sync --instance="$selector" --json)
assert_result "$repeat_result" sync false
[[ "$(file_sha)" == "$synced_file" ]] || fail 'idempotent synchronization changed the workload file'

printf 'environment-cli-lifecycle ok\n'

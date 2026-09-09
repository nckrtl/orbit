#!/usr/bin/env bash

set -euo pipefail

live_pool=/etc/php/8.5/fpm/pool.d/orbit-gateway.conf
conflict_pool=/etc/php/8.5/fpm/pool.d/orb-157-conflict.conf
candidate_root=/etc/php/8.5/fpm/orbit-candidates
sentinel_stage=$candidate_root/orb-157-preserved-stage
worker=/var/lib/orbit-e2e/proof/gateway-fpm-stage-worker.php
working_directory=$(mktemp -d /run/orbit-e2e-orb-157.XXXXXXXX)
backup_pool=$working_directory/original-orbit-gateway.conf
restore_candidate=$working_directory/restored-orbit-gateway.conf
a_pid=''
b_pid=''

restore_live_pool() {
    local cleanup_status=0

    rm -f -- "$conflict_pool" || cleanup_status=$?

    if [[ -f "$backup_pool" ]]; then
        install -o root -g root -m 0644 -- "$backup_pool" "$restore_candidate" || cleanup_status=$?
        mv -f -- "$restore_candidate" "$live_pool" || cleanup_status=$?
        systemctl reload-or-restart php8.5-fpm || cleanup_status=$?
    fi

    rm -rf -- "$sentinel_stage" "$working_directory" || cleanup_status=$?

    return "$cleanup_status"
}

cleanup() {
    local primary_status=$?
    local cleanup_status=0

    trap - EXIT INT TERM
    set +e

    if [[ -n "$a_pid" ]]; then
        kill "$a_pid" 2>/dev/null
        wait "$a_pid" 2>/dev/null
    fi

    if [[ -n "$b_pid" ]]; then
        kill "$b_pid" 2>/dev/null
        wait "$b_pid" 2>/dev/null
    fi

    restore_live_pool || cleanup_status=$?

    if [[ "$primary_status" -eq 0 ]]; then
        primary_status=$cleanup_status
    fi

    exit "$primary_status"
}

wait_for_file() {
    local path=$1
    local remaining=6000

    while [[ ! -f "$path" ]]; do
        if [[ "$remaining" -eq 0 ]]; then
            printf 'Timed out waiting for %s\n' "$path" >&2
            return 1
        fi

        sleep 0.01
        remaining=$((remaining - 1))
    done
}

assert_owner_mode() {
    local path=$1
    local expected_mode=$2
    local observed

    observed=$(stat -c '%U:%G %a' -- "$path")
    [[ "$observed" == "root:root $expected_mode" ]]
}

trap cleanup EXIT INT TERM

test -f "$live_pool"
cp --preserve=mode,ownership -- "$live_pool" "$backup_pool"
cp -- "$live_pool" "$working_directory/lane-a.conf"
cp -- "$live_pool" "$working_directory/lane-b.conf"
printf '\n; ORB-157 invocation A\n' >> "$working_directory/lane-a.conf"
printf '\n; ORB-157 invocation B\n' >> "$working_directory/lane-b.conf"

/usr/bin/php8.5 "$worker" a "$working_directory/lane-a.conf" "$working_directory" pause &
a_pid=$!
wait_for_file "$working_directory/a.ready"

/usr/bin/php8.5 "$worker" b "$working_directory/lane-b.conf" "$working_directory" pause &
b_pid=$!
wait_for_file "$working_directory/b.ready"

mapfile -t a_paths < "$working_directory/a.paths"
mapfile -t b_paths < "$working_directory/b.paths"
test "${#a_paths[@]}" -eq 3
test "${#b_paths[@]}" -eq 3
[[ "${a_paths[0]}" != "${b_paths[0]}" ]]
[[ "${a_paths[1]}" != "${b_paths[1]}" ]]
[[ "${a_paths[2]}" != "${b_paths[2]}" ]]
assert_owner_mode "${a_paths[0]}" 755
assert_owner_mode "${a_paths[1]}" 644
assert_owner_mode "$(dirname "${a_paths[2]}")" 755
assert_owner_mode "${a_paths[2]}" 644
assert_owner_mode "${b_paths[0]}" 755
assert_owner_mode "${b_paths[1]}" 644
assert_owner_mode "$(dirname "${b_paths[2]}")" 755
assert_owner_mode "${b_paths[2]}" 644
[[ "$(stat -c '%d' -- "$(dirname "${a_paths[2]}")")" == "$(stat -c '%d' -- "$(dirname "$live_pool")")" ]]
[[ "$(stat -c '%d' -- "$(dirname "${b_paths[2]}")")" == "$(stat -c '%d' -- "$(dirname "$live_pool")")" ]]

touch "$working_directory/b.release"
wait "$b_pid"
b_pid=''
test ! -e "${b_paths[0]}"
test -d "${a_paths[0]}"
cmp -- "$working_directory/lane-b.conf" "$live_pool"

touch "$working_directory/a.release"
wait "$a_pid"
a_pid=''
test ! -e "${a_paths[0]}"
cmp -- "$working_directory/lane-a.conf" "$live_pool"
assert_owner_mode "$live_pool" 644

install -d -o root -g root -m 0755 "$sentinel_stage"
printf 'preserve another invocation\n' > "$sentinel_stage/marker"
assert_owner_mode "$sentinel_stage" 755
sed '0,/^\[orbit-gateway\]$/{s//[orb-157-conflict]/}' "$live_pool" > "$working_directory/conflicting-pool.conf"
install -o root -g root -m 0644 -- "$working_directory/conflicting-pool.conf" "$conflict_pool"
live_hash_before=$(sha256sum "$live_pool")

/usr/bin/php8.5 "$worker" failure "$working_directory/lane-b.conf" "$working_directory" failure

mapfile -t failure_paths < "$working_directory/failure.paths"
test "${#failure_paths[@]}" -eq 3
test ! -e "${failure_paths[0]}"
test -f "$sentinel_stage/marker"
[[ "$(sha256sum "$live_pool")" == "$live_hash_before" ]]
! grep -Fq -- "${failure_paths[2]}\",\"$live_pool" "$working_directory/failure.commands.jsonl"
! grep -Fq -- '"systemctl","reload-or-restart","php8.5-fpm"' "$working_directory/failure.commands.jsonl"
/usr/bin/php8.5 -r '
    $outcome = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
    if (
        $outcome["status"] !== "failed"
        || $outcome["step"] !== "gateway-fpm-validate"
        || $outcome["error_code"] !== "gateway.fpm_config_invalid"
        || ! is_string($outcome["stderr"])
        || $outcome["stderr"] === ""
    ) {
        exit(1);
    }
' "$working_directory/failure.outcome.json"

rm -f -- "$conflict_pool"
php-fpm8.5 --test
restore_live_pool
trap - EXIT INT TERM

printf 'gateway FPM invocation stages remained isolated under contention and conflict\n'

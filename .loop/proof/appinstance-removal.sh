#!/usr/bin/env bash
set -euo pipefail

scenario="${1:-}"
repository=/home/orbit/orbit
gateway="$repository/apps/gateway"
fixture=/var/lib/orbit-e2e/proof/appinstance-removal.php
ssh_key=/home/orbit/.orbit/ssh/id_ed25519
known_hosts=/home/orbit/.orbit/ssh/known_hosts
app_dev_ip=10.44.0.2

if [ ! -f "$fixture" ]; then
    fixture="$repository/.loop/proof/appinstance-removal.php"
fi

probe_gateway() {
    orbit node:list --json | python3 -c '
import json
import sys

value = json.load(sys.stdin)
if not isinstance(value.get("nodes"), list) or not isinstance(value.get("request_id"), str):
    raise SystemExit(65)
'
}

node_ip() {
    case "$1" in
        app-dev) printf '%s\n' "$app_dev_ip" ;;
        *) return 64 ;;
    esac
}

remote_command() {
    local node=$1
    shift
    ssh \
        -i "$ssh_key" \
        -o BatchMode=yes \
        -o IdentitiesOnly=yes \
        -o StrictHostKeyChecking=yes \
        -o ConnectTimeout=10 \
        -o ServerAliveInterval=5 \
        -o ServerAliveCountMax=3 \
        -o "UserKnownHostsFile=$known_hosts" \
        "orbit@$(node_ip "$node")" \
        "$@"
}

remote_script() {
    local node=$1
    shift
    remote_command "$node" bash -seu -- "$@"
}

gateway_fixture() {
    (
        cd "$gateway"
        php "$fixture" "$@"
    )
}

make_checkout() {
    local name=$1
    local branch=$2
    local mode=${3:-clean}
    remote_script app-dev "$name" "$branch" "$mode" <<'BASH'
name=$1
branch=$2
mode=$3
case "$name" in orb181-[a-z0-9-]*) ;; *) exit 64 ;; esac
case "$branch" in orb181-[a-z0-9-]*) ;; *) exit 64 ;; esac
template=/home/orbit/apps/laravel-typed/e2e-dev
path="/home/orbit/apps/laravel-typed/$name"
test ! -e "$path"
git clone --local --no-checkout "$template" "$path" >/dev/null
git -C "$path" remote set-url origin https://github.com/laravel/laravel.git
git -C "$path" checkout -b "$branch" HEAD >/dev/null
git -C "$path" config user.name 'Orbit proof'
git -C "$path" config user.email orbit-proof@example.invalid
base=$(git -C "$path" rev-parse HEAD)
case "$mode" in
    clean) ;;
    dirty) printf 'dirty source\n' > "$path/orb181-dirty.txt" ;;
    *) exit 64 ;;
esac
printf '%s %s\n' "$base" "$(git -C "$path" rev-parse HEAD)"
BASH
}

seed_dev() {
    local name=$1
    local layout=$2
    local branch=$3
    local commit=$4
    local hostname=${5:-}
    if [ -n "$hostname" ]; then
        gateway_fixture seed-dev "$name" "$layout" "$branch" "$commit" "$hostname"
    else
        gateway_fixture seed-dev "$name" "$layout" "$branch" "$commit"
    fi
}

seed_id() {
    python3 -c 'import json, sys; print(json.load(sys.stdin)["id"])'
}

seed_identity() {
    python3 -c 'import json, sys; value = json.load(sys.stdin); print(value["id"], value["route_id"])'
}

assert_successful_removal() {
    local output=$1
    local expected_id=$2
    local expected_force=$3
    local expected_total=$4
    python3 -c '
import json
import sys

value = json.loads(sys.argv[1])
valid = (
    value.get("id") == int(sys.argv[2])
    and value.get("force") is (sys.argv[3] == "1")
    and value.get("status") == "completed"
    and value.get("current_step") is None
    and value.get("total") == int(sys.argv[4])
    and value.get("completed") == int(sys.argv[4])
    and value.get("remaining") == 0
    and value.get("failed_step") is None
    and value.get("error_code") is None
)
if not valid:
    raise SystemExit(65)
' "$output" "$expected_id" "$expected_force" "$expected_total"
}

remove_success() {
    local id=$1
    local force=$2
    local total=${3:-1}
    local output
    if [ "$force" = 1 ]; then
        output=$(remote_command app-dev orbit instance:remove "$id" --force --json)
    else
        output=$(remote_command app-dev orbit instance:remove "$id" --json)
    fi
    assert_successful_removal "$output" "$id" "$force" "$total"
}

expect_remove_failure() {
    local id=$1
    local force=$2
    local expected_code=$3
    local output status
    set +e
    if [ "$force" = 1 ]; then
        output=$(remote_command app-dev orbit instance:remove "$id" --force --json 2>&1)
        status=$?
    else
        output=$(remote_command app-dev orbit instance:remove "$id" --json 2>&1)
        status=$?
    fi
    set -e
    test "$status" -ne 0
    python3 -c '
import json
import sys

value = json.loads(sys.argv[1])
error = value.get("error")
if not isinstance(error, dict) or error.get("code") != sys.argv[2]:
    raise SystemExit(65)
if not isinstance(error.get("request_id"), str):
    raise SystemExit(65)
' "$output" "$expected_code"
    LAST_FAILURE=$output
}

assert_active_unchanged() {
    local before=$1
    local name=$2
    local after
    after=$(gateway_fixture instance-state "$name")
    test "$before" = "$after"
}

assert_completed_evidence() {
    local name=$1
    local total=${2:-1}
    gateway_fixture removal-evidence "$name" | python3 -c '
import json
import sys

value = json.load(sys.stdin)
members = value.get("members", [])
if value.get("status") != "completed" or value.get("total") != int(sys.argv[1]):
    raise SystemExit(65)
if len(members) != int(sys.argv[1]):
    raise SystemExit(65)
for member in members:
    times = [member.get(key) for key in (
        "source_prepared_at", "route_cleared_at", "source_finalized_at",
        "runtime_cleaned_at", "row_deleted_at",
    )]
    if any(not isinstance(item, str) for item in times) or times != sorted(times):
        raise SystemExit(65)
    if member.get("route_outcome") != "deleted" or member.get("route_exists") is not False:
        raise SystemExit(65)
    receipt = member.get("receipt")
    if not isinstance(receipt, str) or len(receipt) != 64:
        raise SystemExit(65)
' "$total"
}

assert_failure_progress() {
    local expected_id=$1
    local expected_step=$2
    local expected_total=$3
    local expected_completed=$4
    local expected_remaining=$5
    python3 -c '
import json
import sys

value = json.loads(sys.argv[1])
removal = value.get("error", {}).get("details", {}).get("removal")
valid = (
    isinstance(removal, dict)
    and removal.get("id") == int(sys.argv[2])
    and removal.get("status") == "failed"
    and removal.get("current_step") == sys.argv[3]
    and removal.get("failed_step") == sys.argv[3]
    and removal.get("total") == int(sys.argv[4])
    and removal.get("completed") == int(sys.argv[5])
    and removal.get("remaining") == int(sys.argv[6])
    and removal.get("error_code") == value.get("error", {}).get("code")
)
if not valid:
    raise SystemExit(65)
' "$LAST_FAILURE" "$expected_id" "$expected_step" "$expected_total" "$expected_completed" "$expected_remaining"
}

inject_dns_failure() {
    temporary=$(mktemp)
    printf 'orb181-invalid-directive\n' > "$temporary"
    sudo install -o root -g root -m 0644 "$temporary" /etc/dnsmasq.d/orb181-invalid.conf
    rm -f "$temporary"
}

restore_dns() {
    sudo rm -f -- /etc/dnsmasq.d/orb181-invalid.conf
    sudo dnsmasq --test >/dev/null
    sudo systemctl reset-failed dnsmasq
    sudo systemctl restart dnsmasq
    test "$(systemctl is-active dnsmasq)" = active
}
install_route_firewall_artifact() {
    local route_id=$1
    remote_script app-dev "$route_id" <<'BASH'
route_id=$1
sudo ufw allow in proto tcp from 10.44.0.1 to 10.44.0.2 port 443 comment "orbit:route-$route_id-lan" >/dev/null
sudo ufw status numbered | grep -F -- "# orbit:route-$route_id-lan" >/dev/null
BASH
}

assert_removal_projection_absent() {
    local instance_id=$1
    local route_id=$2
    local hostname=$3
    remote_script app-dev "$instance_id" "$route_id" "$hostname" <<'BASH'
instance_id=$1
route_id=$2
hostname=$3
current=$(sudo readlink -f /etc/caddy/Caddyfile)
fragment="$(dirname "$current")/fragments/app-dev.caddy"
test ! -e "/etc/caddy/orbit-certificates/app-instance-$instance_id"
! sudo grep -F -- "$hostname" "$fragment" >/dev/null
! sudo ufw status numbered | grep -F -- "# orbit:route-$route_id-lan" >/dev/null
BASH
    ! sudo grep -F -- "$hostname" /etc/dnsmasq.d/orbit-records.conf >/dev/null
}

install_fpm_access_probe() {
    local instance_id=$1
    remote_script app-dev "$instance_id" <<'BASH'
instance_id=$1
configuration=/etc/php/8.5/fpm/pool.d/orbit-scopes.conf
pool="[orbit-app-instance-$instance_id]"
access_log="/tmp/orb181-fpm-access-$instance_id.log"
candidate=$(mktemp)
trap 'rm -f -- "$candidate"' EXIT
test "$(grep -Fxc -- "$pool" "$configuration")" = 1
awk -v pool="$pool" -v access_log="$access_log" '
    $0 == pool { print; print "access.log = " access_log; next }
    { print }
' "$configuration" > "$candidate"
sudo install -o root -g root -m 0644 "$candidate" "$configuration"
sudo rm -f -- "$access_log"
sudo systemctl restart php8.5-fpm
test "$(systemctl is-active php8.5-fpm)" = active
BASH
}

fpm_access_count() {
    local instance_id=$1
    remote_script app-dev "$instance_id" <<'BASH'
instance_id=$1
access_log="/tmp/orb181-fpm-access-$instance_id.log"
sudo test -f "$access_log"
sudo awk 'END { print NR }' "$access_log"
BASH
}

assert_source_absent() {
    remote_script app-dev "$1" <<'BASH'
name=$1
test ! -e "/home/orbit/apps/laravel-typed/$name"
BASH
}

assert_route_checkpoint() {
    local name=$1
    local expected_step=$2
    local expected_route=$3
    gateway_fixture removal-evidence "$name" | python3 -c '
import json
import sys

value = json.load(sys.stdin)
member = value["members"][0]
valid = (
    value.get("status") == "failed"
    and value.get("current_step") == sys.argv[1]
    and value.get("failed_step") == sys.argv[1]
    and member.get("route_outcome") == (None if sys.argv[2] == "pending" else "deleted")
    and (member.get("route_cleared_at") is None) == (sys.argv[2] == "pending")
    and (member.get("route_exists") is True) == (sys.argv[2] == "pending")
    and member.get("route_targets") == []
)
if not valid:
    raise SystemExit(65)
' "$expected_step" "$expected_route"
}

hold_dns_lock() {
    sudo rm -f -- /tmp/orb181-dns-lock-held /tmp/orb181-dns-lock-release
    sudo bash -seu <<'BASH' &
exec 9>/run/lock/orbit-dnsmasq.lock
flock 9
touch /tmp/orb181-dns-lock-held
while [ ! -f /tmp/orb181-dns-lock-release ]; do
    sleep 0.05
done
BASH
    HELPER_PID=$!
    for _ in $(seq 1 200); do
        test -f /tmp/orb181-dns-lock-held && return
        sleep 0.05
    done
    sudo touch /tmp/orb181-dns-lock-release
    wait "$HELPER_PID" || true
    return 65
}

release_dns_lock() {
    sudo touch /tmp/orb181-dns-lock-release
    wait "$HELPER_PID" 2>/dev/null || true
}

hold_second_caddy_publication() {
    local hostname=$1
    remote_script app-dev "$hostname" <<'BASH' &
hostname=$1
sudo rm -f -- /tmp/orb181-caddy-lock-held /tmp/orb181-caddy-lock-release
for _ in $(seq 1 3000); do
    current=$(sudo readlink -f /etc/caddy/Caddyfile)
    fragment="$(dirname "$current")/fragments/app-dev.caddy"
    if sudo grep -F "https://$hostname" "$fragment" >/dev/null &&
        sudo grep -F 'respond "Orbit Route unavailable\n" 503' "$fragment" >/dev/null; then
        exec 9>/run/lock/orbit-caddy.lock
        flock 9
        touch /tmp/orb181-caddy-lock-held
        while [ ! -f /tmp/orb181-caddy-lock-release ]; do
            sleep 0.05
        done
        exit 0
    fi
    sleep 0.01
done
exit 1
BASH
    HELPER_PID=$!
}

wait_for_remote_marker() {
    local marker=$1
    local request_pid=$2
    local output=$3
    for _ in $(seq 1 400); do
        if ! kill -0 "$request_pid" 2>/dev/null; then
            set +e
            wait "$request_pid"
            set -e
            cat "$output" >&2
            return 65
        fi
        if remote_script app-dev "$marker" <<'BASH'
test -f "$1"
BASH
        then
            return
        fi
        sleep 0.05
    done
    return 65
}

wait_for_dns_waiter() {
    local request_pid=$1
    local output=$2
    for _ in $(seq 1 200); do
        if ! kill -0 "$request_pid" 2>/dev/null; then
            set +e
            wait "$request_pid"
            set -e
            cat "$output" >&2
            return 65
        fi
        if ps -eo args= | grep -Fx 'flock -w 30 9' >/dev/null; then
            return
        fi
        sleep 0.05
    done
    return 65
}

release_caddy_lock() {
    remote_script app-dev <<'BASH'
touch /tmp/orb181-caddy-lock-release
BASH
    wait "$HELPER_PID"
}

assert_exact_unavailable_response() {
    local hostname=$1
    local prefix=$2
    local status
    status=$(curl -sS --connect-timeout 10 --max-time 20 --resolve "$hostname:443:$app_dev_ip" \
        -D "/tmp/$prefix.headers" \
        -o "/tmp/$prefix.body" \
        -w '%{http_code}' \
        "https://$hostname")
    test "$status" = 503
    python3 -c '
from pathlib import Path
import sys

headers = Path(sys.argv[1]).read_text(encoding="iso-8859-1").splitlines()
fields = {}
for line in headers[1:]:
    if ":" not in line:
        continue
    name, value = line.split(":", 1)
    fields.setdefault(name.lower(), []).append(value.strip())
if fields.get("content-type") != ["text/plain; charset=utf-8"]:
    raise SystemExit(65)
if fields.get("cache-control") != ["no-store"]:
    raise SystemExit(65)
if Path(sys.argv[2]).read_bytes() != b"Orbit Route unavailable\\n":
    raise SystemExit(65)
' "/tmp/$prefix.headers" "/tmp/$prefix.body"
}


prove_hostname_reuse() {
    local hostname=$1
    local name=$2
    local commit instance_id status contact_before contact_after

    read -r commit _ < <(make_checkout "$name" "$name" clean)
    instance_id=$(seed_dev "$name" checkout "$name" "$commit" "$hostname" | seed_id)
    gateway_fixture project-dev "$name"
    install_fpm_access_probe "$instance_id"
    contact_before=$(fpm_access_count "$instance_id")
    status=$(curl -sS --connect-timeout 10 --max-time 20 --resolve "$hostname:443:$app_dev_ip" \
        -o /dev/null -w '%{http_code}' "https://$hostname")
    printf '%s\n' "$status" | grep -E '^[1-5][0-9][0-9]$' >/dev/null
    contact_after=$(fpm_access_count "$instance_id")
    test "$contact_after" -gt "$contact_before"
    remove_success "$instance_id" 0
    gateway_fixture hostname-free "$hostname"
    assert_source_absent "$name"
    assert_completed_evidence "$name"
}

prove_traffic_cutoff() {
    local name=$1
    local hostname=$2
    local force=$3
    local mode=$4
    local commit instance_id contact_before contact_after removal_pid unavailable_published output

    read -r commit _ < <(make_checkout "$name" "$name" "$mode")
    instance_id=$(seed_dev "$name" checkout "$name" "$commit" "$hostname" | seed_id)
    gateway_fixture project-dev "$name"
    install_fpm_access_probe "$instance_id"
    curl -sS --connect-timeout 10 --max-time 20 --resolve "$hostname:443:$app_dev_ip" "https://$hostname" >/dev/null
    contact_before=$(fpm_access_count "$instance_id")
    test "$contact_before" -ge 1

    hold_dns_lock
    removal_pid=
    trap 'release_dns_lock; if [ -n "$removal_pid" ]; then wait "$removal_pid" || true; fi' EXIT
    if [ "$force" = 1 ]; then
        remote_command app-dev orbit instance:remove "$instance_id" --force --json > "/tmp/$name-output" 2>&1 &
    else
        remote_command app-dev orbit instance:remove "$instance_id" --json > "/tmp/$name-output" 2>&1 &
    fi
    removal_pid=$!

    unavailable_published=0
    for _ in $(seq 1 300); do
        if ! kill -0 "$removal_pid" 2>/dev/null; then
            set +e
            wait "$removal_pid"
            status=$?
            set -e
            cat "/tmp/$name-output" >&2
            return 65
        fi
        if remote_script app-dev "$hostname" <<'BASH'
hostname=$1
current=$(sudo readlink -f /etc/caddy/Caddyfile)
fragment="$(dirname "$current")/fragments/app-dev.caddy"
sudo grep -F "https://$hostname" "$fragment" >/dev/null
sudo grep -F 'respond "Orbit Route unavailable\n" 503' "$fragment" >/dev/null
BASH
        then
            unavailable_published=1
            break
        fi
        sleep 0.05
    done
    test "$unavailable_published" = 1
    gateway_fixture instance-state "$name" | python3 -c '
import json
import sys

value = json.load(sys.stdin)
if value.get("status") != "removing" or value.get("routes") != []:
    raise SystemExit(65)
'
    remote_script app-dev "$name" <<'BASH'
test -d "/home/orbit/apps/laravel-typed/$1"
BASH
    assert_exact_unavailable_response "$hostname" "$name"
    contact_after=$(fpm_access_count "$instance_id")
    test "$contact_before" = "$contact_after"

    release_dns_lock
    trap 'if [ -n "$removal_pid" ]; then wait "$removal_pid" || true; fi' EXIT
    wait "$removal_pid"
    removal_pid=
    trap - EXIT
    output=$(cat "/tmp/$name-output")
    assert_successful_removal "$output" "$instance_id" "$force" 1
    gateway_fixture hostname-free "$hostname"
    assert_source_absent "$name"
    assert_completed_evidence "$name"
}

case "$scenario" in
    single-development-removal)
        probe_gateway
        test -f "$fixture"

        normal_hostname=orb181-normal.orbit
        read -r normal_commit _ < <(make_checkout orb181-normal orb181-normal clean)
        read -r normal_id normal_route_id < <(
            seed_dev orb181-normal checkout orb181-normal "$normal_commit" "$normal_hostname" | seed_identity
        )
        gateway_fixture project-dev orb181-normal
        install_route_firewall_artifact "$normal_route_id"
        remove_success "$normal_id" 0
        gateway_fixture hostname-free "$normal_hostname"
        assert_removal_projection_absent "$normal_id" "$normal_route_id" "$normal_hostname"
        assert_source_absent orb181-normal
        assert_completed_evidence orb181-normal
        prove_hostname_reuse "$normal_hostname" orb181-normal-reuse

        forced_hostname=orb181-forced.orbit
        read -r forced_commit _ < <(make_checkout orb181-forced orb181-forced dirty)
        read -r forced_id forced_route_id < <(
            seed_dev orb181-forced checkout orb181-forced "$forced_commit" "$forced_hostname" | seed_identity
        )
        gateway_fixture project-dev orb181-forced
        install_route_firewall_artifact "$forced_route_id"
        install_fpm_access_probe "$forced_id"
        curl -sS --connect-timeout 10 --max-time 20 --resolve "$forced_hostname:443:$app_dev_ip" \
            "https://$forced_hostname" >/dev/null
        contact_before=$(fpm_access_count "$forced_id")
        before=$(gateway_fixture instance-state orb181-forced)
        source_before=$(remote_command app-dev git -C /home/orbit/apps/laravel-typed/orb181-forced status --short)
        test "$source_before" = '?? orb181-dirty.txt'
        expect_remove_failure "$forced_id" 0 instance.remove_refused
        assert_active_unchanged "$before" orb181-forced
        source_after=$(remote_command app-dev git -C /home/orbit/apps/laravel-typed/orb181-forced status --short)
        test "$source_after" = "$source_before"
        remote_script app-dev <<'BASH'
test "$(cat /home/orbit/apps/laravel-typed/orb181-forced/orb181-dirty.txt)" = 'dirty source'
BASH
        curl -sS --connect-timeout 10 --max-time 20 --resolve "$forced_hostname:443:$app_dev_ip" \
            "https://$forced_hostname" >/dev/null
        contact_after=$(fpm_access_count "$forced_id")
        test "$contact_after" -gt "$contact_before"

        remove_success "$forced_id" 1
        gateway_fixture hostname-free "$forced_hostname"
        assert_removal_projection_absent "$forced_id" "$forced_route_id" "$forced_hostname"
        assert_source_absent orb181-forced
        assert_completed_evidence orb181-forced
        prove_hostname_reuse "$forced_hostname" orb181-forced-reuse
        ;;

    development-removal-traffic-cutoff)
        prove_traffic_cutoff orb181-cutoff-normal orb181-cutoff-normal.orbit 0 clean
        prove_traffic_cutoff orb181-cutoff-forced orb181-cutoff-forced.orbit 1 dirty
        ;;

    development-removal-retry)
        initial_hostname=orb181-retry-initial.orbit
        read -r initial_commit _ < <(make_checkout orb181-retry-initial orb181-retry-initial clean)
        initial_id=$(seed_dev orb181-retry-initial checkout orb181-retry-initial "$initial_commit" "$initial_hostname" | seed_id)
        gateway_fixture project-dev orb181-retry-initial
        hold_dns_lock
        removal_pid=
        trap 'release_dns_lock; if [ -n "$removal_pid" ]; then wait "$removal_pid" || true; fi; restore_dns' EXIT
        remote_command app-dev orbit instance:remove "$initial_id" --json > /tmp/orb181-retry-initial-output 2>&1 &
        removal_pid=$!
        unavailable_published=0
        for _ in $(seq 1 300); do
            if ! kill -0 "$removal_pid" 2>/dev/null; then
                set +e
                wait "$removal_pid"
                set -e
                cat /tmp/orb181-retry-initial-output >&2
                exit 65
            fi
            if remote_script app-dev "$initial_hostname" <<'BASH'
hostname=$1
current=$(sudo readlink -f /etc/caddy/Caddyfile)
fragment="$(dirname "$current")/fragments/app-dev.caddy"
sudo grep -F "https://$hostname" "$fragment" >/dev/null
sudo grep -F 'respond "Orbit Route unavailable\n" 503' "$fragment" >/dev/null
BASH
            then
                unavailable_published=1
                break
            fi
            sleep 0.05
        done
        test "$unavailable_published" = 1
        inject_dns_failure
        release_dns_lock
        trap 'if [ -n "$removal_pid" ]; then wait "$removal_pid" || true; fi; restore_dns' EXIT
        set +e
        wait "$removal_pid"
        removal_status=$?
        set -e
        test "$removal_status" -ne 0
        LAST_FAILURE=$(cat /tmp/orb181-retry-initial-output)
        assert_failure_progress "$initial_id" route_target_clear 1 0 1
        assert_route_checkpoint orb181-retry-initial route_target_clear pending
        remote_script app-dev <<'BASH'
test -d /home/orbit/apps/laravel-typed/orb181-retry-initial
BASH
        restore_dns
        trap - EXIT
        remove_success "$initial_id" 0
        gateway_fixture hostname-free "$initial_hostname"
        assert_source_absent orb181-retry-initial
        assert_completed_evidence orb181-retry-initial

        late_hostname=orb181-retry-late.orbit
        read -r late_commit _ < <(make_checkout orb181-retry-late orb181-retry-late clean)
        late_id=$(seed_dev orb181-retry-late checkout orb181-retry-late "$late_commit" "$late_hostname" | seed_id)
        gateway_fixture project-dev orb181-retry-late
        hold_second_caddy_publication "$late_hostname"
        removal_pid=
        trap 'remote_script app-dev <<<"touch /tmp/orb181-caddy-lock-release" || true; wait "$HELPER_PID" || true; if [ -n "$removal_pid" ]; then wait "$removal_pid" || true; fi; restore_dns' EXIT
        remote_command app-dev orbit instance:remove "$late_id" --json > /tmp/orb181-retry-late-output 2>&1 &
        removal_pid=$!
        wait_for_remote_marker /tmp/orb181-caddy-lock-held "$removal_pid" /tmp/orb181-retry-late-output
        sudo flock /run/lock/orbit-dnsmasq.lock true
        inject_dns_failure
        release_caddy_lock
        set +e
        wait "$removal_pid"
        removal_status=$?
        set -e
        test "$removal_status" -ne 0
        LAST_FAILURE=$(cat /tmp/orb181-retry-late-output)
        assert_failure_progress "$late_id" route_target_clear 1 0 1
        assert_route_checkpoint orb181-retry-late route_target_clear pending
        remote_script app-dev "$late_id" <<'BASH'
id=$1
test ! -e "/etc/caddy/orbit-certificates/app-instance-$id"
test -d /home/orbit/apps/laravel-typed/orb181-retry-late
BASH
        restore_dns
        trap - EXIT

        hold_dns_lock
        trap 'release_dns_lock; wait "$removal_pid" || true' EXIT
        remote_command app-dev orbit instance:remove "$late_id" --json > /tmp/orb181-retry-late-success 2>&1 &
        removal_pid=$!
        wait_for_dns_waiter "$removal_pid" /tmp/orb181-retry-late-success
        remote_script app-dev "$late_hostname" <<'BASH'
hostname=$1
current=$(sudo readlink -f /etc/caddy/Caddyfile)
fragment="$(dirname "$current")/fragments/app-dev.caddy"
! sudo grep -F "https://$hostname" "$fragment" >/dev/null
! sudo grep -F 'respond "Orbit Route unavailable\n" 503' "$fragment" >/dev/null
BASH
        release_dns_lock
        trap 'wait "$removal_pid" || true' EXIT
        wait "$removal_pid"
        trap - EXIT
        assert_successful_removal "$(cat /tmp/orb181-retry-late-success)" "$late_id" 0 1
        gateway_fixture hostname-free "$late_hostname"
        assert_source_absent orb181-retry-late
        assert_completed_evidence orb181-retry-late

        final_hostname=orb181-retry-final.orbit
        read -r final_commit _ < <(make_checkout orb181-retry-final orb181-retry-final clean)
        final_id=$(seed_dev orb181-retry-final checkout orb181-retry-final "$final_commit" "$final_hostname" | seed_id)
        gateway_fixture project-dev orb181-retry-final
        gateway_fixture install-final-trigger
        trap 'gateway_fixture drop-final-trigger' EXIT
        expect_remove_failure "$final_id" 0 instance.removal_incomplete
        assert_failure_progress "$final_id" row_deletion 1 0 1
        gateway_fixture removal-evidence orb181-retry-final | python3 -c '
import json
import sys

value = json.load(sys.stdin)
member = value["members"][0]
if member.get("runtime_cleaned_at") is None or member.get("row_deleted_at") is not None:
    raise SystemExit(65)
'
        assert_source_absent orb181-retry-final
        gateway_fixture hostname-free "$final_hostname"
        gateway_fixture instance-state orb181-retry-final | python3 -c '
import json
import sys

value = json.load(sys.stdin)
if value.get("id") != int(sys.argv[1]) or value.get("status") != "removing" or value.get("routes") != []:
    raise SystemExit(65)
' "$final_id"
        gateway_fixture drop-final-trigger
        trap - EXIT
        remove_success "$final_id" 0
        gateway_fixture hostname-free "$final_hostname"
        assert_completed_evidence orb181-retry-final
        ;;

    *)
        printf 'Unknown ORB-181 proof scenario: %s\n' "$scenario" >&2
        exit 64
        ;;
esac

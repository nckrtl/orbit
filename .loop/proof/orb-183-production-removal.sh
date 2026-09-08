#!/usr/bin/env bash
set -euo pipefail

scenario="${1:-}"
repository=/home/orbit/orbit
gateway="$repository/apps/gateway"
fixture=/var/lib/orbit-e2e/proof/orb-183-production-removal.php
ssh_key=/home/orbit/.orbit/ssh/id_ed25519
known_hosts=/home/orbit/.orbit/ssh/known_hosts
app_dev_ip=10.44.0.2
app_prod_ip=10.44.0.3
app_prod_2_ip=10.44.0.4
production_user=orbit-laravel-typed
dnsmasq_backup=/tmp/orb183-dnsmasq.conf

if [ ! -f "$fixture" ]; then
    fixture="$repository/.loop/proof/orb-183-production-removal.php"
fi

node_ip() {
    case "$1" in
        app-dev) printf '%s\n' "$app_dev_ip" ;;
        app-prod) printf '%s\n' "$app_prod_ip" ;;
        app-prod-2) printf '%s\n' "$app_prod_2_ip" ;;
        *) return 64 ;;
    esac
}

remote_command() {
    local node=$1
    shift
    timeout --signal=TERM --kill-after=5s 180s ssh \
        -i "$ssh_key" -o BatchMode=yes -o IdentitiesOnly=yes \
        -o StrictHostKeyChecking=yes -o ConnectTimeout=10 \
        -o ServerAliveInterval=5 -o ServerAliveCountMax=3 \
        -o "UserKnownHostsFile=$known_hosts" \
        "orbit@$(node_ip "$node")" "$@"
}

remote_script() {
    local node=$1
    shift
    remote_command "$node" bash -seu -- "$@"
}

gateway_fixture() {
    (cd "$gateway" && timeout --signal=TERM --kill-after=5s 240s php "$fixture" "$@")
}

curl_https() {
    local path=${3:-}
    curl --fail --silent --show-error --connect-timeout 5 --max-time 20 \
        --resolve "$1:443:$2" "https://$1$path"
}

assert_topology() {
    python3 -c '
import json, sys
value = json.load(sys.stdin)
cluster = value.get("cluster_id")
nodes = {node.get("name"): node for node in value.get("nodes", [])}
if not isinstance(cluster, int) or sorted(nodes) != ["app-dev", "app-prod", "app-prod-2", "gateway"]:
    raise SystemExit(65)
if value.get("router", {}).get("name") != "app-dev":
    raise SystemExit(65)
for name, address in (("app-dev", "10.44.0.2"), ("app-prod", "10.44.0.3"), ("app-prod-2", "10.44.0.4"), ("gateway", "10.44.0.1")):
    if nodes[name].get("status") != "active" or nodes[name].get("address") != address:
        raise SystemExit(65)
for name in ("app-dev", "app-prod", "app-prod-2"):
    if nodes[name].get("cluster_id") != cluster:
        raise SystemExit(65)
for name in ("app-prod", "app-prod-2"):
    if nodes[name].get("active_app_prod") is not True:
        raise SystemExit(65)
'
}

probe_gateway_fpm() {
    curl_https gateway.orbit 10.44.0.1 /up >/dev/null
}

probe_clients() {
    timeout --signal=TERM --kill-after=5s 30s orbit node:list --json >/dev/null
    remote_command app-dev orbit node:list --json >/dev/null
    probe_gateway_fpm
}

prepare_user() {
    remote_script "$1" "$production_user" <<'BASH'
user=$1
root=/var/www/laravel-typed
if getent passwd "$user" >/dev/null; then
    entry=$(getent passwd "$user")
    test "$(printf '%s' "$entry" | cut -d: -f6)" = "$root"
    test "$(printf '%s' "$entry" | cut -d: -f7)" = /usr/sbin/nologin
else
    sudo useradd --system --user-group --home-dir "$root" --shell /usr/sbin/nologin -- "$user"
fi
sudo usermod --append --groups "$user" caddy
id -nG caddy | tr ' ' '\n' | grep -Fx -- "$user" >/dev/null
if [ -e "$root" ] || [ -L "$root" ]; then
    test -d "$root"
    test ! -L "$root"
    test "$(stat -c %U:%G -- "$root")" = "$user:$user"
    sudo chmod 0710 -- "$root"
else
    sudo install -d -o "$user" -g "$user" -m 0710 -- "$root"
fi
sudo systemctl restart caddy
test "$(systemctl is-active caddy)" = active
BASH
}

install_content() {
    remote_script "$1" "$production_user" "$2" "$3" <<'BASH'
user=$1
name=$2
response=$3
case "$name:$response" in orb183-[a-z0-9-]*:orb183-[a-z0-9-]*) ;; *) exit 64 ;; esac
root="/var/www/laravel-typed/$name"
test ! -e "$root"
candidate=$(mktemp)
trap 'rm -f -- "$candidate"' EXIT
printf '%s\n' '<?php' 'header("Content-Type: text/plain");' "echo \"$response\\n\";" > "$candidate"
sudo install -d -o "$user" -g "$user" -m 0710 -- "$root"
sudo install -d -o "$user" -g "$user" -m 0750 -- "$root/public"
sudo install -o "$user" -g "$user" -m 0640 -- "$candidate" "$root/public/index.php"
BASH
}

content_evidence() {
    remote_script "$1" "$production_user" "$2" <<'BASH'
user=$1
name=$2
root="/var/www/laravel-typed/$name"
test "$(sudo stat -c %U:%G -- "$root")" = "$user:$user"
sudo sha256sum -- "$root/public/index.php" | cut -d ' ' -f 1
sudo stat -c '%u:%g:%a' -- "$root" "$root/public" "$root/public/index.php"
BASH
}

assert_content() {
    local after
    after=$(content_evidence "$1" "$2")
    test "$after" = "$3"
}

workload_projection() {
    local expectation=$1
    local node=$2
    local id=$3
    local hostname=$4
    remote_script "$node" "$expectation" "$id" "$hostname" <<'BASH'
expectation=$1
id=$2
hostname=$3
current=$(sudo readlink -f /etc/caddy/Caddyfile)
fragment="$(dirname "$current")/fragments/app-dev.caddy"
pool="[orbit-app-instance-$id]"
if [ "$expectation" = present ]; then
    sudo grep -F -- "https://$hostname" "$fragment" >/dev/null
    sudo grep -F -- "$pool" /etc/php/8.5/fpm/pool.d/orbit-scopes.conf >/dev/null
    test -S "/run/php/orbit-app-instance-$id.sock"
    test -f "/home/orbit/.orbit/certificates/app-instance-$id/current/cert.pem"
    sudo test -f "/etc/caddy/orbit-certificates/app-instance-$id/current/cert.pem"
else
    ! sudo grep -F -- "https://$hostname" "$fragment" >/dev/null
    if [ "$expectation" = route-absent-runtime-present ]; then
        sudo grep -F -- "$pool" /etc/php/8.5/fpm/pool.d/orbit-scopes.conf >/dev/null
        test -S "/run/php/orbit-app-instance-$id.sock"
    else
        if [ -f /etc/php/8.5/fpm/pool.d/orbit-scopes.conf ]; then
            ! sudo grep -F -- "$pool" /etc/php/8.5/fpm/pool.d/orbit-scopes.conf >/dev/null
        fi
        test ! -S "/run/php/orbit-app-instance-$id.sock"
    fi
    test ! -e "/home/orbit/.orbit/certificates/app-instance-$id"
    sudo test ! -e "/etc/caddy/orbit-certificates/app-instance-$id"
fi
BASH
}

router_projection() {
    local expectation=$1
    local route_id=$2
    local hostname=$3
    local present_address=${4:-}
    local absent_address=${5:-}
    remote_script app-dev "$expectation" "$route_id" "$hostname" "$present_address" "$absent_address" <<'BASH'
expectation=$1
route_id=$2
hostname=$3
present=${4:-}
absent=${5:-}
current=$(sudo readlink -f /etc/caddy/Caddyfile)
fragment="$(dirname "$current")/fragments/app-dev.caddy"
if [ "$expectation" = present ]; then
    sudo grep -F -- "https://$hostname" "$fragment" >/dev/null
    sudo grep -F -- "https://$present" "$fragment" >/dev/null
    if [ -n "$absent" ]; then ! sudo grep -F -- "https://$absent" "$fragment" >/dev/null; fi
    test -f "/home/orbit/.orbit/certificates/route-$route_id-router/current/cert.pem"
    sudo test -f "/etc/caddy/orbit-certificates/route-$route_id-router/current/cert.pem"
else
    ! sudo grep -F -- "https://$hostname" "$fragment" >/dev/null
    test ! -e "/home/orbit/.orbit/certificates/route-$route_id-router"
    sudo test ! -e "/etc/caddy/orbit-certificates/route-$route_id-router"
fi
BASH
    if [ "$expectation" = present ] || [ "$expectation" = absent-dns-retained ]; then
        sudo grep -F -- "$hostname" /etc/dnsmasq.d/orbit-records.conf >/dev/null
    else
        ! sudo grep -F -- "$hostname" /etc/dnsmasq.d/orbit-records.conf >/dev/null
    fi
}

assert_backends() {
    local responses
    responses=$(mktemp)
    trap 'rm -f -- "$responses"' RETURN
    for _ in $(seq 1 24); do curl_https "$1" "$2" >> "$responses"; done
    python3 -c '
from pathlib import Path
import sys
if set(Path(sys.argv[1]).read_text().splitlines()) != {sys.argv[2], sys.argv[3]}:
    raise SystemExit(65)
' "$responses" "$3" "$4"
    rm -f -- "$responses"
    trap - RETURN
}

assert_backend() {
    for _ in $(seq 1 5); do test "$(curl_https "$1" "$2")" = "$3"; done
}

remove_success() {
    local output
    output=$(remote_command app-dev orbit instance:remove "$1" --json)
    python3 -c '
import json, sys
value = json.loads(sys.argv[1])
if not (value.get("id") == int(sys.argv[2]) and value.get("force") is False
        and value.get("status") == "completed" and value.get("current_step") is None
        and value.get("total") == value.get("completed") == 1 and value.get("remaining") == 0
        and value.get("failed_step") is None and value.get("error_code") is None):
    raise SystemExit(65)
' "$output" "$1"
}

remove_failure() {
    local output status
    set +e
    output=$(remote_command app-dev orbit instance:remove "$1" --json 2>&1)
    status=$?
    set -e
    test "$status" -ne 0
    python3 -c '
import json, sys
value = json.loads(sys.argv[1])
error = value.get("error", {})
removal = error.get("details", {}).get("removal", {})
if not (error.get("code") == sys.argv[3] and isinstance(error.get("request_id"), str)
        and removal.get("id") == int(sys.argv[2]) and removal.get("force") is False
        and removal.get("status") == "failed"
        and removal.get("current_step") == removal.get("failed_step") == "route_target_clear"
        and removal.get("completed") == 0 and removal.get("remaining") == removal.get("total") == 1
        and removal.get("error_code") == sys.argv[3]):
    raise SystemExit(65)
' "$output" "$1" "$2"
}

removal_evidence() {
    gateway_fixture removal-evidence "$1" | python3 -c '
import json, sys
value = json.load(sys.stdin)
member = value.get("member", {})
expected = sys.argv[1]
if expected == "failed":
    valid = (value.get("status") == "failed"
        and value.get("current_step") == value.get("failed_step") == "route_target_clear"
        and value.get("error_code") == "app-dev.dns_config_failed"
        and value.get("app_instance_exists") is True and value.get("app_instance_status") == "removing"
        and isinstance(member.get("source_prepared_at"), str)
        and all(member.get(key) is None for key in ("route_cleared_at", "source_finalized_at", "runtime_cleaned_at", "row_deleted_at", "route_outcome", "receipt")))
else:
    times = [member.get(key) for key in ("source_prepared_at", "route_cleared_at", "source_finalized_at", "runtime_cleaned_at", "row_deleted_at")]
    valid = (value.get("status") == "completed" and value.get("current_step") is None
        and value.get("failed_step") is None and value.get("error_code") is None
        and value.get("app_instance_exists") is False and member.get("route_outcome") == expected
        and all(isinstance(item, str) for item in times) and times == sorted(times)
        and isinstance(member.get("receipt"), str) and len(member["receipt"]) == 64)
if not valid: raise SystemExit(65)
' "$2"
}

route_state() {
    gateway_fixture route-state "$1" | python3 -c '
import json, sys
value = json.load(sys.stdin)
expected = sys.argv[1]
if expected == "absent":
    valid = value == {"exists": False, "targets": []}
elif expected == "empty":
    valid = value.get("exists") is True and value.get("status") == "active" and value.get("targets") == []
else:
    targets = value.get("targets", [])
    valid = (value.get("exists") is True and value.get("status") == "active"
        and len(targets) == 1 and targets[0].get("id") == int(expected) and targets[0].get("position") == 0)
if not valid: raise SystemExit(65)
' "$2"
}

inject_dns_failure() {
    test ! -e "$dnsmasq_backup"
    sudo cp --preserve=mode,ownership,timestamps -- /etc/dnsmasq.conf "$dnsmasq_backup"
    printf '\norb183-invalid-directive\n' | sudo tee -a /etc/dnsmasq.conf >/dev/null
    ! sudo dnsmasq --test --conf-file=/etc/dnsmasq.conf >/dev/null 2>&1
}

restore_dns() {
    sudo rm -f -- /etc/dnsmasq.d/orb183-invalid.conf
    if [ -f "$dnsmasq_backup" ]; then
        sudo cp --preserve=mode,ownership,timestamps -- "$dnsmasq_backup" /etc/dnsmasq.conf
        sudo rm -f -- "$dnsmasq_backup"
    fi
    sudo dnsmasq --test --conf-file=/etc/dnsmasq.conf >/dev/null
    sudo systemctl reset-failed dnsmasq
    sudo systemctl restart dnsmasq
    test "$(systemctl is-active dnsmasq)" = active
}

read_seed_one() {
    python3 -c '
import json, sys
value=json.loads(sys.argv[1]); instance=value["instances"][0]
print(value["route_id"], value["hostname"], value["router_address"], instance["id"], instance["name"], instance["address"])
' "$1"
}

case "$scenario" in
    setup)
        test -f "$fixture"
        restore_dns
        gateway_fixture prepare-topology | assert_topology
        prepare_user app-prod
        prepare_user app-prod-2
        probe_clients
        ;;
    shared-production-target-removal)
        seed=$(gateway_fixture seed orb183-shared orb183-shared.orbit 2)
        read -r route_id hostname router_ip first_id first_name first_ip second_id second_name second_ip < <(python3 -c '
import json, sys
value=json.loads(sys.argv[1]); one,two=value["instances"]
print(value["route_id"],value["hostname"],value["router_address"],one["id"],one["name"],one["address"],two["id"],two["name"],two["address"])
' "$seed")
        test "$first_ip:$second_ip" = "$app_prod_ip:$app_prod_2_ip"
        install_content app-prod "$first_name" orb183-shared-one
        install_content app-prod-2 "$second_name" orb183-shared-two
        first_content=$(content_evidence app-prod "$first_name")
        second_content=$(content_evidence app-prod-2 "$second_name")
        gateway_fixture project "$route_id"
        workload_projection present app-prod "$first_id" "$hostname"
        workload_projection present app-prod-2 "$second_id" "$hostname"
        router_projection present "$route_id" "$hostname" "$first_ip"
        router_projection present "$route_id" "$hostname" "$second_ip"
        assert_backends "$hostname" "$router_ip" orb183-shared-one orb183-shared-two
        remove_success "$first_id"
        route_state "$route_id" "$second_id"
        removal_evidence "$first_name" retained
        workload_projection absent app-prod "$first_id" "$hostname"
        workload_projection present app-prod-2 "$second_id" "$hostname"
        router_projection present "$route_id" "$hostname" "$second_ip" "$first_ip"
        assert_backend "$hostname" "$router_ip" orb183-shared-two
        assert_content app-prod "$first_name" "$first_content"
        assert_content app-prod-2 "$second_name" "$second_content"
        gateway_fixture topology | assert_topology
        probe_gateway_fpm
        ;;
    final-production-target-removal)
        hostname=orb183-reusable.orbit
        seed=$(gateway_fixture seed orb183-final "$hostname" 1)
        read -r route_id _ router_ip id name address < <(read_seed_one "$seed")
        install_content app-prod "$name" orb183-final-original
        before=$(content_evidence app-prod "$name")
        gateway_fixture project "$route_id"
        workload_projection present app-prod "$id" "$hostname"
        router_projection present "$route_id" "$hostname" "$address"
        assert_backend "$hostname" "$router_ip" orb183-final-original
        remove_success "$id"
        route_state "$route_id" absent
        gateway_fixture hostname-free "$hostname"
        removal_evidence "$name" deleted
        workload_projection absent app-prod "$id" "$hostname"
        router_projection absent "$route_id" "$hostname"
        assert_content app-prod "$name" "$before"
        reuse=$(gateway_fixture seed orb183-reuse "$hostname" 1)
        read -r reuse_route _ reuse_router reuse_id reuse_name reuse_address < <(read_seed_one "$reuse")
        install_content app-prod "$reuse_name" orb183-final-reused
        reuse_before=$(content_evidence app-prod "$reuse_name")
        gateway_fixture project "$reuse_route"
        assert_backend "$hostname" "$reuse_router" orb183-final-reused
        remove_success "$reuse_id"
        route_state "$reuse_route" absent
        gateway_fixture hostname-free "$hostname"
        removal_evidence "$reuse_name" deleted
        workload_projection absent app-prod "$reuse_id" "$hostname"
        router_projection absent "$reuse_route" "$hostname"
        assert_content app-prod "$reuse_name" "$reuse_before"
        gateway_fixture topology | assert_topology
        probe_gateway_fpm
        ;;
    production-removal-retry)
        shared_hostname=orb183-shared-retry.orbit
        shared_seed=$(gateway_fixture seed orb183-shared-retry "$shared_hostname" 2)
        read -r shared_route _ shared_router first_id first_name first_ip second_id second_name second_ip < <(python3 -c '
import json, sys
value=json.loads(sys.argv[1]); one,two=value["instances"]
print(value["route_id"],value["hostname"],value["router_address"],one["id"],one["name"],one["address"],two["id"],two["name"],two["address"])
' "$shared_seed")
        install_content app-prod "$first_name" orb183-shared-retry-one
        install_content app-prod-2 "$second_name" orb183-shared-retry-two
        first_before=$(content_evidence app-prod "$first_name")
        second_before=$(content_evidence app-prod-2 "$second_name")
        gateway_fixture project "$shared_route"
        assert_backends "$shared_hostname" "$shared_router" orb183-shared-retry-one orb183-shared-retry-two
        trap restore_dns EXIT
        inject_dns_failure
        remove_failure "$first_id" app-dev.dns_config_failed
        removal_evidence "$first_name" failed
        route_state "$shared_route" "$second_id"
        shared_failed_operation=$(gateway_fixture removal-evidence "$first_name" | python3 -c 'import json,sys; print(json.load(sys.stdin)["operation_id"])')
        workload_projection route-absent-runtime-present app-prod "$first_id" "$shared_hostname"
        workload_projection present app-prod-2 "$second_id" "$shared_hostname"
        router_projection present "$shared_route" "$shared_hostname" "$second_ip" "$first_ip"
        assert_backend "$shared_hostname" "$shared_router" orb183-shared-retry-two
        assert_content app-prod "$first_name" "$first_before"
        assert_content app-prod-2 "$second_name" "$second_before"
        restore_dns
        trap - EXIT
        remove_success "$first_id"
        route_state "$shared_route" "$second_id"
        removal_evidence "$first_name" retained
        shared_completed_operation=$(gateway_fixture removal-evidence "$first_name" | python3 -c 'import json,sys; print(json.load(sys.stdin)["operation_id"])')
        test "$shared_completed_operation" = "$shared_failed_operation"
        workload_projection absent app-prod "$first_id" "$shared_hostname"
        workload_projection present app-prod-2 "$second_id" "$shared_hostname"
        router_projection present "$shared_route" "$shared_hostname" "$second_ip" "$first_ip"
        assert_backend "$shared_hostname" "$shared_router" orb183-shared-retry-two
        assert_content app-prod "$first_name" "$first_before"
        assert_content app-prod-2 "$second_name" "$second_before"

        hostname=orb183-retry.orbit
        seed=$(gateway_fixture seed orb183-retry "$hostname" 1)
        read -r route_id _ router_ip id name address < <(read_seed_one "$seed")
        install_content app-prod "$name" orb183-retry-original
        before=$(content_evidence app-prod "$name")
        gateway_fixture project "$route_id"
        assert_backend "$hostname" "$router_ip" orb183-retry-original
        trap restore_dns EXIT
        inject_dns_failure
        remove_failure "$id" app-dev.dns_config_failed
        removal_evidence "$name" failed
        route_state "$route_id" empty
        failed_operation=$(gateway_fixture removal-evidence "$name" | python3 -c 'import json,sys; print(json.load(sys.stdin)["operation_id"])')
        workload_projection route-absent-runtime-present app-prod "$id" "$hostname"
        router_projection absent-dns-retained "$route_id" "$hostname"
        assert_content app-prod "$name" "$before"
        restore_dns
        trap - EXIT
        remove_success "$id"
        route_state "$route_id" absent
        gateway_fixture hostname-free "$hostname"
        removal_evidence "$name" deleted
        completed_operation=$(gateway_fixture removal-evidence "$name" | python3 -c 'import json,sys; print(json.load(sys.stdin)["operation_id"])')
        test "$completed_operation" = "$failed_operation"
        workload_projection absent app-prod "$id" "$hostname"
        router_projection absent "$route_id" "$hostname"
        assert_content app-prod "$name" "$before"
        gateway_fixture topology | assert_topology
        probe_gateway_fpm
        ;;
    *)
        printf 'Unknown ORB-183 proof scenario: %s\n' "$scenario" >&2
        exit 64
        ;;
esac

printf 'ORB-183 %s passed\n' "$scenario"

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

remote_command() {
    ssh \
        -i "$ssh_key" \
        -o BatchMode=yes \
        -o IdentitiesOnly=yes \
        -o StrictHostKeyChecking=yes \
        -o ConnectTimeout=10 \
        -o ServerAliveInterval=5 \
        -o ServerAliveCountMax=3 \
        -o "UserKnownHostsFile=$known_hosts" \
        "orbit@$app_dev_ip" \
        "$@"
}

remote_script() {
    remote_command bash -seu -- "$@"
}

gateway_fixture() {
    (
        cd "$gateway"
        php "$fixture" "$@"
    )
}

make_worktree_graph() {
    local root=$1
    local root_branch=$2
    shift 2
    remote_script "$root" "$root_branch" "$@" <<'BASH'
root_name=$1
root_branch=$2
shift 2
case "$root_name" in orb182-[a-z0-9-]*) ;; *) exit 64 ;; esac
root="/home/orbit/apps/laravel-typed/$root_name"
test ! -e "$root"
git clone --local --no-checkout /home/orbit/apps/laravel-typed/e2e-dev "$root" >/dev/null
git -C "$root" remote set-url origin https://github.com/laravel/laravel.git
git -C "$root" checkout -b "$root_branch" HEAD >/dev/null
git -C "$root" config user.name 'Orbit proof'
git -C "$root" config user.email orbit-proof@example.invalid
while [ "$#" -gt 0 ]; do
    child=$1
    branch=$2
    shift 2
    case "$child" in orb182-[a-z0-9-]*) ;; *) exit 64 ;; esac
    path="/home/orbit/apps/laravel-typed/$child"
    test ! -e "$path"
    git -C "$root" worktree add -b "$branch" "$path" HEAD >/dev/null
done
git -C "$root" rev-parse HEAD
BASH
}

seed_dev() {
    gateway_fixture seed-dev "$@"
}

seed_id() {
    python3 -c 'import json, sys; print(json.load(sys.stdin)["id"])'
}

seed_identity() {
    python3 -c 'import json, sys; value = json.load(sys.stdin); print(value["id"], value["route_id"])'
}

expect_remove_failure() {
    local id=$1
    local force=$2
    local expected_code=$3
    local output status
    set +e
    if [ "$force" = 1 ]; then
        output=$(remote_command orbit instance:remove "$id" --force --json 2>&1)
        status=$?
    else
        output=$(remote_command orbit instance:remove "$id" --json 2>&1)
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

assert_successful_removal() {
    local output=$1
    local expected_id=$2
    local expected_total=$3
    python3 -c '
import json
import sys

value = json.loads(sys.argv[1])
total = int(sys.argv[3])
valid = (
    value.get("id") == int(sys.argv[2])
    and value.get("force") is True
    and value.get("status") == "completed"
    and value.get("current_step") is None
    and value.get("total") == total
    and value.get("completed") == total
    and value.get("remaining") == 0
    and value.get("failed_step") is None
    and value.get("error_code") is None
)
if not valid:
    raise SystemExit(65)
' "$output" "$expected_id" "$expected_total"
}

remove_success() {
    local id=$1
    local force=$2
    local total=$3
    local output
    if [ "$force" = 1 ]; then
        output=$(remote_command orbit instance:remove "$id" --force --json)
    else
        output=$(remote_command orbit instance:remove "$id" --json)
        python3 -c '
import json
import sys

value = json.loads(sys.argv[1])
total = int(sys.argv[3])
valid = (
    value.get("id") == int(sys.argv[2])
    and value.get("force") is False
    and value.get("status") == "completed"
    and value.get("total") == total
    and value.get("completed") == total
    and value.get("remaining") == 0
)
if not valid:
    raise SystemExit(65)
' "$output" "$id" "$total"
        return
    fi
    assert_successful_removal "$output" "$id" "$total"
}

assert_active_unchanged() {
    local before=$1
    local name=$2
    test "$before" = "$(gateway_fixture instance-state "$name")"
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

assert_completed_evidence() {
    local name=$1
    local total=$2
    gateway_fixture removal-evidence "$name" | python3 -c '
import json
import sys

value = json.load(sys.stdin)
members = value.get("members", [])
if value.get("status") != "completed" or value.get("total") != int(sys.argv[1]):
    raise SystemExit(65)
if len(members) != int(sys.argv[1]):
    raise SystemExit(65)
previous = None
for member in members:
    times = [member.get(key) for key in (
        "source_prepared_at", "route_cleared_at", "source_finalized_at",
        "runtime_cleaned_at", "row_deleted_at",
    )]
    if any(not isinstance(item, str) for item in times) or times != sorted(times):
        raise SystemExit(65)
    if previous is not None and previous > times[0]:
        raise SystemExit(65)
    previous = times[-1]
    if member.get("route_outcome") != "deleted" or member.get("route_exists") is not False:
        raise SystemExit(65)
    if member.get("app_instance_exists") is not False:
        raise SystemExit(65)
    if not isinstance(member.get("receipt"), str) or len(member["receipt"]) != 64:
        raise SystemExit(65)
' "$total"
}

assert_source_absent() {
    remote_script "$@" <<'BASH'
for name in "$@"; do
    test ! -e "/home/orbit/apps/laravel-typed/$name"
done
BASH
}

install_fpm_access_probe() {
    remote_script "$1" <<'BASH'
instance_id=$1
configuration=/etc/php/8.5/fpm/pool.d/orbit-scopes.conf
pool="[orbit-app-instance-$instance_id]"
access_log="/tmp/orb182-fpm-access-$instance_id.log"
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
    remote_script "$1" <<'BASH'
instance_id=$1
access_log="/tmp/orb182-fpm-access-$instance_id.log"
if sudo test -f "$access_log"; then
    sudo awk 'END { print NR }' "$access_log"
else
    printf '0\n'
fi
BASH
}

assert_fpm_contact() {
    local instance_id=$1
    local hostname=$2
    local before after status
    before=$(fpm_access_count "$instance_id")
    status=$(curl -sS --connect-timeout 10 --max-time 20 --resolve "$hostname:443:$app_dev_ip" \
        -o /dev/null -w '%{http_code}' "https://$hostname")
    printf '%s\n' "$status" | grep -E '^[1-5][0-9][0-9]$' >/dev/null
    after=$(fpm_access_count "$instance_id")
    test "$after" -gt "$before"
}

install_route_firewall_artifact() {
    remote_script "$1" <<'BASH'
route_id=$1
sudo ufw allow in proto tcp from 10.44.0.1 to 10.44.0.2 port 443 comment "orbit:route-$route_id-lan" >/dev/null
sudo ufw status numbered | grep -F -- "# orbit:route-$route_id-lan" >/dev/null
BASH
}

assert_removal_projection_absent() {
    remote_script "$1" "$2" "$3" <<'BASH'
instance_id=$1
route_id=$2
hostname=$3
current=$(sudo readlink -f /etc/caddy/Caddyfile)
fragment="$(dirname "$current")/fragments/app-dev.caddy"
test ! -e "/etc/caddy/orbit-certificates/app-instance-$instance_id"
! sudo grep -F -- "$hostname" "$fragment" >/dev/null
! sudo grep -F -- "# orbit:route-$route_id-lan" < <(sudo ufw status numbered)
BASH
    gateway_fixture hostname-free "$3"
}

mark_content() {
    remote_script "$1" "$2" <<'BASH'
name=$1
mode=$2
path="/home/orbit/apps/laravel-typed/$name"
case "$mode" in
    dirty)
        printf 'dirty worktree\n' > "$path/orb182-dirty.txt"
        ;;
    unpublished)
        printf 'unpublished worktree\n' > "$path/orb182-unpublished.txt"
        git -C "$path" add orb182-unpublished.txt
        git -C "$path" commit -m 'Add unpublished ORB-182 proof commit' >/dev/null
        ;;
    *) exit 64 ;;
esac
BASH
}

break_dns() {
    temporary=$(mktemp)
    printf 'orb182-invalid-directive\n' > "$temporary"
    sudo install -o root -g root -m 0644 "$temporary" /etc/dnsmasq.d/orb182-invalid.conf
    rm -f "$temporary"
}

restore_dns() {
    sudo rm -f -- /etc/dnsmasq.d/orb182-invalid.conf
    sudo dnsmasq --test >/dev/null
    sudo systemctl reset-failed dnsmasq
    sudo systemctl restart dnsmasq
    test "$(systemctl is-active dnsmasq)" = active
}

replace_worktree() {
    local root_name=$1
    local worktree_name=$2
    remote_script "$root_name" "$worktree_name" <<'BASH'
root_name=$1
worktree_name=$2
root="/home/orbit/apps/laravel-typed/$root_name"
path="/home/orbit/apps/laravel-typed/$worktree_name"
backup="/home/orbit/$worktree_name-original"
test ! -e "$backup"
git -C "$root" worktree move "$path" "$backup"
git -C "$root" worktree add --detach "$path" HEAD >/dev/null
test -d "$path"
test -d "$backup"
BASH
}

restore_worktree() {
    local root_name=$1
    local worktree_name=$2
    remote_script "$root_name" "$worktree_name" <<'BASH'
root_name=$1
worktree_name=$2
root="/home/orbit/apps/laravel-typed/$root_name"
path="/home/orbit/apps/laravel-typed/$worktree_name"
backup="/home/orbit/$worktree_name-original"
git -C "$root" worktree remove --force "$path"
git -C "$root" worktree move "$backup" "$path"
test -d "$path"
test ! -e "$backup"
BASH
}

case "$scenario" in
    worktree-removal-boundaries)
        probe_gateway
        test -f "$fixture"

        unregistered_commit=$(make_worktree_graph \
            orb182-unregistered-root orb182-unregistered-root \
            orb182-unregistered-child orb182-unregistered-child)
        unregistered_id=$(seed_dev \
            orb182-unregistered-root checkout orb182-unregistered-root "$unregistered_commit" | seed_id)
        unregistered_before=$(gateway_fixture instance-state orb182-unregistered-root)
        remote_script <<'BASH'
root=/home/orbit/apps/laravel-typed/orb182-unregistered-root
child=/home/orbit/apps/laravel-typed/orb182-unregistered-child
test -d "$child"
test "$(git -C "$root" worktree list --porcelain | grep -c '^worktree ')" = 2
BASH
        expect_remove_failure "$unregistered_id" 0 instance.remove_refused
        assert_active_unchanged "$unregistered_before" orb182-unregistered-root
        remote_script <<'BASH'
root=/home/orbit/apps/laravel-typed/orb182-unregistered-root
child=/home/orbit/apps/laravel-typed/orb182-unregistered-child
test -d "$child"
test "$(git -C "$root" worktree list --porcelain | grep -c '^worktree ')" = 2
BASH
        expect_remove_failure "$unregistered_id" 1 instance.remove_refused
        assert_active_unchanged "$unregistered_before" orb182-unregistered-root
        remote_script <<'BASH'
root=/home/orbit/apps/laravel-typed/orb182-unregistered-root
child=/home/orbit/apps/laravel-typed/orb182-unregistered-child
test -d "$root/.git"
test -d "$child"
git -C "$root" worktree remove --force "$child"
BASH
        remove_success "$unregistered_id" 1 1

        clean_commit=$(make_worktree_graph \
            orb182-clean-root orb182-clean-root \
            orb182-clean-child orb182-clean-child \
            orb182-clean-sibling orb182-clean-sibling)
        read -r clean_root_id clean_root_route < <(
            seed_dev orb182-clean-root checkout orb182-clean-root "$clean_commit" | seed_identity
        )
        read -r clean_child_id clean_child_route < <(
            seed_dev orb182-clean-child worktree orb182-clean-child "$clean_commit" | seed_identity
        )
        clean_sibling_id=$(seed_dev \
            orb182-clean-sibling worktree orb182-clean-sibling "$clean_commit" | seed_id)
        gateway_fixture project-dev orb182-clean-root orb182-clean-child orb182-clean-sibling
        install_fpm_access_probe "$clean_root_id"
        install_fpm_access_probe "$clean_sibling_id"
        clean_before=$(gateway_fixture instance-state orb182-clean-root)
        expect_remove_failure "$clean_root_id" 0 instance.remove_refused
        assert_active_unchanged "$clean_before" orb182-clean-root
        python3 -c '
import json
import sys

if "--force" not in json.loads(sys.argv[1])["error"]["message"]:
    raise SystemExit(65)
' "$LAST_FAILURE"
        remote_before=$(remote_command git -C /home/orbit/apps/laravel-typed/orb182-clean-root \
            ls-remote origin refs/heads/13.x)
        remove_success "$clean_child_id" 0 1
        gateway_fixture hostname-free orb182-clean-child.orbit
        remote_script <<'BASH'
root=/home/orbit/apps/laravel-typed/orb182-clean-root
sibling=/home/orbit/apps/laravel-typed/orb182-clean-sibling
test -d "$root/.git"
test -d "$sibling"
test ! -e /home/orbit/apps/laravel-typed/orb182-clean-child
git -C "$root" show-ref --verify --quiet refs/heads/orb182-clean-child
test -z "$(git -C "$root" status --short)"
test -z "$(git -C "$sibling" status --short)"
test "$(git -C "$root" worktree list --porcelain | grep -c '^worktree ')" = 2
BASH
        remote_after=$(remote_command git -C /home/orbit/apps/laravel-typed/orb182-clean-root \
            ls-remote origin refs/heads/13.x)
        test "$remote_before" = "$remote_after"
        assert_fpm_contact "$clean_root_id" orb182-clean-root.orbit
        assert_fpm_contact "$clean_sibling_id" orb182-clean-sibling.orbit
        remove_success "$clean_root_id" 1 2
        assert_source_absent orb182-clean-root orb182-clean-sibling

        content_commit=$(make_worktree_graph \
            orb182-content-root orb182-content-root \
            orb182-content-dirty orb182-content-dirty \
            orb182-content-unpublished orb182-content-unpublished)
        content_root_id=$(seed_dev \
            orb182-content-root checkout orb182-content-root "$content_commit" | seed_id)
        dirty_id=$(seed_dev \
            orb182-content-dirty worktree orb182-content-dirty "$content_commit" | seed_id)
        unpublished_id=$(seed_dev \
            orb182-content-unpublished worktree orb182-content-unpublished "$content_commit" | seed_id)
        gateway_fixture project-dev orb182-content-root orb182-content-dirty orb182-content-unpublished
        install_fpm_access_probe "$unpublished_id"
        mark_content orb182-content-dirty dirty
        mark_content orb182-content-unpublished unpublished
        dirty_before=$(gateway_fixture instance-state orb182-content-dirty)
        unpublished_before=$(gateway_fixture instance-state orb182-content-unpublished)
        expect_remove_failure "$dirty_id" 0 instance.remove_refused
        assert_active_unchanged "$dirty_before" orb182-content-dirty
        expect_remove_failure "$unpublished_id" 0 instance.remove_refused
        assert_active_unchanged "$unpublished_before" orb182-content-unpublished
        remove_success "$dirty_id" 1 1
        remote_script <<'BASH'
root=/home/orbit/apps/laravel-typed/orb182-content-root
sibling=/home/orbit/apps/laravel-typed/orb182-content-unpublished
test -d "$root/.git"
test -d "$sibling"
test "$(cat "$sibling/orb182-unpublished.txt")" = 'unpublished worktree'
git -C "$root" show-ref --verify --quiet refs/heads/orb182-content-dirty
BASH
        assert_fpm_contact "$unpublished_id" orb182-content-unpublished.orbit
        remove_success "$unpublished_id" 1 1
        remote_script <<'BASH'
root=/home/orbit/apps/laravel-typed/orb182-content-root
test -d "$root/.git"
git -C "$root" show-ref --verify --quiet refs/heads/orb182-content-dirty
git -C "$root" show-ref --verify --quiet refs/heads/orb182-content-unpublished
test "$(git -C "$root" worktree list --porcelain | grep -c '^worktree ')" = 1
BASH
        remove_success "$content_root_id" 0 1
        ;;

    forced-cascade-removal)
        probe_gateway
        cascade_commit=$(make_worktree_graph \
            orb182-cascade-root orb182-cascade-root \
            orb182-cascade-z orb182-cascade-z \
            orb182-cascade-a orb182-cascade-a)
        read -r cascade_root_id cascade_root_route < <(
            seed_dev orb182-cascade-root checkout orb182-cascade-root "$cascade_commit" | seed_identity
        )
        read -r cascade_z_id cascade_z_route < <(
            seed_dev orb182-cascade-z worktree orb182-cascade-z "$cascade_commit" | seed_identity
        )
        read -r cascade_a_id cascade_a_route < <(
            seed_dev orb182-cascade-a worktree orb182-cascade-a "$cascade_commit" | seed_identity
        )
        gateway_fixture project-dev orb182-cascade-root orb182-cascade-z orb182-cascade-a
        install_route_firewall_artifact "$cascade_root_route"
        install_route_firewall_artifact "$cascade_z_route"
        install_route_firewall_artifact "$cascade_a_route"
        remote_before=$(remote_command git -C /home/orbit/apps/laravel-typed/orb182-cascade-root \
            ls-remote origin refs/heads/13.x)
        remove_success "$cascade_root_id" 1 3
        gateway_fixture removal-evidence orb182-cascade-root | python3 -c '
import json
import sys

value = json.load(sys.stdin)
members = value.get("members", [])
names = ["orb182-cascade-a", "orb182-cascade-z", "orb182-cascade-root"]
paths = sorted(f"/home/orbit/apps/laravel-typed/{name}" for name in names)
if [item.get("name") for item in members] != names:
    raise SystemExit(65)
if [item.get("layout") for item in members] != ["worktree", "worktree", "checkout"]:
    raise SystemExit(65)
if [item.get("position") for item in members] != [0, 1, 2]:
    raise SystemExit(65)
if any(item.get("linked_worktree_paths") != paths for item in members):
    raise SystemExit(65)
if any(item.get("common_repository_path") != paths[1] for item in members):
    raise SystemExit(65)
if any(not isinstance(item.get("source_digest"), str) or len(item["source_digest"]) != 64 for item in members):
    raise SystemExit(65)
'
        assert_completed_evidence orb182-cascade-root 3
        assert_source_absent orb182-cascade-a orb182-cascade-z orb182-cascade-root
        remote_after=$(remote_command git ls-remote https://github.com/laravel/laravel.git refs/heads/13.x)
        test "$remote_before" = "$remote_after"
        assert_removal_projection_absent "$cascade_a_id" "$cascade_a_route" orb182-cascade-a.orbit
        assert_removal_projection_absent "$cascade_z_id" "$cascade_z_route" orb182-cascade-z.orbit
        assert_removal_projection_absent "$cascade_root_id" "$cascade_root_route" orb182-cascade-root.orbit
        ;;

    forced-cascade-retry)
        probe_gateway
        retry_commit=$(make_worktree_graph \
            orb182-retry-root orb182-retry-root \
            orb182-retry-a orb182-retry-a \
            orb182-retry-b orb182-retry-b)
        retry_root_id=$(seed_dev orb182-retry-root checkout orb182-retry-root "$retry_commit" | seed_id)
        seed_dev orb182-retry-a worktree orb182-retry-a "$retry_commit" >/dev/null
        seed_dev orb182-retry-b worktree orb182-retry-b "$retry_commit" >/dev/null
        gateway_fixture project-dev orb182-retry-root orb182-retry-a orb182-retry-b
        gateway_fixture install-member-row-trigger orb182-retry-b
        trap 'gateway_fixture drop-member-row-trigger' EXIT
        expect_remove_failure "$retry_root_id" 1 instance.removal_incomplete
        assert_failure_progress "$retry_root_id" row_deletion 3 1 2
        gateway_fixture removal-evidence orb182-retry-root | python3 -c '
import json
import sys

value = json.load(sys.stdin)
members = value["members"]
if [item["name"] for item in members] != ["orb182-retry-a", "orb182-retry-b", "orb182-retry-root"]:
    raise SystemExit(65)
if members[0]["row_deleted_at"] is None or members[0]["app_instance_exists"] is not False:
    raise SystemExit(65)
if members[1]["runtime_cleaned_at"] is None or members[1]["row_deleted_at"] is not None:
    raise SystemExit(65)
if members[1]["app_instance_exists"] is not True or members[2]["route_exists"] is not True:
    raise SystemExit(65)
'
        remote_script <<'BASH'
root=/home/orbit/apps/laravel-typed/orb182-retry-root
new=/home/orbit/apps/laravel-typed/orb182-retry-new
test ! -e "$new"
git -C "$root" worktree add -b orb182-retry-new "$new" HEAD >/dev/null
BASH
        expect_remove_failure "$retry_root_id" 1 instance.removal_conflict
        assert_failure_progress "$retry_root_id" row_deletion 3 1 2
        gateway_fixture removal-evidence orb182-retry-root | python3 -c '
import json
import sys

member = json.load(sys.stdin)["members"][2]
if member.get("route_exists") is not True or member.get("route_targets") == []:
    raise SystemExit(65)
'
        remote_script <<'BASH'
root=/home/orbit/apps/laravel-typed/orb182-retry-root
new=/home/orbit/apps/laravel-typed/orb182-retry-new
test -d "$new"
git -C "$root" worktree remove --force "$new"
BASH
        gateway_fixture drop-member-row-trigger
        trap - EXIT
        remove_success "$retry_root_id" 1 3
        assert_completed_evidence orb182-retry-root 3

        replace_commit=$(make_worktree_graph \
            orb182-replace-root orb182-replace-root \
            orb182-replace-a orb182-replace-a \
            orb182-replace-b orb182-replace-b)
        replace_root_id=$(seed_dev \
            orb182-replace-root checkout orb182-replace-root "$replace_commit" | seed_id)
        seed_dev orb182-replace-a worktree orb182-replace-a "$replace_commit" >/dev/null
        seed_dev orb182-replace-b worktree orb182-replace-b "$replace_commit" >/dev/null
        gateway_fixture project-dev orb182-replace-root orb182-replace-a orb182-replace-b
        break_dns
        trap restore_dns EXIT
        expect_remove_failure "$replace_root_id" 1 app-dev.dns_config_failed
        assert_failure_progress "$replace_root_id" route_target_clear 3 0 3
        restore_dns
        trap - EXIT
        gateway_fixture set-member-owner orb182-replace-b foreign
        expect_remove_failure "$replace_root_id" 1 instance.removal_conflict
        assert_failure_progress "$replace_root_id" route_target_clear 3 0 3
        gateway_fixture set-member-owner orb182-replace-b fixture
        replace_worktree orb182-replace-root orb182-replace-b
        expect_remove_failure "$replace_root_id" 1 instance.removal_conflict
        assert_failure_progress "$replace_root_id" route_target_clear 3 0 3
        remote_script <<'BASH'
test -d /home/orbit/apps/laravel-typed/orb182-replace-root
test -d /home/orbit/apps/laravel-typed/orb182-replace-a
test -d /home/orbit/apps/laravel-typed/orb182-replace-b
BASH
        restore_worktree orb182-replace-root orb182-replace-b
        remove_success "$replace_root_id" 1 3
        assert_completed_evidence orb182-replace-root 3

        final_commit=$(make_worktree_graph \
            orb182-final-root orb182-final-root \
            orb182-final-a orb182-final-a \
            orb182-final-b orb182-final-b)
        final_root_id=$(seed_dev orb182-final-root checkout orb182-final-root "$final_commit" | seed_id)
        seed_dev orb182-final-a worktree orb182-final-a "$final_commit" >/dev/null
        seed_dev orb182-final-b worktree orb182-final-b "$final_commit" >/dev/null
        gateway_fixture project-dev orb182-final-root orb182-final-a orb182-final-b
        gateway_fixture install-final-trigger
        trap 'gateway_fixture drop-final-trigger' EXIT
        expect_remove_failure "$final_root_id" 1 instance.removal_incomplete
        assert_failure_progress "$final_root_id" row_deletion 3 2 1
        gateway_fixture removal-evidence orb182-final-root | python3 -c '
import json
import sys

value = json.load(sys.stdin)
members = value["members"]
if any(member["row_deleted_at"] is None for member in members[:2]):
    raise SystemExit(65)
if any(member["app_instance_exists"] is not False for member in members[:2]):
    raise SystemExit(65)
final = members[2]
if final["runtime_cleaned_at"] is None or final["row_deleted_at"] is not None:
    raise SystemExit(65)
if final["app_instance_exists"] is not True or final["route_exists"] is not False:
    raise SystemExit(65)
'
        gateway_fixture instance-state orb182-final-root | python3 -c '
import json
import sys

value = json.load(sys.stdin)
if value.get("id") != int(sys.argv[1]) or value.get("status") != "removing" or value.get("routes") != []:
    raise SystemExit(65)
' "$final_root_id"
        assert_source_absent orb182-final-a orb182-final-b orb182-final-root
        gateway_fixture drop-final-trigger
        trap - EXIT
        remove_success "$final_root_id" 1 3
        assert_completed_evidence orb182-final-root 3
        ;;

    *)
        printf 'Unknown ORB-182 proof scenario: %s\n' "$scenario" >&2
        exit 64
        ;;
esac

printf 'ORB-182 %s: ok\n' "$scenario"

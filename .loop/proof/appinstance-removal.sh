#!/usr/bin/env bash
set -euo pipefail

scenario="${1:-}"
repository=/home/orbit/orbit
gateway="$repository/apps/gateway"
fixture=/var/lib/orbit-e2e/proof/appinstance-removal.php
ssh_key=/home/orbit/.orbit/ssh/id_ed25519
known_hosts=/home/orbit/.orbit/ssh/known_hosts
app_dev_ip=10.44.0.2
app_prod_ip=10.44.0.3
app_prod_2_ip=10.44.0.4

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
        app-prod) printf '%s\n' "$app_prod_ip" ;;
        app-prod-2) printf '%s\n' "$app_prod_2_ip" ;;
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
case "$name" in orb124-[a-z0-9-]*) ;; *) exit 64 ;; esac
case "$branch" in orb124-[a-z0-9-]*) ;; *) exit 64 ;; esac
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
    dirty) printf 'dirty source\n' > "$path/orb124-dirty.txt" ;;
    unpublished)
        printf 'unpublished source\n' > "$path/orb124-unpublished.txt"
        git -C "$path" add orb124-unpublished.txt
        git -C "$path" commit -m 'Add unpublished proof commit' >/dev/null
        ;;
    endpoint)
        mkdir -p "$path/public"
        printf '%s\n' '<?php' 'file_put_contents("/tmp/orb124-former-target-contact", "hit\n", FILE_APPEND);' 'header("Content-Type: text/plain");' 'echo "former target\n";' > "$path/public/index.php"
        git -C "$path" add public/index.php
        git -C "$path" commit -m 'Add removal contact probe' >/dev/null
        base=$(git -C "$path" rev-parse HEAD)
        rm -f /tmp/orb124-former-target-contact
        ;;
    published-behind)
        advertised_descendant=$(git -C "$path" rev-parse HEAD)
        git -C "$path" reset --hard HEAD^ >/dev/null
        base=$(git -C "$path" rev-parse HEAD)
        while read -r ref; do
            test "$ref" = "refs/heads/$branch" || git -C "$path" update-ref -d "$ref"
        done < <(git -C "$path" for-each-ref --format='%(refname)')
        git -C "$path" reflog expire --expire=now --all
        git -C "$path" gc --prune=now >/dev/null
        ! git -C "$path" cat-file -e "$advertised_descendant^{commit}" 2>/dev/null
        ;;
    wrong-origin) git -C "$path" remote set-url origin https://github.com/laravel/framework.git ;;
    *) exit 64 ;;
esac
printf '%s %s\n' "$base" "$(git -C "$path" rev-parse HEAD)"
BASH
}

make_symlink_checkout() {
    local name=$1
    local branch=$2
    remote_script app-dev "$name" "$branch" <<'BASH'
name=$1
branch=$2
case "$name" in orb124-[a-z0-9-]*) ;; *) exit 64 ;; esac
real="/home/orbit/orb124-symlink-real"
path="/home/orbit/apps/laravel-typed/$name"
test ! -e "$real"
test ! -e "$path"
git clone --local --no-checkout /home/orbit/apps/laravel-typed/e2e-dev "$real" >/dev/null
git -C "$real" remote set-url origin https://github.com/laravel/laravel.git
git -C "$real" checkout -b "$branch" HEAD >/dev/null
ln -s "$real" "$path"
git -C "$real" rev-parse HEAD
BASH
}

make_worktree_graph() {
    local root=$1
    local root_branch=$2
    shift 2
    remote_script app-dev "$root" "$root_branch" "$@" <<'BASH'
root_name=$1
root_branch=$2
shift 2
case "$root_name" in orb124-[a-z0-9-]*) ;; *) exit 64 ;; esac
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
    case "$child" in orb124-[a-z0-9-]*) ;; *) exit 64 ;; esac
    path="/home/orbit/apps/laravel-typed/$child"
    test ! -e "$path"
    git -C "$root" worktree add -b "$branch" "$path" HEAD >/dev/null
done
git -C "$root" rev-parse HEAD
BASH
}

remove_sources() {
    remote_script app-dev "$@" <<'BASH'
for name in "$@"; do
    case "$name" in orb124-[a-z0-9-]*) ;; *) exit 64 ;; esac
    path="/home/orbit/apps/laravel-typed/$name"
    if [ -L "$path" ]; then
        rm -- "$path"
    elif [ -e "$path" ]; then
        rm -rf -- "$path"
    fi
done
rm -rf -- /home/orbit/orb124-symlink-real
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
    if member.get("route_outcome") not in ("deleted", "retained"):
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
    printf 'orb124-invalid-directive\n' > "$temporary"
    sudo install -o root -g root -m 0644 "$temporary" /etc/dnsmasq.d/orb124-invalid.conf
    rm -f "$temporary"
}

restore_dns() {
    sudo rm -f -- /etc/dnsmasq.d/orb124-invalid.conf
    sudo systemctl restart dnsmasq
}

break_php_fpm_config() {
    remote_script app-dev <<'BASH'
test -f /etc/php/8.5/fpm/php-fpm.conf
test ! -e /etc/php/8.5/fpm/php-fpm.conf.orb124
sudo mv -- /etc/php/8.5/fpm/php-fpm.conf /etc/php/8.5/fpm/php-fpm.conf.orb124
BASH
}

restore_php_fpm_config() {
    remote_script app-dev <<'BASH'
if [ -f /etc/php/8.5/fpm/php-fpm.conf.orb124 ]; then
    sudo mv -- /etc/php/8.5/fpm/php-fpm.conf.orb124 /etc/php/8.5/fpm/php-fpm.conf
fi
BASH
}

install_production_content() {
    local node=$1
    local instance_id=$2
    local instance_name=$3
    local response=$4
    remote_script "$node" "$instance_name" "$response" <<'BASH'
instance_name=$1
response=$2
root="/srv/orbit/$instance_name"
candidate=$(mktemp)
printf '%s\n' '<?php' "header(\"Content-Type: text/plain\");" "echo \"$response\\n\";" > "$candidate"
sudo install -d -o orbit -g orbit -m 0755 "$root" "$root/public"
sudo install -o orbit -g orbit -m 0644 "$candidate" "$root/public/index.php"
rm -f "$candidate"
BASH
}

assert_workload_artifacts_removed() {
    local node=$1
    local instance_id=$2
    local instance_name=$3
    local hostname=$4
    remote_script "$node" "$instance_id" "$instance_name" "$hostname" <<'BASH'
instance_id=$1
instance_name=$2
hostname=$3
current=$(sudo readlink -f /etc/caddy/Caddyfile)
fragments=$(dirname "$current")/fragments
test ! -e "/etc/caddy/orbit-certificates/app-instance-$instance_id"
! sudo grep -F -- "https://$hostname" "$fragments/app-dev.caddy" >/dev/null
test -f "/srv/orbit/$instance_name/public/index.php"
BASH
}

install_route_firewall_artifact() {
    local route_id=$1
    remote_script app-dev "$route_id" <<'BASH'
route_id=$1
sudo ufw allow in proto tcp from 10.44.0.1 to 10.44.0.2 port 443 comment "orbit:route-$route_id-lan" >/dev/null
sudo ufw status numbered | grep -F -- "# orbit:route-$route_id-lan" >/dev/null
BASH
}

assert_route_firewall_removed() {
    local route_id=$1
    remote_script app-dev "$route_id" <<'BASH'
route_id=$1
! sudo ufw status numbered | grep -F -- "# orbit:route-$route_id-lan" >/dev/null
BASH
}

inject_source_finalization_state() {
    local name=$1
    local mode=$2
    local evidence
    evidence=$(gateway_fixture removal-evidence "$name")
    read -r operation member checkout root digest identity layout common receipt < <(python3 -c '
import hashlib
import json
import sys

value = json.loads(sys.argv[1])
member = value["members"][0]
payload = "\0".join((value["operation_id"], str(member["member_id"]), member["source_digest"], "finalized"))
print(
    value["operation_id"],
    member["member_id"],
    member["checkout_path"],
    member["root"],
    member["source_digest"],
    member["source_identity"],
    member["layout"],
    member["common_repository_path"],
    hashlib.sha256(payload.encode()).hexdigest(),
)
' "$evidence")
    remote_script app-dev "$operation" "$member" "$checkout" "$root" "$digest" "$identity" "$layout" "$common" "$receipt" "$mode" <<'BASH'
operation=$1
member=$2
checkout=$3
root=$4
digest=$5
identity=$6
layout=$7
common=$8
receipt=$9
shift 9
mode=$1
state="$root/.orbit-removals"
journal="$state/$operation.$member.journal"
receipt_path="$state/$operation.$member.receipt"
quarantine="$state/$operation.$member.quarantine"
test "$(cat "$journal")" = "$digest"
test "$(stat -c '%d:%i' "$checkout")" = "$identity"
case "$layout" in
    worktree) git --git-dir="$common/.git" worktree move "$checkout" "$quarantine" ;;
    checkout) mv -- "$checkout" "$quarantine" ;;
    *) exit 64 ;;
esac
if [ "$mode" != before-receipt ]; then
    printf '%s\n' "$receipt" > "$receipt_path"
    chmod 0600 -- "$receipt_path"
fi
if [ "$mode" = after-delete ]; then
    case "$layout" in
        worktree) git --git-dir="$common/.git" worktree remove --force "$quarantine" ;;
        checkout) rm -rf -- "$quarantine" ;;
    esac
fi
BASH
}

case "$scenario" in
    setup)
        probe_gateway
        test -x /usr/bin/git
        test -x /usr/bin/curl
        test -d /home/orbit/apps/laravel-typed/e2e-dev/.git
        ;;

    setup-gateway)
        cd "$gateway"
        php artisan migrate:status | grep -F '2026_09_07_200000_make_app_instance_removal_retry_safe' >/dev/null
        php artisan route:list --name=instance:remove --json | python3 -c 'import json, sys; sys.exit(65) if len(json.load(sys.stdin)) != 1 else None'
        test -f "$fixture"
        ;;

    removal-preflight-refusals)
        read -r dirty_start _ < <(make_checkout orb124-preflight-dirty orb124-preflight-dirty dirty)
        dirty_id=$(seed_dev orb124-preflight-dirty checkout orb124-preflight-dirty "$dirty_start" | seed_id)
        before=$(gateway_fixture instance-state orb124-preflight-dirty)
        expect_remove_failure "$dirty_id" 0 instance.remove_refused
        assert_active_unchanged "$before" orb124-preflight-dirty
        remote_script app-dev <<'BASH'
test -f /home/orbit/apps/laravel-typed/orb124-preflight-dirty/orb124-dirty.txt
BASH
        gateway_fixture cleanup-active orb124-preflight-dirty
        remove_sources orb124-preflight-dirty

        read -r unpublished_start _ < <(make_checkout orb124-preflight-unpublished orb124-preflight-unpublished unpublished)
        unpublished_id=$(seed_dev orb124-preflight-unpublished checkout orb124-preflight-unpublished "$unpublished_start" | seed_id)
        before=$(gateway_fixture instance-state orb124-preflight-unpublished)
        expect_remove_failure "$unpublished_id" 0 instance.remove_refused
        assert_active_unchanged "$before" orb124-preflight-unpublished
        remote_script app-dev <<'BASH'
test -f /home/orbit/apps/laravel-typed/orb124-preflight-unpublished/orb124-unpublished.txt
BASH
        gateway_fixture cleanup-active orb124-preflight-unpublished
        remove_sources orb124-preflight-unpublished
        ;;

    normal-layout-removal)
        read -r checkout_commit _ < <(make_checkout orb124-normal-checkout orb124-normal-checkout clean)
        checkout_id=$(seed_dev orb124-normal-checkout checkout orb124-normal-checkout "$checkout_commit" | seed_id)
        gateway_fixture project-dev orb124-normal-checkout
        remove_success "$checkout_id" 0 1
        remote_script app-dev <<'BASH'
test ! -e /home/orbit/apps/laravel-typed/orb124-normal-checkout
BASH
        assert_completed_evidence orb124-normal-checkout

        read -r behind_commit _ < <(make_checkout orb124-normal-behind orb124-normal-behind published-behind)
        behind_id=$(seed_dev orb124-normal-behind checkout orb124-normal-behind "$behind_commit" | seed_id)
        gateway_fixture project-dev orb124-normal-behind
        remove_success "$behind_id" 0 1
        remote_script app-dev <<'BASH'
test ! -e /home/orbit/apps/laravel-typed/orb124-normal-behind
BASH
        assert_completed_evidence orb124-normal-behind

        graph_commit=$(make_worktree_graph orb124-normal-root orb124-normal-root orb124-normal-worktree orb124-normal-worktree)
        root_id=$(seed_dev orb124-normal-root checkout orb124-normal-root "$graph_commit" | seed_id)
        worktree_id=$(seed_dev orb124-normal-worktree worktree orb124-normal-worktree "$graph_commit" | seed_id)
        gateway_fixture project-dev orb124-normal-root orb124-normal-worktree
        remove_success "$worktree_id" 0 1
        remote_script app-dev <<'BASH'
root=/home/orbit/apps/laravel-typed/orb124-normal-root
test -d "$root"
test ! -e /home/orbit/apps/laravel-typed/orb124-normal-worktree
test "$(git -C "$root" worktree list --porcelain | grep -c '^worktree ')" = 1
test -z "$(git -C "$root" status --short)"
BASH
        remove_success "$root_id" 0 1
        ;;

    forced-worktree-cascade)
        graph_commit=$(make_worktree_graph orb124-cascade-root orb124-cascade-root orb124-cascade-a orb124-cascade-a orb124-cascade-b orb124-cascade-b)
        remote_script app-dev <<'BASH'
printf 'dirty cascade\n' > /home/orbit/apps/laravel-typed/orb124-cascade-a/orb124-dirty.txt
path=/home/orbit/apps/laravel-typed/orb124-cascade-b
printf 'unpublished cascade\n' > "$path/orb124-unpublished.txt"
git -C "$path" add orb124-unpublished.txt
git -C "$path" commit -m 'Add unpublished cascade commit' >/dev/null
BASH
        root_id=$(seed_dev orb124-cascade-root checkout orb124-cascade-root "$graph_commit" | seed_id)
        seed_dev orb124-cascade-a worktree orb124-cascade-a "$graph_commit" >/dev/null
        seed_dev orb124-cascade-b worktree orb124-cascade-b "$graph_commit" >/dev/null
        gateway_fixture project-dev orb124-cascade-root orb124-cascade-a orb124-cascade-b
        break_php_fpm_config
        trap restore_php_fpm_config EXIT
        expect_remove_failure "$root_id" 1 app-dev.php_fpm_config_failed
        assert_failure_progress "$root_id" runtime_cleanup 3 0 3
        gateway_fixture removal-evidence orb124-cascade-root | python3 -c '
import json
import sys

value = json.load(sys.stdin)
members = value["members"]
if [item["layout"] for item in members] != ["worktree", "worktree", "checkout"]:
    raise SystemExit(65)
if [item["position"] for item in members] != [0, 1, 2]:
    raise SystemExit(65)
if members[0]["source_finalized_at"] is None or members[0]["runtime_cleaned_at"] is not None:
    raise SystemExit(65)
'
        remote_script app-dev <<'BASH'
root=/home/orbit/apps/laravel-typed/orb124-cascade-root
new=/home/orbit/apps/laravel-typed/orb124-cascade-new
test -d "$root"
test ! -e "$new"
git -C "$root" worktree add -b orb124-cascade-new "$new" HEAD >/dev/null
BASH
        expect_remove_failure "$root_id" 1 instance.removal_conflict
        assert_failure_progress "$root_id" runtime_cleanup 3 0 3
        gateway_fixture removal-evidence orb124-cascade-root | python3 -c '
import json
import sys

value = json.load(sys.stdin)
if value.get("total") != 3 or len(value.get("members", [])) != 3:
    raise SystemExit(65)
if [item["name"] for item in value["members"]] != ["orb124-cascade-a", "orb124-cascade-b", "orb124-cascade-root"]:
    raise SystemExit(65)
'
        remote_script app-dev <<'BASH'
root=/home/orbit/apps/laravel-typed/orb124-cascade-root
new=/home/orbit/apps/laravel-typed/orb124-cascade-new
test -d "$new"
git -C "$root" worktree remove --force "$new"
test ! -e "$new"
BASH
        restore_php_fpm_config
        trap - EXIT
        remove_success "$root_id" 1 3
        remote_script app-dev <<'BASH'
for name in orb124-cascade-root orb124-cascade-a orb124-cascade-b; do
    test ! -e "/home/orbit/apps/laravel-typed/$name"
done
BASH
        assert_completed_evidence orb124-cascade-root 3
        ;;

    forced-removal-boundaries)
        read -r owner_commit _ < <(make_checkout orb124-wrong-owner orb124-wrong-owner clean)
        owner_id=$(seed_dev orb124-wrong-owner checkout orb124-wrong-owner "$owner_commit" | seed_id)
        remote_script app-dev <<'BASH'
sudo chown root:root /home/orbit/apps/laravel-typed/orb124-wrong-owner
BASH
        before=$(gateway_fixture instance-state orb124-wrong-owner)
        expect_remove_failure "$owner_id" 1 instance.remove_refused
        assert_active_unchanged "$before" orb124-wrong-owner
        remote_script app-dev <<'BASH'
sudo chown orbit:orbit /home/orbit/apps/laravel-typed/orb124-wrong-owner
BASH
        gateway_fixture cleanup-active orb124-wrong-owner
        remove_sources orb124-wrong-owner

        read -r mismatch_commit _ < <(make_checkout orb124-wrong-origin orb124-wrong-origin wrong-origin)
        mismatch_id=$(seed_dev orb124-wrong-origin checkout orb124-wrong-origin "$mismatch_commit" | seed_id)
        before=$(gateway_fixture instance-state orb124-wrong-origin)
        expect_remove_failure "$mismatch_id" 1 instance.source_identity_invalid
        assert_active_unchanged "$before" orb124-wrong-origin
        gateway_fixture cleanup-active orb124-wrong-origin
        remove_sources orb124-wrong-origin

        read -r unavailable_commit _ < <(make_checkout orb124-origin-unavailable orb124-origin-unavailable clean)
        unavailable_id=$(seed_dev orb124-origin-unavailable checkout orb124-origin-unavailable "$unavailable_commit" | seed_id)
        gateway_fixture project-dev orb124-origin-unavailable
        remote_script app-dev <<'BASH'
path=/home/orbit/apps/laravel-typed/orb124-origin-unavailable
git -C "$path" config http.proxy http://127.0.0.1:1
! git -C "$path" ls-remote origin refs/heads/13.x >/dev/null 2>&1
BASH
        remove_success "$unavailable_id" 1 1
        remote_script app-dev <<'BASH'
test ! -e /home/orbit/apps/laravel-typed/orb124-origin-unavailable
BASH

        symlink_commit=$(make_symlink_checkout orb124-symlink orb124-symlink)
        symlink_id=$(seed_dev orb124-symlink checkout orb124-symlink "$symlink_commit" | seed_id)
        before=$(gateway_fixture instance-state orb124-symlink)
        expect_remove_failure "$symlink_id" 1 instance.remove_refused
        assert_active_unchanged "$before" orb124-symlink
        remote_script app-dev <<'BASH'
test -L /home/orbit/apps/laravel-typed/orb124-symlink
test -d /home/orbit/orb124-symlink-real/.git
BASH
        gateway_fixture cleanup-active orb124-symlink
        remove_sources orb124-symlink
        ;;

    worktree-removal-preserves-common-git)
        graph_commit=$(make_worktree_graph orb124-preserve-root orb124-preserve-root orb124-preserve-child orb124-preserve-child orb124-preserve-sibling orb124-preserve-sibling)
        root_id=$(seed_dev orb124-preserve-root checkout orb124-preserve-root "$graph_commit" | seed_id)
        child_id=$(seed_dev orb124-preserve-child worktree orb124-preserve-child "$graph_commit" | seed_id)
        seed_dev orb124-preserve-sibling worktree orb124-preserve-sibling "$graph_commit" >/dev/null
        gateway_fixture project-dev orb124-preserve-root orb124-preserve-child orb124-preserve-sibling
        remove_success "$child_id" 0 1
        remote_script app-dev <<'BASH'
root=/home/orbit/apps/laravel-typed/orb124-preserve-root
sibling=/home/orbit/apps/laravel-typed/orb124-preserve-sibling
test -d "$root/.git"
test -d "$sibling"
test ! -e /home/orbit/apps/laravel-typed/orb124-preserve-child
git -C "$root" show-ref --verify --quiet refs/heads/orb124-preserve-child
test -z "$(git -C "$root" status --short)"
test -z "$(git -C "$sibling" status --short)"
test "$(git -C "$root" worktree list --porcelain | grep -c '^worktree ')" = 2
BASH
        remove_success "$root_id" 1 2
        ;;

    checkout-worktree-removal-boundary)
        graph_commit=$(make_worktree_graph orb124-unregistered-root orb124-unregistered-root orb124-unregistered-child orb124-unregistered-child)
        unregistered_id=$(seed_dev orb124-unregistered-root checkout orb124-unregistered-root "$graph_commit" | seed_id)
        before=$(gateway_fixture instance-state orb124-unregistered-root)
        expect_remove_failure "$unregistered_id" 1 instance.remove_refused
        assert_active_unchanged "$before" orb124-unregistered-root
        remote_script app-dev <<'BASH'
test -d /home/orbit/apps/laravel-typed/orb124-unregistered-root/.git
test -d /home/orbit/apps/laravel-typed/orb124-unregistered-child
BASH
        gateway_fixture cleanup-active orb124-unregistered-root
        remove_sources orb124-unregistered-child orb124-unregistered-root

        graph_commit=$(make_worktree_graph orb124-boundary-root orb124-boundary-root orb124-boundary-child orb124-boundary-child)
        boundary_root_id=$(seed_dev orb124-boundary-root checkout orb124-boundary-root "$graph_commit" | seed_id)
        seed_dev orb124-boundary-child worktree orb124-boundary-child "$graph_commit" >/dev/null
        gateway_fixture project-dev orb124-boundary-root orb124-boundary-child
        before=$(gateway_fixture instance-state orb124-boundary-root)
        expect_remove_failure "$boundary_root_id" 0 instance.remove_refused
        assert_active_unchanged "$before" orb124-boundary-root
        python3 -c '
import json
import sys

value = json.loads(sys.argv[1])
if "--force" not in value["error"]["message"]:
    raise SystemExit(65)
' "$LAST_FAILURE"
        remove_success "$boundary_root_id" 1 2
        ;;

    shared-route-target-removal)
        production=$(gateway_fixture seed-production)
        read -r route_id hostname router_ip first_id first_name second_id second_name < <(python3 -c '
import json
import sys

value = json.loads(sys.argv[1])
print(
    value["route_id"], value["hostname"], value["router_ip"],
    value["instances"][0]["id"], value["instances"][0]["name"],
    value["instances"][1]["id"], value["instances"][1]["name"],
)
' "$production")
        install_production_content app-prod "$first_id" "$first_name" orb124-prod-one
        install_production_content app-prod-2 "$second_id" "$second_name" orb124-prod-two
        gateway_fixture project-production "$route_id"
        curl -sS --resolve "$hostname:443:$router_ip" "https://$hostname" >/dev/null
        remove_success "$first_id" 0 1
        assert_workload_artifacts_removed app-prod "$first_id" "$first_name" "$hostname"
        gateway_fixture production-evidence "$route_id" "$first_id" "$second_id" | python3 -c '
import json
import sys

value = json.load(sys.stdin)
if value != {"route_status": "active", "targets": [int(sys.argv[1])], "departing_exists": False, "remaining_exists": True}:
    raise SystemExit(65)
' "$second_id"
        for _ in 1 2 3 4 5; do
            test "$(curl -sS --resolve "$hostname:443:$router_ip" "https://$hostname")" = orb124-prod-two
        done
        remove_success "$second_id" 0 1
        assert_workload_artifacts_removed app-prod-2 "$second_id" "$second_name" "$hostname"
        gateway_fixture hostname-free "$hostname"
        gateway_fixture reset-production-nodes
        ;;

    final-target-route-removal)
        hostname=orb124-reusable.orbit
        read -r final_commit _ < <(make_checkout orb124-final-target orb124-final-target clean)
        final=$(seed_dev orb124-final-target checkout orb124-final-target "$final_commit" "$hostname")
        read -r final_id final_route_id < <(python3 -c '
import json
import sys

value = json.loads(sys.argv[1])
print(value["id"], value["route_id"])
' "$final")
        gateway_fixture project-dev orb124-final-target
        install_route_firewall_artifact "$final_route_id"
        remove_success "$final_id" 0 1
        assert_route_firewall_removed "$final_route_id"
        gateway_fixture hostname-free "$hostname"
        remote_script app-dev <<'BASH'
test ! -e /home/orbit/apps/laravel-typed/orb124-final-target
BASH
        assert_completed_evidence orb124-final-target
        reuse=$(remote_command app-dev orbit instance:new 1 2 orb124-reuse --branch=13.x --hostname="$hostname" --json)
        reuse_id=$(printf '%s' "$reuse" | python3 -c '
import json
import sys

value = json.load(sys.stdin)
if value.get("hostname") != "orb124-reusable.orbit" or value.get("status") != "active":
    raise SystemExit(65)
print(value["id"])
')
        curl -sS --resolve "$hostname:443:$app_dev_ip" -o /dev/null -w '%{http_code}' "https://$hostname" | grep -E '^[1-5][0-9][0-9]$' >/dev/null
        remove_success "$reuse_id" 0 1
        ;;

    removal-route-before-source)
        hostname=orb124-ordered.orbit
        read -r ordered_commit _ < <(make_checkout orb124-ordered orb124-ordered clean)
        ordered_id=$(seed_dev orb124-ordered checkout orb124-ordered "$ordered_commit" "$hostname" | seed_id)
        gateway_fixture project-dev orb124-ordered
        remove_success "$ordered_id" 0 1
        gateway_fixture hostname-free "$hostname"
        remote_script app-dev <<'BASH'
test ! -e /home/orbit/apps/laravel-typed/orb124-ordered
BASH
        assert_completed_evidence orb124-ordered
        ;;

    interrupted-removal-state)
        hostname=orb124-interrupted.orbit
        read -r interrupted_commit _ < <(make_checkout orb124-interrupted orb124-interrupted clean)
        interrupted_id=$(seed_dev orb124-interrupted checkout orb124-interrupted "$interrupted_commit" "$hostname" | seed_id)
        gateway_fixture project-dev orb124-interrupted
        break_php_fpm_config
        trap restore_php_fpm_config EXIT
        expect_remove_failure "$interrupted_id" 0 app-dev.php_fpm_config_failed
        gateway_fixture removal-evidence orb124-interrupted | python3 -c '
import json
import sys

value = json.load(sys.stdin)
member = value["members"][0]
if value.get("status") != "failed" or value.get("current_step") != "runtime_cleanup":
    raise SystemExit(65)
if value.get("failed_step") != "runtime_cleanup" or value.get("error_code") != "app-dev.php_fpm_config_failed":
    raise SystemExit(65)
if member.get("route_outcome") != "deleted" or member.get("source_finalized_at") is None or member.get("runtime_cleaned_at") is not None:
    raise SystemExit(65)
'
        gateway_fixture hostname-free "$hostname"
        remote_script app-dev <<'BASH'
test ! -e /home/orbit/apps/laravel-typed/orb124-interrupted
BASH
        restore_php_fpm_config
        trap - EXIT
        remove_success "$interrupted_id" 0 1
        assert_completed_evidence orb124-interrupted

        for window in before receipt deleted; do
            root_name="orb124-finalize-$window-root"
            child_name="orb124-finalize-$window-child"
            extra_name="orb124-finalize-$window-extra"
            case "$window" in
                before) mode=before-receipt ;;
                receipt) mode=after-receipt ;;
                deleted) mode=after-delete ;;
                *) exit 64 ;;
            esac
            finalize_commit=$(make_worktree_graph "$root_name" "$root_name" "$child_name" "$child_name")
            finalize_root_id=$(seed_dev "$root_name" checkout "$root_name" "$finalize_commit" | seed_id)
            seed_dev "$child_name" worktree "$child_name" "$finalize_commit" >/dev/null
            gateway_fixture project-dev "$root_name" "$child_name"
            inject_dns_failure
            trap restore_dns EXIT
            expect_remove_failure "$finalize_root_id" 1 app-dev.dns_config_failed
            assert_failure_progress "$finalize_root_id" route_target_clear 2 0 2
            restore_dns
            trap - EXIT
            gateway_fixture complete-removal-route-step "$child_name"
            inject_source_finalization_state "$child_name" "$mode"
            if [ "$mode" != after-delete ]; then
                evidence=$(gateway_fixture removal-evidence "$child_name")
                quarantine=$(python3 -c '
import json
import sys

value = json.loads(sys.argv[1])
member = value["members"][0]
print(f"{member['"'"'root'"'"']}/.orbit-removals/{value['"'"'operation_id'"'"']}.{member['"'"'member_id'"'"']}.quarantine")
' "$evidence")
                remote_script app-dev "$quarantine" <<'BASH'
quarantine=$1
test -d "$quarantine"
git -C "$quarantine" remote set-url origin https://github.com/laravel/framework.git
BASH
                expect_remove_failure "$finalize_root_id" 1 instance.source_identity_invalid
                assert_failure_progress "$finalize_root_id" source_finalization 2 0 2
                remote_script app-dev "$quarantine" <<'BASH'
quarantine=$1
test -d "$quarantine"
git -C "$quarantine" remote set-url origin https://github.com/laravel/laravel.git
BASH
            fi
            remote_script app-dev "$root_name" "$extra_name" <<'BASH'
root_name=$1
extra_name=$2
root="/home/orbit/apps/laravel-typed/$root_name"
extra="/home/orbit/apps/laravel-typed/$extra_name"
test -d "$root"
test ! -e "$extra"
git -C "$root" worktree add -b "$extra_name" "$extra" HEAD >/dev/null
BASH
            expect_remove_failure "$finalize_root_id" 1 instance.removal_conflict
            assert_failure_progress "$finalize_root_id" source_finalization 2 0 2
            gateway_fixture removal-evidence "$root_name" | python3 -c '
import json
import sys

value = json.load(sys.stdin)
if value.get("total") != 2 or len(value.get("members", [])) != 2:
    raise SystemExit(65)
if [item["name"] for item in value["members"]] != [sys.argv[1], sys.argv[2]]:
    raise SystemExit(65)
' "$child_name" "$root_name"
            remote_script app-dev "$root_name" "$extra_name" <<'BASH'
root_name=$1
extra_name=$2
root="/home/orbit/apps/laravel-typed/$root_name"
extra="/home/orbit/apps/laravel-typed/$extra_name"
test -d "$extra"
git -C "$root" worktree remove --force "$extra"
test ! -e "$extra"
BASH
            remove_success "$finalize_root_id" 1 2
            remote_script app-dev "$root_name" "$child_name" <<'BASH'
root_name=$1
child_name=$2
test ! -e "/home/orbit/apps/laravel-typed/$root_name"
test ! -e "/home/orbit/apps/laravel-typed/$child_name"
BASH
            assert_completed_evidence "$root_name" 2
        done
        ;;

    removal-retry-revalidation)
        read -r retry_commit _ < <(make_checkout orb124-retry orb124-retry clean)
        retry_id=$(seed_dev orb124-retry checkout orb124-retry "$retry_commit" | seed_id)
        gateway_fixture project-dev orb124-retry
        inject_dns_failure
        trap restore_dns EXIT
        expect_remove_failure "$retry_id" 0 app-dev.dns_config_failed
        restore_dns
        trap - EXIT
        remote_script app-dev "$retry_commit" <<'BASH'
commit=$1
path=/home/orbit/apps/laravel-typed/orb124-retry
backup=/home/orbit/orb124-retry-original
test ! -e "$backup"
mv -- "$path" "$backup"
git clone --local --no-checkout /home/orbit/apps/laravel-typed/e2e-dev "$path" >/dev/null
git -C "$path" remote set-url origin https://github.com/laravel/laravel.git
git -C "$path" checkout -b orb124-retry "$commit" >/dev/null
BASH
        expect_remove_failure "$retry_id" 0 instance.removal_conflict
        assert_failure_progress "$retry_id" route_target_clear 1 0 1
        remote_script app-dev "$retry_commit" <<'BASH'
commit=$1
path=/home/orbit/apps/laravel-typed/orb124-retry
backup=/home/orbit/orb124-retry-original
test "$(git -C "$path" remote get-url origin)" = https://github.com/laravel/laravel.git
test "$(git -C "$path" symbolic-ref --short HEAD)" = orb124-retry
test "$(git -C "$path" rev-parse HEAD)" = "$commit"
rm -rf -- "$path"
mv -- "$backup" "$path"
BASH
        remove_success "$retry_id" 0 1
        assert_completed_evidence orb124-retry
        ;;

    coordinated-development-removal)
        hostname=orb124-unavailable.orbit
        read -r contact_commit _ < <(make_checkout orb124-unavailable orb124-unavailable endpoint)
        unavailable_id=$(seed_dev orb124-unavailable checkout orb124-unavailable "$contact_commit" "$hostname" | seed_id)
        gateway_fixture project-dev orb124-unavailable
        test "$(curl -sS --resolve "$hostname:443:$app_dev_ip" "https://$hostname")" = 'former target'
        contact_before=$(remote_script app-dev <<'BASH'
wc -l < /tmp/orb124-former-target-contact
BASH
)
        sudo rm -f -- /tmp/orb124-dns-lock-held /tmp/orb124-dns-lock-release
        rm -f -- /tmp/orb124-removal-output
        sudo bash -seu <<'BASH' &
exec 9>/run/lock/orbit-dnsmasq.lock
flock 9
touch /tmp/orb124-dns-lock-held
while [ ! -f /tmp/orb124-dns-lock-release ]; do
    sleep 0.1
done
BASH
        lock_pid=$!
        trap 'sudo touch /tmp/orb124-dns-lock-release; wait "$lock_pid" || true' EXIT
        for _ in $(seq 1 100); do
            test -f /tmp/orb124-dns-lock-held && break
            sleep 0.1
        done
        test -f /tmp/orb124-dns-lock-held
        remote_command app-dev orbit instance:remove "$unavailable_id" --json > /tmp/orb124-removal-output &
        removal_pid=$!
        unavailable_published=0
        for _ in $(seq 1 200); do
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
            sleep 0.1
        done
        test "$unavailable_published" = 1
        status=$(curl -sS --resolve "$hostname:443:$app_dev_ip" -D /tmp/orb124-unavailable.headers -o /tmp/orb124-unavailable.body -w '%{http_code}' "https://$hostname")
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
' /tmp/orb124-unavailable.headers /tmp/orb124-unavailable.body
        contact_after=$(remote_script app-dev <<'BASH'
wc -l < /tmp/orb124-former-target-contact
BASH
)
        test "$contact_before" = "$contact_after"
        sudo touch /tmp/orb124-dns-lock-release
        wait "$lock_pid"
        wait "$removal_pid"
        trap - EXIT
        assert_successful_removal "$(cat /tmp/orb124-removal-output)" "$unavailable_id" 0 1
        gateway_fixture hostname-free "$hostname"
        ;;

    removal-retains-branches)
        graph_commit=$(make_worktree_graph orb124-branches-root orb124-branches-root orb124-branches-child orb124-branches-child)
        root_id=$(seed_dev orb124-branches-root checkout orb124-branches-root "$graph_commit" | seed_id)
        child_id=$(seed_dev orb124-branches-child worktree orb124-branches-child "$graph_commit" | seed_id)
        gateway_fixture project-dev orb124-branches-root orb124-branches-child
        remote_before=$(remote_script app-dev <<'BASH'
git -C /home/orbit/apps/laravel-typed/orb124-branches-root ls-remote origin refs/heads/13.x
BASH
)
        remove_success "$child_id" 0 1
        remote_after=$(remote_script app-dev <<'BASH'
root=/home/orbit/apps/laravel-typed/orb124-branches-root
git -C "$root" show-ref --verify --quiet refs/heads/orb124-branches-child
git -C "$root" ls-remote origin refs/heads/13.x
BASH
)
        test "$remote_before" = "$remote_after"
        remove_success "$root_id" 0 1
        ;;

    *)
        printf 'Unknown ORB-124 proof scenario: %s\n' "$scenario" >&2
        exit 64
        ;;
esac

printf 'ORB-124 %s: ok\n' "$scenario"

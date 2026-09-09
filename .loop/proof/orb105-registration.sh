#!/usr/bin/env bash
set -euo pipefail

scenario=${1:-}
repository=/home/orbit/orbit
state_fixture="$repository/.loop/proof/orb105-state.php"
ssh_key=/home/orbit/.orbit/ssh/id_ed25519
known_hosts=/home/orbit/.orbit/ssh/known_hosts
app_dev_ip=10.44.0.2
template=/home/orbit/apps/laravel-typed/e2e-dev

remote_command() {
    ssh -i "$ssh_key" -o BatchMode=yes -o IdentitiesOnly=yes -o StrictHostKeyChecking=yes \
        -o "UserKnownHostsFile=$known_hosts" "orbit@$app_dev_ip" "$@"
}

remote_script() {
    remote_command bash -seu -- "$@"
}

gateway_state() {
    (cd "$repository/apps/gateway" && php "$state_fixture" "$@")
}

create_checkout() {
    local path=$1
    local branch=$2
    remote_script "$path" "$branch" <<'BASH'
path=$1
branch=$2
case "$path" in /home/orbit/orb105-*|/dev/shm/orb105-*) ;; *) exit 64 ;; esac
test ! -e "$path"
git clone --local --no-checkout /home/orbit/apps/laravel-typed/e2e-dev "$path" >/dev/null
git -C "$path" remote set-url origin https://github.com/laravel/laravel.git
git -C "$path" checkout -B "$branch" HEAD >/dev/null
git -C "$path" config user.name 'Orbit ORB-105 proof'
git -C "$path" config user.email orbit-proof@example.invalid
BASH
}

create_graph() {
    local root=$1
    local child=$2
    remote_script "$root" "$child" <<'BASH'
root=$1
child=$2
case "$root" in /home/orbit/orb105-*) ;; *) exit 64 ;; esac
case "$child" in /home/orbit/orb105-*) ;; *) exit 64 ;; esac
test ! -e "$root"
test ! -e "$child"
git clone --local --no-checkout /home/orbit/apps/laravel-typed/e2e-dev "$root" >/dev/null
git -C "$root" remote set-url origin https://github.com/laravel/laravel.git
git -C "$root" checkout -B orb105-root HEAD >/dev/null
git -C "$root" config user.name 'Orbit ORB-105 proof'
git -C "$root" config user.email orbit-proof@example.invalid
git -C "$root" worktree add -b orb105-child "$child" HEAD >/dev/null
BASH
}

register_source() {
    local path=$1
    shift
    remote_command orbit instance:register "--path=$path" --app=1 --no-interaction --json "$@"
}

json_field() {
    python3 -c 'import json,sys; value=json.loads(sys.argv[1]); current=value
for key in sys.argv[2].split("."): current=current[key]
print("true" if current is True else "false" if current is False else current)' "$1" "$2"
}

assert_registration() {
    local output=$1
    local layout=$2
    local path=$3
    python3 -c 'import json,sys
value=json.loads(sys.argv[1]); instance=value["app_instance"]
assert instance["source_layout"] == sys.argv[2]
assert instance["checkout_path"] == sys.argv[3]
assert instance["status"] == "active"
assert isinstance(instance["route"]["id"], int)
assert isinstance(value["request_id"], str)' "$output" "$layout" "$path"
}

remove_instance() {
    remote_command orbit instance:remove "$1" --force --json >/dev/null
}

cleanup_path() {
    remote_script "$@" <<'BASH'
for path in "$@"; do
    case "$path" in /home/orbit/orb105-*|/dev/shm/orb105-*) ;; *) exit 64 ;; esac
    if [ -e "$path" ] || [ -L "$path" ]; then rm -rf -- "$path"; fi
done
BASH
}

case "$scenario" in
    setup)
        gateway_state probe >/dev/null
        remote_command orbit app:list --json | python3 -c 'import json,sys; assert len(json.load(sys.stdin)["apps"]) >= 1'
        ;;
    register-checkout-and-worktree)
        source=/home/orbit/orb105-checkout
        create_checkout "$source" orb105-checkout
        output=$(register_source "$source")
        destination=/home/orbit/apps/laravel-typed/orb105-checkout
        assert_registration "$output" checkout "$destination"
        remove_instance "$(json_field "$output" app_instance.id)"

        root=/home/orbit/orb105-common
        child=/home/orbit/orb105-linked
        create_graph "$root" "$child"
        output=$(register_source "$child")
        destination=/home/orbit/apps/laravel-typed/orb105-linked
        assert_registration "$output" worktree "$destination"
        remote_command git -C "$root" status --porcelain >/dev/null
        remove_instance "$(json_field "$output" app_instance.id)"
        cleanup_path "$root" "$child"
        ;;
    register-preserves-git-state)
        source=/home/orbit/orb105-preserve
        create_checkout "$source" orb105-preserve
        remote_script "$source" <<'BASH'
path=$1
printf 'staged\n' > "$path/orb105-staged.txt"
git -C "$path" add orb105-staged.txt
printf 'dirty\n' >> "$path/README.md"
printf 'untracked\n' > "$path/orb105-untracked.txt"
git -C "$path" config orb105.proof retained
git -C "$path" update-ref refs/orb105/proof HEAD
git -C "$path" checkout --detach >/dev/null
git -C "$path" status --porcelain=v2 --untracked-files=all > /tmp/orb105-status-before
BASH
        output=$(register_source "$source")
        destination=/home/orbit/apps/laravel-typed/orb105-preserve
        assert_registration "$output" checkout "$destination"
        remote_script "$destination" <<'BASH'
path=$1
test "$(git -C "$path" config orb105.proof)" = retained
test "$(git -C "$path" rev-parse refs/orb105/proof)" = "$(git -C "$path" rev-parse HEAD)"
test -z "$(git -C "$path" symbolic-ref -q HEAD || true)"
git -C "$path" status --porcelain=v2 --untracked-files=all > /tmp/orb105-status-after
cmp /tmp/orb105-status-before /tmp/orb105-status-after
grep -Fx staged "$path/orb105-staged.txt"
grep -Fx untracked "$path/orb105-untracked.txt"
BASH
        remove_instance "$(json_field "$output" app_instance.id)"
        ;;
    registered-source-provisioning)
        source=/home/orbit/orb105-provision
        create_checkout "$source" orb105-provision
        output=$(register_source "$source")
        destination=/home/orbit/apps/laravel-typed/orb105-provision
        assert_registration "$output" checkout "$destination"
        hostname=$(json_field "$output" app_instance.hostname)
        test "$(json_field "$output" app_instance.url)" = "https://$hostname"
        remote_command grep -F "APP_URL=https://$hostname" "$destination/.env" >/dev/null
        remove_instance "$(json_field "$output" app_instance.id)"
        ;;
    registered-source-provisioning-rollback)
        source=/home/orbit/orb105-rollback
        create_checkout "$source" orb105-rollback
        remote_script "$source" <<'BASH'
path=$1
printf '%s\n' 'APP_URL=https://before.invalid' 'ORB105_SENTINEL=retained' > "$path/.env"
sha256sum "$path/.env" | cut -d' ' -f1 > /tmp/orb105-env-before
BASH
        temporary=$(mktemp)
        printf 'orb105-invalid-directive\n' > "$temporary"
        sudo install -o root -g root -m 0644 "$temporary" /etc/dnsmasq.d/orb105-invalid.conf
        rm -f "$temporary"
        set +e
        failure=$(register_source "$source" 2>&1)
        status=$?
        set -e
        sudo rm -f /etc/dnsmasq.d/orb105-invalid.conf
        sudo systemctl reset-failed dnsmasq
        sudo systemctl restart dnsmasq
        test "$status" -ne 0
        test "$(json_field "$failure" error.code)" = instance.registration_incomplete
        destination=/home/orbit/apps/laravel-typed/orb105-rollback
        remote_script "$destination" <<'BASH'
path=$1
test "$(sha256sum "$path/.env" | cut -d' ' -f1)" = "$(cat /tmp/orb105-env-before)"
grep -Fx ORB105_SENTINEL=retained "$path/.env"
BASH
        output=$(register_source "$source")
        remove_instance "$(json_field "$output" app_instance.id)"
        ;;
    manual-default-migration)
        root=/home/orbit/orb105-migration
        source="$root/laravel-typed"
        create_checkout "$source" 13.x
        commit=$(remote_command git -C "$source" rev-parse HEAD)
        seeded=$(gateway_state seed-migration "$source" "$commit")
        id=$(json_field "$seeded" id)
        route_id=$(json_field "$seeded" route_id)
        output=$(register_source "$source")
        test "$(json_field "$output" app_instance.id)" = "$id"
        state=$(gateway_state migration-state "$id")
        test "$(json_field "$state" name)" = default
        test "$(json_field "$state" path)" = /home/orbit/apps/laravel-typed/default
        test "$(json_field "$state" migration_required)" = false
        test "$(json_field "$state" route_id)" = "$route_id"
        test "$(json_field "$state" hostname)" = orb105-migration.orbit
        remove_instance "$id"
        cleanup_path "$root"
        ;;
    checkout-move-repairs-worktrees)
        root=/home/orbit/orb105-repair-root
        child=/home/orbit/orb105-repair-child
        create_graph "$root" "$child"
        output=$(register_source "$root")
        destination=/home/orbit/apps/laravel-typed/orb105-repair-root
        remote_command git -C "$child" status --porcelain >/dev/null
        remote_command git -C "$destination" worktree list --porcelain | grep -F "worktree $child"
        remote_command git -C "$destination" worktree remove --force "$child"
        remove_instance "$(json_field "$output" app_instance.id)"
        cleanup_path "$root" "$child"
        ;;
    include-worktrees-all-or-none)
        root=/home/orbit/orb105-set-root
        child=/home/orbit/orb105-set-child
        create_graph "$root" "$child"
        output=$(register_source "$root" --include-worktrees)
        test "$(json_field "$output" source_count)" = 2
        test "$(json_field "$output" completed_count)" = 2
        remove_instance "$(json_field "$output" app_instance.id)"

        root=/home/orbit/orb105-bad-root
        child=/home/orbit/orb105-bad-child
        create_graph "$root" "$child"
        remote_script "$child" <<'BASH'
path=$1
gitfile=$(cat "$path/.git")
rm "$path/.git"
ln -s /tmp/orb105-invalid-git "$path/.git"
printf '%s\n' "$gitfile" > /tmp/orb105-gitfile
BASH
        set +e
        failure=$(register_source "$root" --include-worktrees 2>&1)
        status=$?
        set -e
        test "$status" -ne 0
        test "$(json_field "$failure" error.code)" = instance.source_invalid
        remote_command test -d "$root"
        remote_command test -L "$child/.git"
        cleanup_path "$root" "$child"
        ;;
    cross-filesystem-registration-retry)
        source=/dev/shm/orb105-cross
        create_checkout "$source" orb105-cross
        first=$(register_source "$source")
        destination=/home/orbit/apps/laravel-typed/orb105-cross
        assert_registration "$first" checkout "$destination"
        remote_script "$source" "$destination" <<'BASH'
source=$1
destination=$2
test ! -e "$source"
cp -a "$destination" "$source"
BASH
        second=$(register_source "$source")
        test "$(json_field "$first" app_instance.id)" = "$(json_field "$second" app_instance.id)"
        test "$(json_field "$first" app_instance.route.id)" = "$(json_field "$second" app_instance.route.id)"
        remote_command test ! -e "$source"
        remote_command test -d "$destination"
        remove_instance "$(json_field "$second" app_instance.id)"
        ;;
    *) exit 64 ;;
esac

#!/usr/bin/env bash
set -euo pipefail

scenario=${1:-}
repository=/home/orbit/orbit
state_fixture="$repository/.loop/proof/orb105-state.php"
registration_fixture="$repository/.loop/proof/orb105-register-request.php"
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
if [[ "$path" == /dev/shm/* ]]; then
    git clone --no-local --no-checkout /home/orbit/apps/laravel-typed/e2e-dev "$path" >/dev/null
else
    git clone --local --no-checkout /home/orbit/apps/laravel-typed/e2e-dev "$path" >/dev/null
fi
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

register_request() {
    remote_command php "$registration_fixture" "$1"
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

install_projection_fault() {
    local temporary
    temporary=$(mktemp)
    printf 'orb105-invalid-directive\n' > "$temporary"
    sudo install -o root -g root -m 0644 "$temporary" /etc/dnsmasq.d/orb105-invalid.conf
    rm -f "$temporary"
}

clear_projection_fault() {
    sudo rm -f /etc/dnsmasq.d/orb105-invalid.conf
    sudo systemctl reset-failed dnsmasq
    sudo systemctl restart dnsmasq
}

snapshot_source() {
    local path=$1
    local output=$2
    local portable=${3:-false}
    remote_script "$path" "$output" "$portable" <<'BASH'
path=$1
output=$2
portable=$3
case "$output" in /tmp/orb105-*) ;; *) exit 64 ;; esac
env GIT_OPTIONAL_LOCKS=0 python3 - "$path" "$portable" > "$output" <<'PYTHON'
import base64, hashlib, json, os, pathlib, stat, subprocess, sys
root = pathlib.Path(sys.argv[1])
portable = sys.argv[2] == 'true'
environment = dict(os.environ, GIT_OPTIONAL_LOCKS='0')

def git(*arguments):
    result = subprocess.run(
        ['git', '-C', str(root), *arguments],
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        env=environment,
        check=False,
    )
    return {
        'exit': result.returncode,
        'stdout': base64.b64encode(result.stdout).decode(),
        'stderr': base64.b64encode(result.stderr).decode(),
    }

git_state = {
    'head': git('rev-parse', '--verify', 'HEAD^{commit}'),
    'branch': git('symbolic-ref', '-q', 'HEAD'),
    'status': git('status', '--porcelain=v2', '--untracked-files=all'),
    'config': git('config', '--local', '--null', '--list'),
    'refs': git('show-ref', '--head'),
    'index': hashlib.sha256((root / '.git' / 'index').read_bytes()).hexdigest(),
}
entries = []
for entry in [root, *sorted(root.rglob('*'))]:
    info = entry.lstat()
    relative = '.' if entry == root else entry.relative_to(root).as_posix()
    kind = 'link' if entry.is_symlink() else 'file' if entry.is_file() else 'directory'
    entries.append({
        'path': relative,
        'type': kind,
        'mode': stat.S_IMODE(info.st_mode),
        'uid': info.st_uid,
        'gid': info.st_gid,
        'size': None if portable and kind == 'directory' else info.st_size,
        'mtime_ns': info.st_mtime_ns,
        'content': hashlib.sha256(entry.read_bytes()).hexdigest() if kind == 'file' else None,
        'target': os.readlink(entry) if kind == 'link' else None,
    })
print(json.dumps({'git': git_state, 'entries': entries}, sort_keys=True, separators=(',', ':')))
PYTHON
BASH
}

assert_source_snapshot() {
    local path=$1
    local expected=$2
    local portable=${3:-false}
    local actual="${expected}.actual"
    snapshot_source "$path" "$actual" "$portable"
    remote_command cmp "$expected" "$actual"
    remote_command rm -f "$actual"
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
printf 'APP_URL=https://orb105-preserve.orbit\n' > "$path/.env"
git -C "$path" config orb105.proof retained
git -C "$path" update-ref refs/orb105/proof HEAD
printf '#!/bin/sh\nexit 0\n' > "$path/orb105-executable"
chmod 0750 "$path/orb105-executable"
ln -s orb105-untracked.txt "$path/orb105-link"
git -C "$path" checkout --detach >/dev/null
BASH
        snapshot_source "$source" /tmp/orb105-preserve-before
        output=$(register_source "$source" --hostname=orb105-preserve.orbit)
        destination=/home/orbit/apps/laravel-typed/orb105-preserve
        assert_registration "$output" checkout "$destination"
        assert_source_snapshot "$destination" /tmp/orb105-preserve-before
        remote_command rm -f /tmp/orb105-preserve-before
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
        install_projection_fault
        trap clear_projection_fault EXIT
        set +e
        failure=$(register_source "$source" 2>&1)
        status=$?
        set -e
        clear_projection_fault
        trap - EXIT
        test "$status" -ne 0
        test "$(json_field "$failure" error.code)" = instance.registration_incomplete
        destination=/home/orbit/apps/laravel-typed/orb105-rollback
        remote_script "$destination" <<'BASH'
path=$1
test "$(sha256sum "$path/.env" | cut -d' ' -f1)" = "$(cat /tmp/orb105-env-before)"
grep -Fx ORB105_SENTINEL=retained "$path/.env"
BASH
        output=$(register_source "$destination")
        remove_instance "$(json_field "$output" app_instance.id)"
        ;;
    manual-default-migration)
        root=/home/orbit/orb105-migration
        source="$root/laravel-typed"
        create_checkout "$source" 13.x
        remote_script "$source" <<'BASH'
path=$1
printf '%s\n' 'APP_URL=https://migration-before.invalid' 'ORB105_MIGRATION_SENTINEL=retained' > "$path/.env"
BASH
        snapshot_source "$source" /tmp/orb105-migration-before
        commit=$(remote_command git -C "$source" rev-parse HEAD)
        seeded=$(gateway_state seed-migration "$source" "$commit")
        id=$(json_field "$seeded" id)
        route_id=$(json_field "$seeded" route_id)

        before=$(gateway_state migration-state "$id")
        install_projection_fault
        trap clear_projection_fault EXIT
        set +e
        failure=$(register_source "$source" 2>&1)
        status=$?
        set -e
        clear_projection_fault
        trap - EXIT
        test "$status" -ne 0
        test "$(json_field "$failure" error.code)" = instance.registration_incomplete
        failed=$(gateway_state migration-state "$id")
        python3 -c 'import json,sys
before=json.loads(sys.argv[1]); after=json.loads(sys.argv[2])
preserved=("id","name","path","migration_required","status","branch","selected_php_version","source_is_laravel","route_id","hostname","route_status","route_provenance","route_publication","route_target_instance_id")
assert {key: before[key] for key in preserved} == {key: after[key] for key in preserved}
assert after["failed_step"] == "registration"
assert isinstance(after["error_code"], str) and after["error_code"]' "$before" "$failed"
        remote_command test -d "$source"
        remote_command test ! -e /home/orbit/apps/laravel-typed/default
        assert_source_snapshot "$source" /tmp/orb105-migration-before

        output=$(register_source "$source")
        test "$(json_field "$output" app_instance.id)" = "$id"
        state=$(gateway_state migration-state "$id")
        test "$(json_field "$state" name)" = default
        test "$(json_field "$state" path)" = /home/orbit/apps/laravel-typed/default
        test "$(json_field "$state" migration_required)" = false
        test "$(json_field "$state" route_id)" = "$route_id"
        test "$(json_field "$state" hostname)" = orb105-migration.orbit
        test "$(json_field "$state" selected_php_version)" = 8.5
        test "$(json_field "$state" source_is_laravel)" = true
        test "$(json_field "$state" failed_step)" = None
        test "$(json_field "$state" error_code)" = None
        test "$(json_field "$state" relocation_state)" = relocated
        test "$(json_field "$state" authoritative_path)" = /home/orbit/apps/laravel-typed/default
        test "$(json_field "$state" route_target_instance_id)" = "$id"
        remote_command test ! -e "$source"
        remote_command rm -f /tmp/orb105-migration-before
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
        destination=/home/orbit/apps/laravel-typed/orb105-cross
        snapshot_source "$source" /tmp/orb105-cross-before true
        install_projection_fault
        trap clear_projection_fault EXIT
        set +e
        failure=$(register_request "$source" 2>&1)
        status=$?
        set -e
        clear_projection_fault
        trap - EXIT
        test "$status" -ne 0
        test "$(json_field "$failure" error.code)" = instance.registration_incomplete
        retained=$(gateway_state registration-by-original "$source")
        id=$(json_field "$retained" id)
        route_id=$(json_field "$retained" route_id)
        test "$(json_field "$retained" path)" = "$destination"
        assert_source_snapshot "$destination" /tmp/orb105-cross-before true

        stage="${destination}.orbit-stage-${id}"
        remote_script "$source" "$destination" "$stage" <<'BASH'
source=$1
destination=$2
stage=$3
test ! -e "$source" && test -d "$destination" && test ! -e "$stage"
cp -a "$destination" "$source"
cp -a "$destination" "$stage"
printf 'incomplete stage\n' > "$stage/README.md"
rm -rf -- "$destination"
BASH
        gateway_state set-relocation-checkpoint "$id" relocating "$source" >/dev/null
        incomplete=$(register_request "$source")
        test "$(json_field "$incomplete" app_instance.id)" = "$id"
        test "$(json_field "$incomplete" app_instance.route.id)" = "$route_id"
        remote_command test ! -e "$source"
        remote_command test ! -e "$stage"
        assert_source_snapshot "$destination" /tmp/orb105-cross-before true

        remote_script "$source" "$destination" "$stage" <<'BASH'
source=$1
destination=$2
stage=$3
test ! -e "$source" && test -d "$destination" && test ! -e "$stage"
cp -a "$destination" "$source"
cp -a "$destination" "$stage"
rm -rf -- "$destination"
BASH
        gateway_state set-relocation-checkpoint "$id" relocating "$source" >/dev/null
        complete=$(register_request "$source")
        test "$(json_field "$complete" app_instance.id)" = "$id"
        test "$(json_field "$complete" app_instance.route.id)" = "$route_id"
        remote_command test ! -e "$source"
        remote_command test ! -e "$stage"
        assert_source_snapshot "$destination" /tmp/orb105-cross-before true

        remote_command cp -a "$destination" "$source"
        gateway_state set-relocation-checkpoint "$id" relocating "$source" >/dev/null
        duplicate=$(register_request "$source")
        test "$(json_field "$duplicate" app_instance.id)" = "$id"
        test "$(json_field "$duplicate" app_instance.route.id)" = "$route_id"
        remote_command test ! -e "$source"
        assert_source_snapshot "$destination" /tmp/orb105-cross-before true

        gateway_state set-relocation-checkpoint "$id" relocating "$source" >/dev/null
        destination_only=$(register_request "$source")
        test "$(json_field "$destination_only" app_instance.id)" = "$id"
        test "$(json_field "$destination_only" app_instance.route.id)" = "$route_id"
        remote_command test ! -e "$source"
        assert_source_snapshot "$destination" /tmp/orb105-cross-before true
        remote_command rm -f /tmp/orb105-cross-before
        remove_instance "$id"
        ;;
    *) exit 64 ;;
esac

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
case "$path" in /home/orbit/orb105-*|/dev/shm/orb105-*|/home/orbit/apps/laravel-typed/orb105-*) ;; *) exit 64 ;; esac
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
mkdir -p "$(dirname "$root")" "$(dirname "$child")"
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
    remote_command php "$registration_fixture" "$@"
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
    local ignored=${4:-__ORB105_NO_IGNORED_PATH__}
    remote_script "$path" "$output" "$portable" "$ignored" <<'BASH'
path=$1
output=$2
portable=$3
ignored=$4
if [ "$ignored" = __ORB105_NO_IGNORED_PATH__ ]; then
    ignored=
fi
case "$output" in /tmp/orb105-*) ;; *) exit 64 ;; esac
env GIT_OPTIONAL_LOCKS=0 python3 - "$path" "$portable" "$ignored" > "$output" <<'PYTHON'
import base64, hashlib, json, os, pathlib, stat, subprocess, sys
root = pathlib.Path(sys.argv[1])
portable = sys.argv[2] == 'true'
ignored = sys.argv[3]
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
    'index': hashlib.sha256(pathlib.Path(
        subprocess.check_output(
            ['git', '-C', str(root), 'rev-parse', '--path-format=absolute', '--git-path', 'index'],
            env=environment,
        ).decode().strip(),
    ).read_bytes()).hexdigest(),
}
entries = []
selected = [root, *[
    entry for entry in sorted(root.rglob('*'))
    if not ignored or entry.relative_to(root).parts[0] != ignored
]]
for entry in selected:
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
    local ignored=${4:-}
    local actual="${expected}.actual"
    snapshot_source "$path" "$actual" "$portable" "$ignored"
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

        source=/home/orbit/apps/laravel-typed/orb105-already
        create_checkout "$source" orb105-already
        output=$(register_source "$source")
        assert_registration "$output" checkout "$source"
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
        id=$(json_field "$output" app_instance.id)
        route_id=$(json_field "$output" app_instance.route.id)
        hostname=$(json_field "$output" app_instance.hostname)
        test "$(json_field "$output" app_instance.url)" = "https://$hostname"
        remote_command grep -F "APP_URL=https://$hostname" "$destination/.env" >/dev/null
        remote_script "$destination" <<'BASH'
path=$1
printf '%s\n' 'normal edit after registration' >> "$path/README.md"
BASH
        retry=$(register_request "$source")
        test "$(json_field "$retry" app_instance.id)" = "$id"
        test "$(json_field "$retry" app_instance.route.id)" = "$route_id"
        remote_command grep -Fx 'normal edit after registration' "$destination/README.md" >/dev/null
        remote_command git -C "$destination" remote set-url origin https://github.com/acme/replacement.git
        set +e
        conflict=$(register_request "$source" 2>&1)
        conflict_status=$?
        set -e
        test "$conflict_status" -ne 0
        test "$(json_field "$conflict" error.code)" = instance.registration_conflict
        remote_command git -C "$destination" remote set-url origin https://github.com/laravel/laravel.git
        retry=$(register_request "$source")
        test "$(json_field "$retry" app_instance.id)" = "$id"
        test "$(json_field "$retry" app_instance.route.id)" = "$route_id"
        remove_instance "$id"
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
        output=$(register_source "$root" --include-worktrees --hostname=orb105-primary.orbit)
        test "$(json_field "$output" source_count)" = 2
        test "$(json_field "$output" completed_count)" = 2
        python3 -c 'import json,sys
value=json.loads(sys.argv[1]); members={member["name"]:member for member in value["app_instances"]}
assert members["orb105-set-root"]["hostname"] == "orb105-primary.orbit"
assert members["orb105-set-child"]["hostname"] != "orb105-primary.orbit"
assert members["orb105-set-root"]["route"]["id"] != members["orb105-set-child"]["route"]["id"]' "$output"
        retry=$(register_request "$root" true orb105-primary.orbit)
        test "$(json_field "$retry" source_count)" = 2
        test "$(json_field "$retry" completed_count)" = 2
        remove_instance "$(json_field "$output" app_instance.id)"

        root=/home/orbit/orb105-owner-root
        child=/home/orbit/orb105-owner-child
        create_graph "$root" "$child"
        remote_command sudo chown root:root "$child/composer.json"
        set +e
        failure=$(register_source "$root" --include-worktrees 2>&1)
        status=$?
        set -e
        test "$status" -ne 0
        test "$(json_field "$failure" error.code)" = app-dev.source_metadata_unsafe
        remote_command test -d "$root" -a -d "$child"
        state=$(gateway_state registration-set-count "$root" "$child")
        test "$(json_field "$state" instances)" = 0
        test "$(json_field "$state" routes)" = 0
        remote_command sudo chown orbit:orbit "$child/composer.json"
        cleanup_path "$root" "$child"

        source=/home/orbit/orb105-credential
        create_checkout "$source" orb105-credential
        remote_command git -C "$source" remote set-url origin https://orb105-user:orb105-token@example.invalid/acme.git
        set +e
        failure=$(register_source "$source" 2>&1)
        status=$?
        set -e
        test "$status" -ne 0
        test "$(json_field "$failure" error.code)" = instance.source_invalid
        case "$failure" in *orb105-token*) exit 1 ;; esac
        cleanup_path "$source"

        managed=/home/orbit/orb105-managed-owner
        source="$managed/nested"
        create_checkout "$source" orb105-overlap
        owner_state=$(gateway_state seed-overlap-owner "$managed")
        owner_id=$(json_field "$owner_state" id)
        set +e
        failure=$(register_source "$source" 2>&1)
        status=$?
        set -e
        test "$status" -ne 0
        test "$(json_field "$failure" error.code)" = instance.source_conflict
        remote_command test -d "$source"
        gateway_state delete-overlap-owner "$owner_id" >/dev/null
        cleanup_path "$managed"

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
        remote_command rm -f /tmp/orb105-gitfile

        base=/home/orbit/orb105-collision-equal
        root="$base/primary/shared"
        child="$base/linked/shared"
        create_graph "$root" "$child"
        snapshot_source "$root" /tmp/orb105-collision-equal-root
        snapshot_source "$child" /tmp/orb105-collision-equal-child
        for attempt in 1 2; do
            set +e
            failure=$(register_source "$root" --include-worktrees 2>&1)
            status=$?
            set -e
            test "$status" -ne 0
            test "$(json_field "$failure" error.code)" = instance.identity_conflict
            state=$(gateway_state registration-set-count "$root" "$child")
            test "$(json_field "$state" instances)" = 0
            test "$(json_field "$state" routes)" = 0
            assert_source_snapshot "$root" /tmp/orb105-collision-equal-root
            assert_source_snapshot "$child" /tmp/orb105-collision-equal-child
        done
        remote_command rm -f /tmp/orb105-collision-equal-root /tmp/orb105-collision-equal-child
        cleanup_path "$base"

        base=/home/orbit/orb105-collision-explicit
        root="$base/primary/source"
        child="$base/linked/feature"
        create_graph "$root" "$child"
        snapshot_source "$root" /tmp/orb105-collision-explicit-root
        snapshot_source "$child" /tmp/orb105-collision-explicit-child
        for attempt in 1 2; do
            set +e
            failure=$(register_source "$root" --include-worktrees --name=feature 2>&1)
            status=$?
            set -e
            test "$status" -ne 0
            test "$(json_field "$failure" error.code)" = instance.identity_conflict
            state=$(gateway_state registration-set-count "$root" "$child")
            test "$(json_field "$state" instances)" = 0
            test "$(json_field "$state" routes)" = 0
            assert_source_snapshot "$root" /tmp/orb105-collision-explicit-root
            assert_source_snapshot "$child" /tmp/orb105-collision-explicit-child
        done
        remote_command rm -f /tmp/orb105-collision-explicit-root /tmp/orb105-collision-explicit-child
        cleanup_path "$base"
        ;;
    cross-filesystem-registration-retry)
        source=/dev/shm/orb105-cross
        create_checkout "$source" orb105-cross
        destination=/home/orbit/apps/laravel-typed/orb105-cross
        remote_script "$source" <<'BASH'
path=$1
mkdir "$path/orb105-cleanup"
printf '/orb105-cleanup/\n' >> "$path/.git/info/exclude"
for index in $(seq -w 0 30000); do
    printf 'retained\n' > "$path/orb105-cleanup/$index"
done
BASH
        snapshot_source "$source" /tmp/orb105-cross-before true orb105-cleanup
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
        assert_source_snapshot "$destination" /tmp/orb105-cross-before true orb105-cleanup
        test "$(remote_command find "$destination/orb105-cleanup" -type f | wc -l)" = 30001

        remote_script "$source" "$destination" <<'BASH'
source=$1
destination=$2
test ! -e "$source" && test -d "$destination"
cp -a "$destination" "$source"
BASH
        gateway_state set-relocation-checkpoint "$id" relocating "$source" >/dev/null

        set +e
        register_request "$source" >/tmp/orb105-cleanup-request 2>&1 &
        request_pid=$!
        set -e
        remote_script "$source" <<'BASH'
source=$1
deadline=$((SECONDS + 60))
cleanup_pid=
while [ -z "$cleanup_pid" ]; do
    test "$SECONDS" -lt "$deadline"
    for command_line in /proc/[0-9]*/cmdline; do
        test -r "$command_line" || continue
        command=$(tr '\0' ' ' < "$command_line")
        case "$command" in
            *"python3 -c"*" cleanup "*"$source"*)
                cleanup_pid=${command_line#/proc/}
                cleanup_pid=${cleanup_pid%/cmdline}
                break
                ;;
        esac
    done
done
while [ -e "$source/orb105-cleanup/00000" ]; do
    test "$SECONDS" -lt "$deadline"
    kill -0 "$cleanup_pid"
done
test -d "$source"
kill -KILL "$cleanup_pid"
BASH
        set +e
        wait "$request_pid"
        status=$?
        set -e
        failure=$(cat /tmp/orb105-cleanup-request)
        rm -f /tmp/orb105-cleanup-request
        test "$status" -ne 0
        test "$(json_field "$failure" error.code)" = instance.registration_incomplete
        interrupted=$(gateway_state registration-by-original "$source")
        test "$(json_field "$interrupted" id)" = "$id"
        test "$(json_field "$interrupted" route_id)" = "$route_id"
        test "$(json_field "$interrupted" relocation_state)" = original_cleanup
        test "$(json_field "$interrupted" authoritative_path)" = "$destination"
        remote_command test -d "$source"
        remote_command test ! -e "$source/orb105-cleanup/00000"
        assert_source_snapshot "$destination" /tmp/orb105-cross-before true orb105-cleanup
        test "$(remote_command find "$destination/orb105-cleanup" -type f | wc -l)" = 30001

        set +e
        completed=$(register_request "$source" 2>&1)
        retry_status=$?
        set -e
        if [ "$retry_status" -ne 0 ]; then
            printf '%s\n' "$completed" >&2
            exit "$retry_status"
        fi
        test "$(json_field "$completed" app_instance.id)" = "$id"
        test "$(json_field "$completed" app_instance.route.id)" = "$route_id"
        remote_command test ! -e "$source"
        remote_command test -f "$destination/orb105-cleanup/30000"
        remote_command rm -f /tmp/orb105-cross-before
        remove_instance "$id"
        ;;
    *) exit 64 ;;
esac

#!/usr/bin/env bash
set -euo pipefail

scenario=${1:-}
repository=/home/orbit/orbit
gateway="$repository/apps/gateway"
fixture=/var/lib/orbit-e2e/proof/orb-178-checkout-removal.php
ssh_key=/home/orbit/.orbit/ssh/id_ed25519
known_hosts=/home/orbit/.orbit/ssh/known_hosts
app_dev_ip=10.44.0.2
export GIT_OPTIONAL_LOCKS=0

if [ ! -f "$fixture" ]; then
    fixture="$repository/.loop/proof/orb-178-checkout-removal.php"
fi

remote_command() {
    ssh \
        -i "$ssh_key" \
        -o BatchMode=yes \
        -o IdentitiesOnly=yes \
        -o StrictHostKeyChecking=yes \
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

seed_instance() {
    local name=$1
    local layout=$2
    local branch=$3
    local commit=$4
    local path=${5:-}
    gateway_fixture seed "$name" "$layout" "$branch" "$commit" "$path" \
        | python3 -c 'import json, sys; value=json.load(sys.stdin); print(value["id"])'
}

make_checkout() {
    local name=$1
    local branch=$2
    local mode=${3:-clean}

    remote_script "$name" "$branch" "$mode" <<'BASH'
name=$1
branch=$2
mode=$3
case "$name" in orb178-[a-z0-9-]*) ;; *) exit 64 ;; esac
case "$branch" in orb178-[a-z0-9-]*) ;; *) exit 64 ;; esac
template=/home/orbit/apps/laravel-typed/e2e-dev
path="/home/orbit/apps/laravel-typed/$name"
test ! -e "$path"
git clone --local --no-checkout "$template" "$path" >/dev/null
git -C "$path" remote set-url origin https://github.com/laravel/laravel.git
git -C "$path" checkout -b "$branch" HEAD >/dev/null
git -C "$path" config user.name 'Orbit proof'
git -C "$path" config user.email orbit-proof@example.invalid
starting=$(git -C "$path" rev-parse HEAD)

case "$mode" in
    clean) ;;
    dirty)
        printf 'dirty source\n' > "$path/orb178-dirty.txt"
        ;;
    unpublished)
        printf 'unpublished source\n' > "$path/orb178-unpublished.txt"
        git -C "$path" add orb178-unpublished.txt
        git -C "$path" commit -m 'Add unpublished ORB-178 proof commit' >/dev/null
        ;;
    published-behind)
        advertised_descendant=$(git -C "$path" rev-parse HEAD)
        git -C "$path" reset --hard HEAD^ >/dev/null
        starting=$(git -C "$path" rev-parse HEAD)
        while read -r ref; do
            test "$ref" = "refs/heads/$branch" || git -C "$path" update-ref -d "$ref"
        done < <(git -C "$path" for-each-ref --format='%(refname)')
        git -C "$path" reflog expire --expire=now --all
        git -C "$path" gc --prune=now >/dev/null
        ! git -C "$path" cat-file -e "$advertised_descendant^{commit}" 2>/dev/null
        remote_advertisement=$(git -C "$path" ls-remote --exit-code origin)
        awk -v commit="$advertised_descendant" '$1 == commit { found=1 } END { exit found ? 0 : 1 }' \
            <<<"$remote_advertisement"
        ;;
    *) exit 64 ;;
esac

printf '%s %s\n' "$starting" "$(git -C "$path" rev-parse HEAD)"
BASH
}

make_symlink_checkout() {
    local name=$1
    local branch=$2

    remote_script "$name" "$branch" <<'BASH'
name=$1
branch=$2
case "$name" in orb178-[a-z0-9-]*) ;; *) exit 64 ;; esac
real="/home/orbit/${name}-real"
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
    local root_name=$1
    local root_branch=$2
    shift 2

    remote_script "$root_name" "$root_branch" "$@" <<'BASH'
root_name=$1
root_branch=$2
shift 2
case "$root_name" in orb178-[a-z0-9-]*) ;; *) exit 64 ;; esac
root="/home/orbit/apps/laravel-typed/$root_name"
test ! -e "$root"
git clone --local --no-checkout /home/orbit/apps/laravel-typed/e2e-dev "$root" >/dev/null
git -C "$root" remote set-url origin https://github.com/laravel/laravel.git
git -C "$root" checkout -b "$root_branch" HEAD >/dev/null

while [ "$#" -gt 0 ]; do
    child=$1
    branch=$2
    shift 2
    case "$child" in orb178-[a-z0-9-]*) ;; *) exit 64 ;; esac
    git -C "$root" worktree add -b "$branch" "/home/orbit/apps/laravel-typed/$child" HEAD >/dev/null
done

git -C "$root" rev-parse HEAD
BASH
}

remove_sources() {
    remote_script "$@" <<'BASH'
for name in "$@"; do
    case "$name" in orb178-[a-z0-9-]*) ;; *) exit 64 ;; esac
    path="/home/orbit/apps/laravel-typed/$name"
    if [ -L "$path" ]; then
        rm -- "$path"
    elif [ -e "$path" ]; then
        rm -rf -- "$path"
    fi
    rm -rf -- "/home/orbit/${name}-real" "/home/orbit/${name}-original"
done
BASH
}

source_snapshot() {
    local name=$1

    source_path_snapshot "/home/orbit/apps/laravel-typed/$name"
}

source_path_snapshot() {
    local path=$1

    remote_script "$path" <<'BASH'
path=$1
case "$path" in
    /home/orbit/apps/laravel-typed/orb178-[a-z0-9-]*|/home/orbit/orb178-[a-z0-9-]*-original|/etc/laravel-typed/orb178-[a-z0-9-]*) ;;
    *) exit 64 ;;
esac
test -e "$path" || test -L "$path"
find -L "$path" -xdev -printf '%P\t%y\t%m\t%u\t%g\t%s\t%T@\n' -exec sha256sum {} \; 2>/dev/null \
    | LC_ALL=C sort \
    | sha256sum \
    | cut -d ' ' -f 1
BASH
}

git_storage_snapshot() {
    local name=$1

    remote_script "$name" <<'BASH'
path="/home/orbit/apps/laravel-typed/$1"
test -d "$path/.git"
{
    git -C "$path" show-ref || true
    find "$path/.git/objects" "$path/.git/refs" -type f -printf '%p\t%s\t%T@\n' -exec sha256sum {} \;
    if [ -f "$path/.git/packed-refs" ]; then
        stat -c '%s %Y' "$path/.git/packed-refs"
        sha256sum "$path/.git/packed-refs"
    fi
    stat -c '%s %Y' "$path/.git/index"
    sha256sum "$path/.git/index"
} | LC_ALL=C sort | sha256sum | cut -d ' ' -f 1
BASH
}

assert_state_unchanged() {
    local before=$1
    local name=$2
    local after
    after=$(gateway_fixture state "$name")
    test "$before" = "$after"
}

assert_refusal_preserves() {
    local id=$1
    local source_name=$2
    local force=$3
    local expected_code=$4
    local state_name=${5:-$source_name}
    local before_state before_source after_source output status
    before_state=$(gateway_fixture state "$state_name")
    before_source=$(source_snapshot "$source_name")

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
    assert_state_unchanged "$before_state" "$state_name"
    after_source=$(source_snapshot "$source_name")
    test "$before_source" = "$after_source"
}

remove_success() {
    local id=$1
    local name=$2
    local force=$3
    local output

    if [ "$force" = 1 ]; then
        output=$(remote_command orbit instance:remove "$id" --force --json)
    else
        output=$(remote_command orbit instance:remove "$id" --json)
    fi

    python3 -c '
import json
import sys
value = json.loads(sys.argv[1])
if value.get("id") != int(sys.argv[2]) or value.get("name") != sys.argv[3]:
    raise SystemExit(65)
if not isinstance(value.get("request_id"), str):
    raise SystemExit(65)
' "$output" "$id" "$name"
    remote_script "$name" <<'BASH'
test ! -e "/home/orbit/apps/laravel-typed/$1"
BASH
    gateway_fixture missing "$name"
}

cleanup_case() {
    gateway_fixture cleanup "$@" || true
    remove_sources "$@"
}

case "$scenario" in
    setup-app-dev)
        test -d /home/orbit/apps/laravel-typed/e2e-dev/.git
        test -x /usr/bin/git
        orbit node:list --json | python3 -c 'import json, sys; value=json.load(sys.stdin); assert isinstance(value.get("nodes"), list)'
        ;;

    setup-gateway)
        test -f "$fixture"
        gateway_fixture setup
        ;;

    checkout-publication-boundary)
        read -r clean_commit _ < <(make_checkout orb178-clean orb178-clean clean)
        clean_id=$(seed_instance orb178-clean checkout orb178-clean "$clean_commit")
        remove_success "$clean_id" orb178-clean 0
        gateway_fixture cleanup orb178-clean || true

        read -r behind_commit _ < <(make_checkout orb178-published-behind orb178-published-behind published-behind)
        behind_id=$(seed_instance orb178-published-behind checkout orb178-published-behind "$behind_commit")
        before_git=$(git_storage_snapshot orb178-published-behind)
        before_source=$(source_snapshot orb178-published-behind)
        gateway_fixture inspect orb178-published-behind 0 | python3 -c '
import json
import sys
value=json.load(sys.stdin)
if value.get("linked_worktree_paths") != ["/home/orbit/apps/laravel-typed/orb178-published-behind"]:
    raise SystemExit(65)
'
        test "$before_git" = "$(git_storage_snapshot orb178-published-behind)"
        test "$before_source" = "$(source_snapshot orb178-published-behind)"
        remove_success "$behind_id" orb178-published-behind 0
        gateway_fixture cleanup orb178-published-behind || true

        read -r dirty_commit _ < <(make_checkout orb178-dirty orb178-dirty dirty)
        dirty_id=$(seed_instance orb178-dirty checkout orb178-dirty "$dirty_commit")
        assert_refusal_preserves "$dirty_id" orb178-dirty 0 instance.remove_refused
        cleanup_case orb178-dirty

        read -r unpublished_commit _ < <(make_checkout orb178-unpublished orb178-unpublished unpublished)
        unpublished_id=$(seed_instance orb178-unpublished checkout orb178-unpublished "$unpublished_commit")
        assert_refusal_preserves "$unpublished_id" orb178-unpublished 0 instance.remove_refused
        cleanup_case orb178-unpublished
        ;;

    checkout-removal-refusals)
        read -r forced_commit _ < <(make_checkout orb178-force-unreachable orb178-force-unreachable dirty)
        forced_id=$(seed_instance orb178-force-unreachable checkout orb178-force-unreachable "$forced_commit")
        remote_script <<'BASH'
path=/home/orbit/apps/laravel-typed/orb178-force-unreachable
probe=/home/orbit/.orbit/orb178-git-ssh-probe
attempt=/home/orbit/.orbit/orb178-git-ssh-attempted
git -C "$path" remote set-url origin git@github.com:laravel/laravel.git
! git config --global --get-all core.sshCommand
cat > "$probe" <<'PROBE'
#!/bin/sh
printf 'remote publication attempted\n' >> /home/orbit/.orbit/orb178-git-ssh-attempted
exit 78
PROBE
chmod 700 "$probe"
rm -f -- "$attempt"
git config --global core.sshCommand "$probe"
scratch=$(mktemp -d)
git init --bare --quiet "$scratch/repository.git"
git --git-dir="$scratch/repository.git" remote add origin git@github.com:laravel/laravel.git
! git --git-dir="$scratch/repository.git" fetch --quiet origin >/dev/null 2>&1
test -s "$attempt"
rm -rf -- "$scratch"
rm -f -- "$attempt"
BASH
        set +e
        output=$(remote_command orbit instance:remove "$forced_id" --force --json 2>&1)
        status=$?
        set -e
        remote_script <<'BASH'
probe=/home/orbit/.orbit/orb178-git-ssh-probe
attempt=/home/orbit/.orbit/orb178-git-ssh-attempted
attempted=0
test ! -e "$attempt" || attempted=1
git config --global --unset-all core.sshCommand
rm -f -- "$probe" "$attempt"
test "$attempted" -eq 0
BASH
        test "$status" -eq 0
        python3 -c '
import json
import sys
value = json.loads(sys.argv[1])
if value.get("id") != int(sys.argv[2]) or value.get("name") != sys.argv[3]:
    raise SystemExit(65)
if not isinstance(value.get("request_id"), str):
    raise SystemExit(65)
' "$output" "$forced_id" orb178-force-unreachable
        remote_script <<'BASH'
test ! -e /home/orbit/apps/laravel-typed/orb178-force-unreachable
BASH
        gateway_fixture missing orb178-force-unreachable
        gateway_fixture cleanup orb178-force-unreachable || true

        read -r owner_commit _ < <(make_checkout orb178-owner orb178-owner clean)
        owner_id=$(seed_instance orb178-owner checkout orb178-owner "$owner_commit")
        remote_script <<'BASH'
sudo chown root:root /home/orbit/apps/laravel-typed/orb178-owner
BASH
        assert_refusal_preserves "$owner_id" orb178-owner 1 instance.force_failed
        remote_script <<'BASH'
sudo chown orbit:orbit /home/orbit/apps/laravel-typed/orb178-owner
BASH
        cleanup_case orb178-owner

        read -r path_commit _ < <(make_checkout orb178-path-actual orb178-path-actual clean)
        path_id=$(seed_instance orb178-path checkout orb178-path-actual "$path_commit" /home/orbit/apps/laravel-typed/orb178-path-actual)
        assert_refusal_preserves "$path_id" orb178-path-actual 1 instance.checkout_path_unsafe orb178-path
        gateway_fixture cleanup orb178-path
        remove_sources orb178-path-actual

        remote_script <<'BASH'
sudo mkdir -p /etc/laravel-typed
sudo git clone --local --no-checkout /home/orbit/apps/laravel-typed/e2e-dev /etc/laravel-typed/orb178-containment >/dev/null
sudo git -C /etc/laravel-typed/orb178-containment checkout -b orb178-containment HEAD >/dev/null
sudo chown -R orbit:orbit /etc/laravel-typed/orb178-containment
BASH
        containment_commit=$(remote_script <<'BASH'
git -C /etc/laravel-typed/orb178-containment rev-parse HEAD
BASH
)
        containment_id=$(seed_instance orb178-containment checkout orb178-containment "$containment_commit" /etc/laravel-typed/orb178-containment)
        before=$(gateway_fixture state orb178-containment)
        before_containment_source=$(source_path_snapshot /etc/laravel-typed/orb178-containment)
        set +e
        output=$(remote_command orbit instance:remove "$containment_id" --force --json 2>&1)
        status=$?
        set -e
        test "$status" -ne 0
        python3 -c 'import json,sys; assert json.loads(sys.argv[1])["error"]["code"] == "instance.checkout_path_unsafe"' "$output"
        assert_state_unchanged "$before" orb178-containment
        test "$before_containment_source" = "$(source_path_snapshot /etc/laravel-typed/orb178-containment)"
        remote_script <<'BASH'
test -d /etc/laravel-typed/orb178-containment/.git
sudo rm -rf -- /etc/laravel-typed/orb178-containment
sudo rmdir --ignore-fail-on-non-empty /etc/laravel-typed
BASH
        gateway_fixture cleanup orb178-containment

        symlink_commit=$(make_symlink_checkout orb178-symlink orb178-symlink)
        symlink_id=$(seed_instance orb178-symlink checkout orb178-symlink "$symlink_commit")
        assert_refusal_preserves "$symlink_id" orb178-symlink 1 instance.force_failed
        cleanup_case orb178-symlink

        read -r overlap_commit _ < <(make_checkout orb178-overlap orb178-overlap clean)
        overlap_id=$(seed_instance orb178-overlap checkout orb178-overlap "$overlap_commit")
        seed_instance orb178-overlap-marker checkout orb178-overlap-marker "$overlap_commit" /home/orbit/apps/laravel-typed/orb178-overlap/nested >/dev/null
        before_overlap_marker=$(gateway_fixture state orb178-overlap-marker)
        assert_refusal_preserves "$overlap_id" orb178-overlap 1 instance.checkout_path_unsafe
        assert_state_unchanged "$before_overlap_marker" orb178-overlap-marker
        cleanup_case orb178-overlap orb178-overlap-marker

        graph_commit=$(make_worktree_graph \
            orb178-linked-root orb178-linked-root \
            orb178-linked-owned orb178-linked-owned \
            orb178-linked-external orb178-linked-external)
        linked_id=$(seed_instance orb178-linked-root checkout orb178-linked-root "$graph_commit")
        seed_instance orb178-linked-owned worktree orb178-linked-owned "$graph_commit" >/dev/null
        before_state=$(gateway_fixture state orb178-linked-root)
        before_source=$(source_snapshot orb178-linked-root)
        before_owned_source=$(source_snapshot orb178-linked-owned)
        before_external_source=$(source_snapshot orb178-linked-external)
        before_refs=$(remote_script <<'BASH'
root=/home/orbit/apps/laravel-typed/orb178-linked-root
git -C "$root" show-ref
git -C "$root" ls-remote origin
BASH
)
        assert_refusal_preserves "$linked_id" orb178-linked-root 1 instance.remove_refused
        assert_state_unchanged "$before_state" orb178-linked-root
        test "$before_source" = "$(source_snapshot orb178-linked-root)"
        test "$before_owned_source" = "$(source_snapshot orb178-linked-owned)"
        test "$before_external_source" = "$(source_snapshot orb178-linked-external)"
        gateway_fixture state orb178-linked-owned >/dev/null
        after_refs=$(remote_script <<'BASH'
root=/home/orbit/apps/laravel-typed/orb178-linked-root
test -d /home/orbit/apps/laravel-typed/orb178-linked-owned
test -d /home/orbit/apps/laravel-typed/orb178-linked-external
git -C "$root" show-ref
git -C "$root" ls-remote origin
BASH
)
        test "$before_refs" = "$after_refs"
        gateway_fixture cleanup orb178-linked-owned orb178-linked-root
        remove_sources orb178-linked-owned orb178-linked-external orb178-linked-root

        for force in 0 1; do
            for mutation in replacement origin; do
                name="orb178-race-${mutation}-${force}"
                read -r race_commit _ < <(make_checkout "$name" "$name" clean)
                seed_instance "$name" checkout "$name" "$race_commit" >/dev/null
                before=$(gateway_fixture state "$name")
                gateway_fixture race "$name" "$mutation" "$force"
                assert_state_unchanged "$before" "$name"
                remote_script "$name" "$mutation" <<'BASH'
name=$1
mutation=$2
test -d "/home/orbit/apps/laravel-typed/$name/.git"
if [ "$mutation" = replacement ]; then
    test -d "/home/orbit/${name}-original/.git"
fi
BASH
                cleanup_case "$name"
            done
        done

        read -r equivalent_commit _ < <(make_checkout orb178-equivalent-origin orb178-equivalent-origin clean)
        seed_instance orb178-equivalent-origin checkout orb178-equivalent-origin "$equivalent_commit" >/dev/null
        gateway_fixture race orb178-equivalent-origin equivalent-origin 1
        remote_script <<'BASH'
test ! -e /home/orbit/apps/laravel-typed/orb178-equivalent-origin
BASH
        gateway_fixture cleanup orb178-equivalent-origin || true
        ;;

    *)
        printf 'Unknown ORB-178 proof scenario: %s\n' "$scenario" >&2
        exit 64
        ;;
esac

printf 'ORB-178 %s: ok\n' "$scenario"

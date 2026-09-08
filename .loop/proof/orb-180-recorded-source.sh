#!/usr/bin/env bash
set -euo pipefail

scenario=${1:-}
repository=/home/orbit/orbit
gateway="$repository/apps/gateway"
fixture=/var/lib/orbit-e2e/proof/orb-180-recorded-source.php
ssh_key=/home/orbit/.orbit/ssh/id_ed25519
known_hosts=/home/orbit/.orbit/ssh/known_hosts
app_dev_ip=10.44.0.2
export GIT_OPTIONAL_LOCKS=0

if [ ! -f "$fixture" ]; then
    fixture="$repository/.loop/proof/orb-180-recorded-source.php"
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
    remote_command bash -seuo pipefail -- "$@"
}

gateway_fixture() {
    (
        cd "$gateway"
        php "$fixture" "$@"
    )
}

probe_surfaces() {
    remote_command orbit node:list --json | python3 -c '
import json
import sys
value = json.load(sys.stdin)
if not isinstance(value.get("nodes"), list) or not isinstance(value.get("request_id"), str):
    raise SystemExit(65)
'
    gateway_fixture setup
}

json_field() {
    python3 -c 'import json,sys; value=json.loads(sys.argv[1]); print(value[sys.argv[2]])' "$1" "$2"
}

make_checkout() {
    local name=$1
    local branch=$2

    remote_script "$name" "$branch" <<'BASH'
name=$1
branch=$2
case "$name" in orb180-[a-z0-9-]*) ;; *) exit 64 ;; esac
path="/home/orbit/apps/laravel-typed/$name"
test ! -e "$path"
git clone --local --no-checkout /home/orbit/apps/laravel-typed/e2e-dev "$path" >/dev/null
git -C "$path" remote set-url origin https://github.com/laravel/laravel.git
git -C "$path" checkout -b "$branch" HEAD >/dev/null
git -C "$path" config user.name 'Orbit proof'
git -C "$path" config user.email orbit-proof@example.invalid
git -C "$path" rev-parse HEAD
BASH
}

make_worktree_graph() {
    local root_name=$1
    local target_name=$2
    local sibling_name=$3

    remote_script "$root_name" "$target_name" "$sibling_name" <<'BASH'
root_name=$1
target_name=$2
sibling_name=$3
root="/home/orbit/apps/laravel-typed/$root_name"
git clone --local --no-checkout /home/orbit/apps/laravel-typed/e2e-dev "$root" >/dev/null
git -C "$root" remote set-url origin https://github.com/laravel/laravel.git
git -C "$root" checkout -b "$root_name" HEAD >/dev/null
git -C "$root" worktree add -b "$target_name" "/home/orbit/apps/laravel-typed/$target_name" HEAD >/dev/null
git -C "$root" worktree add -b "$sibling_name" "/home/orbit/apps/laravel-typed/$sibling_name" HEAD >/dev/null
git -C "$root" rev-parse HEAD
BASH
}

seed_instance() {
    gateway_fixture seed "$1" "$2" "$3" "$4" >/dev/null
}

record_source() {
    gateway_fixture record "$1" 1
}

assert_control_state() {
    gateway_fixture state "$1" | python3 -c '
import json
import sys
value = json.load(sys.stdin)
valid = (
    value.get("instance_status") == "removing"
    and value.get("route_status") == "active"
    and isinstance(value.get("route_id"), int)
    and isinstance(value.get("route_target"), int)
)
if not valid:
    raise SystemExit(65)
'
}

remove_sources() {
    remote_script "$@" <<'BASH'
for name in "$@"; do
    case "$name" in orb180-[a-z0-9-]*) ;; *) exit 64 ;; esac
    path="/home/orbit/apps/laravel-typed/$name"
    if [ -e "$path" ] || [ -L "$path" ]; then
        rm -rf -- "$path"
    fi
    rm -rf -- "/home/orbit/${name}-preserved"
done
BASH
}

arm_finalization_race() {
    local evidence=$1
    local name=$2

    remote_script \
        "$(json_field "$evidence" checkout_path)" \
        "$(json_field "$evidence" receipt)" \
        "$name" <<'BASH'
checkout=$1
receipt=$2
branch=$3
authorized_keys=/home/orbit/.ssh/authorized_keys
backup=/home/orbit/.ssh/authorized_keys.orb180
marker=/home/orbit/.orb180-finalization-race
wrapper=/home/orbit/.orb180-finalization-wrapper
cp -- "$authorized_keys" "$backup"
printf '%s\n%s\n%s\n' "$checkout" "$receipt" "$branch" > "$marker"
cat > "$wrapper" <<'WRAPPER'
#!/usr/bin/env bash
set -euo pipefail
authorized_keys=/home/orbit/.ssh/authorized_keys
backup=/home/orbit/.ssh/authorized_keys.orb180
marker=/home/orbit/.orb180-finalization-race
wrapper=/home/orbit/.orb180-finalization-wrapper
mapfile -t fields < "$marker"
checkout=${fields[0]}
receipt=${fields[1]}
branch=${fields[2]}
signature="'$receipt' '1' 'orbit' 'orbit'"
if [[ ${SSH_ORIGINAL_COMMAND:-} == *"$signature"* ]]; then
    cp -- "$backup" "$authorized_keys"
    rm -f -- "$backup" "$marker" "$wrapper"
    git -C "$checkout" branch -m "$branch-changed"
fi
exec bash -c "$SSH_ORIGINAL_COMMAND"
WRAPPER
chmod 0700 -- "$wrapper"
sed 's|^|command="/home/orbit/.orb180-finalization-wrapper" |' "$backup" > "$authorized_keys"
BASH
}

assert_receipt_file() {
    local evidence=$1
    local receipt_path receipt
    receipt_path=$(json_field "$evidence" receipt_path)
    receipt=$(json_field "$evidence" receipt)
    remote_script "$receipt_path" "$receipt" <<'BASH'
printf '%s\n' "$2" | cmp -s - "$1"
BASH
}

case "$scenario" in
    setup-app-dev)
        test -d /home/orbit/apps/laravel-typed/e2e-dev/.git
        test -x /usr/bin/git
        orbit node:list --json | python3 -c 'import json,sys; assert isinstance(json.load(sys.stdin).get("nodes"), list)'
        ;;

    setup-gateway)
        test -f "$fixture"
        gateway_fixture setup
        ;;

    recorded-source-finalization)
        probe_surfaces

        checkout=orb180-final-checkout
        neighbor=orb180-final-neighbor
        make_checkout "$neighbor" "$neighbor" >/dev/null
        checkout_commit=$(make_checkout "$checkout" "$checkout")
        seed_instance "$checkout" checkout "$checkout" "$checkout_commit"
        checkout_evidence=$(record_source "$checkout")
        checkout_receipt=$(json_field "$checkout_evidence" receipt)
        test "$(gateway_fixture finalize "$checkout")" = "$checkout_receipt"
        remote_script "$checkout" "$neighbor" <<'BASH'
checkout=$1
neighbor=$2
test ! -e "/home/orbit/apps/laravel-typed/$checkout"
git -C "/home/orbit/apps/laravel-typed/$neighbor" status --porcelain >/dev/null
BASH
        assert_receipt_file "$checkout_evidence"
        assert_control_state "$checkout"
        remove_sources "$neighbor"

        root=orb180-final-root
        target=orb180-final-target
        sibling=orb180-final-sibling
        graph_commit=$(make_worktree_graph "$root" "$target" "$sibling")
        seed_instance "$target" worktree "$target" "$graph_commit"
        worktree_evidence=$(record_source "$target")
        common=$(json_field "$worktree_evidence" common_repository_path)
        before_refs=$(remote_script "$common" <<'BASH'
git -C "$1" show-ref | sha256sum | cut -d ' ' -f 1
git -C "$1" ls-remote origin | sha256sum | cut -d ' ' -f 1
BASH
)
        worktree_receipt=$(json_field "$worktree_evidence" receipt)
        test "$(gateway_fixture finalize "$target")" = "$worktree_receipt"
        remote_script "$common" "$target" "$sibling" "$before_refs" <<'BASH'
common=$1
target=$2
sibling=$3
before_refs=$4
test ! -e "/home/orbit/apps/laravel-typed/$target"
test -d "$common/.git"
test -d "/home/orbit/apps/laravel-typed/$sibling"
git -C "$common" status --porcelain >/dev/null
git -C "/home/orbit/apps/laravel-typed/$sibling" status --porcelain >/dev/null
git -C "$common" show-ref --verify "refs/heads/$target" >/dev/null
worktree_inventory=$(git -C "$common" worktree list --porcelain)
! grep -F "/home/orbit/apps/laravel-typed/$target" <<< "$worktree_inventory"
after_refs=$(
    git -C "$common" show-ref | sha256sum | cut -d ' ' -f 1
    git -C "$common" ls-remote origin | sha256sum | cut -d ' ' -f 1
)
test "$after_refs" = "$before_refs"
BASH
        assert_receipt_file "$worktree_evidence"
        assert_control_state "$target"
        remote_script "$common" "$sibling" <<'BASH'
git -C "$1" worktree remove --force "/home/orbit/apps/laravel-typed/$2"
rm -rf -- "$1"
BASH
        ;;

    source-finalization-recovery)
        probe_surfaces

        before=orb180-recovery-before
        before_commit=$(make_checkout "$before" "$before")
        seed_instance "$before" checkout "$before" "$before_commit"
        before_evidence=$(record_source "$before")
        before_checkout=$(json_field "$before_evidence" checkout_path)
        before_quarantine=$(json_field "$before_evidence" quarantine)
        remote_script "$before_checkout" "$before_quarantine" <<'BASH'
mv -- "$1" "$2"
BASH
        test "$(gateway_fixture revalidate "$before")" = quarantined
        test "$(gateway_fixture finalize "$before")" = "$(json_field "$before_evidence" receipt)"
        assert_receipt_file "$before_evidence"

        partial=orb180-recovery-partial
        partial_commit=$(make_checkout "$partial" "$partial")
        seed_instance "$partial" checkout "$partial" "$partial_commit"
        partial_evidence=$(record_source "$partial")
        remote_script \
            "$(json_field "$partial_evidence" checkout_path)" \
            "$(json_field "$partial_evidence" quarantine)" \
            "$(json_field "$partial_evidence" receipt_path)" \
            "$(json_field "$partial_evidence" receipt)" <<'BASH'
mv -- "$1" "$2"
printf '%s\n' "$4" > "$3"
chmod 0600 -- "$3"
rm -rf -- "$2/.git"
BASH
        test "$(gateway_fixture revalidate "$partial")" = receipt-pending-cleanup
        test "$(gateway_fixture finalize "$partial")" = "$(json_field "$partial_evidence" receipt)"
        assert_receipt_file "$partial_evidence"

        completed=orb180-recovery-completed
        completed_commit=$(make_checkout "$completed" "$completed")
        seed_instance "$completed" checkout "$completed" "$completed_commit"
        completed_evidence=$(record_source "$completed")
        completed_receipt=$(json_field "$completed_evidence" receipt)
        test "$(gateway_fixture finalize "$completed")" = "$completed_receipt"
        test "$(gateway_fixture revalidate "$completed")" = completed
        test "$(gateway_fixture finalize "$completed")" = "$completed_receipt"

        missing=orb180-recovery-missing
        missing_commit=$(make_checkout "$missing" "$missing")
        seed_instance "$missing" checkout "$missing" "$missing_commit"
        missing_evidence=$(record_source "$missing")
        remote_script "$(json_field "$missing_evidence" checkout_path)" "$missing" <<'BASH'
mv -- "$1" "/home/orbit/$2-preserved"
BASH
        gateway_fixture expect-revalidate-refusal "$missing"
        assert_control_state "$missing"
        remote_script "$missing" <<'BASH'
test -d "/home/orbit/$1-preserved/.git"
BASH
        remove_sources "$missing"

        ambiguous=orb180-recovery-ambiguous
        ambiguous_commit=$(make_checkout "$ambiguous" "$ambiguous")
        seed_instance "$ambiguous" checkout "$ambiguous" "$ambiguous_commit"
        ambiguous_evidence=$(record_source "$ambiguous")
        remote_script \
            "$(json_field "$ambiguous_evidence" checkout_path)" \
            "$(json_field "$ambiguous_evidence" quarantine)" <<'BASH'
mv -- "$1" "$2"
mkdir -- "$1"
BASH
        gateway_fixture expect-revalidate-refusal "$ambiguous"
        assert_control_state "$ambiguous"
        remote_script \
            "$(json_field "$ambiguous_evidence" checkout_path)" \
            "$(json_field "$ambiguous_evidence" quarantine)" <<'BASH'
test -d "$1"
test -d "$2/.git"
rm -rf -- "$1" "$2"
BASH

        mismatch=orb180-recovery-journal
        mismatch_commit=$(make_checkout "$mismatch" "$mismatch")
        seed_instance "$mismatch" checkout "$mismatch" "$mismatch_commit"
        mismatch_evidence=$(record_source "$mismatch")
        remote_script "$(json_field "$mismatch_evidence" journal)" <<'BASH'
printf 'mismatched\n' > "$1"
BASH
        gateway_fixture expect-revalidate-refusal "$mismatch"
        assert_control_state "$mismatch"
        remote_script "$mismatch" <<'BASH'
test -d "/home/orbit/apps/laravel-typed/$1/.git"
BASH
        remove_sources "$mismatch"

        wrong_receipt=orb180-recovery-receipt
        wrong_receipt_commit=$(make_checkout "$wrong_receipt" "$wrong_receipt")
        seed_instance "$wrong_receipt" checkout "$wrong_receipt" "$wrong_receipt_commit"
        wrong_receipt_evidence=$(record_source "$wrong_receipt")
        remote_script \
            "$(json_field "$wrong_receipt_evidence" checkout_path)" \
            "$(json_field "$wrong_receipt_evidence" quarantine)" \
            "$(json_field "$wrong_receipt_evidence" receipt_path)" \
            "$wrong_receipt" <<'BASH'
mv -- "$1" "$2"
printf 'mismatched\n' > "$3"
chmod 0600 -- "$3"
printf 'preserve\n' > "/home/orbit/$4-preserved"
BASH
        gateway_fixture expect-revalidate-refusal "$wrong_receipt"
        assert_control_state "$wrong_receipt"
        remote_script \
            "$(json_field "$wrong_receipt_evidence" quarantine)" \
            "$wrong_receipt" <<'BASH'
test -d "$1/.git"
printf 'preserve\n' | cmp -s - "/home/orbit/$2-preserved"
rm -rf -- "$1" "/home/orbit/$2-preserved"
BASH

        root=orb180-recovery-root
        target=orb180-recovery-target
        sibling=orb180-recovery-sibling
        graph_commit=$(make_worktree_graph "$root" "$target" "$sibling")
        seed_instance "$target" worktree "$target" "$graph_commit"
        worktree_evidence=$(record_source "$target")
        remote_script \
            "$(json_field "$worktree_evidence" checkout_path)" \
            "$(json_field "$worktree_evidence" quarantine)" \
            "$(json_field "$worktree_evidence" common_repository_path)" \
            "$(json_field "$worktree_evidence" recovery)" \
            "$(json_field "$worktree_evidence" receipt_path)" \
            "$(json_field "$worktree_evidence" receipt)" <<'BASH'
checkout=$1
quarantine=$2
common=$3
recovery=$4
receipt_path=$5
receipt=$6
git --git-dir="$common/.git" worktree move "$checkout" "$quarantine"
admin=$(git -C "$quarantine" rev-parse --absolute-git-dir)
printf '%s\n%s\n%s\n%s\n' \
    "$(printf '%s' "$admin" | base64 --wrap=0)" \
    "$(stat -c '%d:%i' "$admin")" \
    "$(stat -c '%d:%i' "$common/.git")" \
    "$(stat -c '%d:%i' "$(dirname "$admin")")" > "$recovery"
chmod 0600 -- "$recovery"
printf '%s\n' "$receipt" > "$receipt_path"
chmod 0600 -- "$receipt_path"
rm -rf -- "$quarantine"
test -d "$admin"
BASH
        test "$(gateway_fixture revalidate "$target")" = receipt-pending-cleanup
        test "$(gateway_fixture finalize "$target")" = "$(json_field "$worktree_evidence" receipt)"
        remote_script "$(json_field "$worktree_evidence" common_repository_path)" "$target" "$sibling" <<'BASH'
common=$1
target=$2
sibling=$3
test ! -e "/home/orbit/apps/laravel-typed/$target"
git -C "$common" show-ref --verify "refs/heads/$target" >/dev/null
git -C "/home/orbit/apps/laravel-typed/$sibling" status --porcelain >/dev/null
worktree_inventory=$(git -C "$common" worktree list --porcelain)
! grep -F "/home/orbit/apps/laravel-typed/$target" <<< "$worktree_inventory"
BASH
        remote_script "$(json_field "$worktree_evidence" common_repository_path)" "$sibling" <<'BASH'
git -C "$1" worktree remove --force "/home/orbit/apps/laravel-typed/$2"
rm -rf -- "$1"
BASH
        ;;

    source-finalization-revalidation)
        probe_surfaces

        origin=orb180-drift-origin
        origin_commit=$(make_checkout "$origin" "$origin")
        seed_instance "$origin" checkout "$origin" "$origin_commit"
        record_source "$origin" >/dev/null
        remote_script "$origin" <<'BASH'
git -C "/home/orbit/apps/laravel-typed/$1" remote set-url origin https://github.com/laravel/framework.git
BASH
        gateway_fixture expect-revalidate-refusal "$origin"
        assert_control_state "$origin"
        remote_script "$origin" <<'BASH'
test -d "/home/orbit/apps/laravel-typed/$1/.git"
BASH
        remove_sources "$origin"

        replacement=orb180-drift-replacement
        replacement_commit=$(make_checkout "$replacement" "$replacement")
        seed_instance "$replacement" checkout "$replacement" "$replacement_commit"
        record_source "$replacement" >/dev/null
        remote_script "$replacement" <<'BASH'
name=$1
path="/home/orbit/apps/laravel-typed/$name"
mv -- "$path" "/home/orbit/$name-preserved"
git clone --local --no-checkout /home/orbit/apps/laravel-typed/e2e-dev "$path" >/dev/null
git -C "$path" remote set-url origin https://github.com/laravel/laravel.git
git -C "$path" checkout -b "$name" HEAD >/dev/null
BASH
        gateway_fixture expect-revalidate-refusal "$replacement"
        assert_control_state "$replacement"
        remote_script "$replacement" <<'BASH'
test -d "/home/orbit/apps/laravel-typed/$1/.git"
test -d "/home/orbit/$1-preserved/.git"
BASH
        remove_sources "$replacement"

        canonical=orb180-drift-canonical
        canonical_commit=$(make_checkout "$canonical" "$canonical")
        seed_instance "$canonical" checkout "$canonical" "$canonical_commit"
        record_source "$canonical" >/dev/null
        gateway_fixture mutate-app-repository \
            "$canonical" \
            https://github.com/laravel/framework.git \
            github.com/laravel/framework
        gateway_fixture expect-revalidate-refusal "$canonical"
        assert_control_state "$canonical"
        remote_script "$canonical" <<'BASH'
test -d "/home/orbit/apps/laravel-typed/$1/.git"
BASH
        gateway_fixture mutate-app-repository \
            "$canonical" \
            https://github.com/laravel/laravel.git \
            github.com/laravel/laravel
        remove_sources "$canonical"

        recorded_layout=orb180-drift-recorded-layout
        recorded_layout_commit=$(make_checkout "$recorded_layout" "$recorded_layout")
        seed_instance "$recorded_layout" checkout "$recorded_layout" "$recorded_layout_commit"
        record_source "$recorded_layout" >/dev/null
        gateway_fixture mutate-layout "$recorded_layout" worktree
        gateway_fixture expect-revalidate-refusal "$recorded_layout"
        assert_control_state "$recorded_layout"
        remote_script "$recorded_layout" <<'BASH'
test -d "/home/orbit/apps/laravel-typed/$1/.git"
BASH
        remove_sources "$recorded_layout"

        physical_layout=orb180-drift-physical-layout
        physical_layout_commit=$(make_checkout "$physical_layout" "$physical_layout")
        seed_instance "$physical_layout" checkout "$physical_layout" "$physical_layout_commit"
        record_source "$physical_layout" >/dev/null
        remote_script "$physical_layout" <<'BASH'
name=$1
path="/home/orbit/apps/laravel-typed/$name"
git_directory="/home/orbit/$name-physical-git"
mv -- "$path/.git" "$git_directory"
printf 'gitdir: %s\n' "$git_directory" > "$path/.git"
BASH
        gateway_fixture expect-revalidate-refusal "$physical_layout"
        assert_control_state "$physical_layout"
        remote_script "$physical_layout" <<'BASH'
test -f "/home/orbit/apps/laravel-typed/$1/.git"
test -d "/home/orbit/$1-physical-git"
BASH
        remove_sources "$physical_layout"
        remote_script "$physical_layout" <<'BASH'
rm -rf -- "/home/orbit/$1-physical-git"
BASH

        ancestry=orb180-drift-ancestry
        ancestry_commit=$(make_checkout "$ancestry" "$ancestry")
        seed_instance "$ancestry" checkout "$ancestry" "$ancestry_commit"
        record_source "$ancestry" >/dev/null
        remote_script "$ancestry" <<'BASH'
path="/home/orbit/apps/laravel-typed/$1"
tree=$(git -C "$path" rev-parse HEAD^{tree})
unrelated=$(printf 'unrelated history\n' | git -C "$path" commit-tree "$tree")
git -C "$path" reset --hard "$unrelated" >/dev/null
test "$(git -C "$path" symbolic-ref --short HEAD)" = "$1"
BASH
        gateway_fixture expect-revalidate-refusal "$ancestry"
        assert_control_state "$ancestry"
        remote_script "$ancestry" <<'BASH'
test -d "/home/orbit/apps/laravel-typed/$1/.git"
BASH
        remove_sources "$ancestry"

        inventory=orb180-drift-inventory
        inventory_sibling=orb180-drift-inventory-late
        inventory_commit=$(make_checkout "$inventory" "$inventory")
        seed_instance "$inventory" checkout "$inventory" "$inventory_commit"
        record_source "$inventory" >/dev/null
        remote_script "$inventory" "$inventory_sibling" <<'BASH'
root="/home/orbit/apps/laravel-typed/$1"
sibling="/home/orbit/apps/laravel-typed/$2"
git -C "$root" worktree add -b "$2" "$sibling" HEAD >/dev/null
BASH
        gateway_fixture expect-revalidate-refusal "$inventory"
        assert_control_state "$inventory"
        remote_script "$inventory" "$inventory_sibling" <<'BASH'
root="/home/orbit/apps/laravel-typed/$1"
sibling="/home/orbit/apps/laravel-typed/$2"
git -C "$root" status --porcelain >/dev/null
git -C "$sibling" status --porcelain >/dev/null
git -C "$root" worktree remove --force "$sibling"
rm -rf -- "$root"
BASH

        race=orb180-drift-race
        race_commit=$(make_checkout "$race" "$race")
        seed_instance "$race" checkout "$race" "$race_commit"
        race_evidence=$(record_source "$race")
        arm_finalization_race "$race_evidence" "$race"
        gateway_fixture expect-finalize-incomplete "$race"
        assert_control_state "$race"
        remote_script "$race" <<'BASH'
test -d "/home/orbit/apps/laravel-typed/$1/.git"
test "$(git -C "/home/orbit/apps/laravel-typed/$1" symbolic-ref --short HEAD)" = "$1-changed"
test ! -e /home/orbit/.ssh/authorized_keys.orb180
test ! -e /home/orbit/.orb180-finalization-race
test ! -e /home/orbit/.orb180-finalization-wrapper
BASH
        remove_sources "$race"

        owner=orb180-drift-owner
        owner_commit=$(make_checkout "$owner" "$owner")
        seed_instance "$owner" checkout "$owner" "$owner_commit"
        record_source "$owner" >/dev/null
        remote_script "$owner" <<'BASH'
sudo chown root:root "/home/orbit/apps/laravel-typed/$1"
BASH
        gateway_fixture expect-revalidate-refusal "$owner"
        assert_control_state "$owner"
        remote_script "$owner" <<'BASH'
sudo chown orbit:orbit "/home/orbit/apps/laravel-typed/$1"
BASH
        remove_sources "$owner"

        quarantine=orb180-drift-quarantine
        quarantine_commit=$(make_checkout "$quarantine" "$quarantine")
        seed_instance "$quarantine" checkout "$quarantine" "$quarantine_commit"
        quarantine_evidence=$(record_source "$quarantine")
        remote_script \
            "$(json_field "$quarantine_evidence" checkout_path)" \
            "$(json_field "$quarantine_evidence" quarantine)" <<'BASH'
mv -- "$1" "$2"
git -C "$2" branch -m orb180-drift-quarantine-changed
BASH
        gateway_fixture expect-revalidate-refusal "$quarantine"
        assert_control_state "$quarantine"
        remote_script "$(json_field "$quarantine_evidence" quarantine)" <<'BASH'
test -d "$1/.git"
BASH
        remove_sources "$quarantine"
        remote_script "$(json_field "$quarantine_evidence" quarantine)" <<'BASH'
rm -rf -- "$1"
BASH

        linked=orb180-linked-root
        linked_sibling=orb180-linked-sibling
        linked_commit=$(make_worktree_graph "$linked" "$linked_sibling" orb180-linked-other)
        seed_instance "$linked" checkout "$linked" "$linked_commit"
        gateway_fixture expect-record-refusal "$linked"
        remote_script "$linked" "$linked_sibling" <<'BASH'
git -C "/home/orbit/apps/laravel-typed/$1" status --porcelain >/dev/null
git -C "/home/orbit/apps/laravel-typed/$2" status --porcelain >/dev/null
BASH
        remote_script "$linked" "$linked_sibling" <<'BASH'
root="/home/orbit/apps/laravel-typed/$1"
git -C "$root" worktree remove --force "/home/orbit/apps/laravel-typed/$2"
git -C "$root" worktree remove --force /home/orbit/apps/laravel-typed/orb180-linked-other
rm -rf -- "$root"
BASH
        ;;

    *)
        printf 'Unknown ORB-180 proof scenario: %s\n' "$scenario" >&2
        exit 64
        ;;
esac

printf 'ORB-180 %s: ok\n' "$scenario"

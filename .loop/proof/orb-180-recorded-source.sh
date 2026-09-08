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
    remote_command bash -seu -- "$@"
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

cleanup_case() {
    gateway_fixture cleanup "$@" || true
    remove_sources "$@"
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
        checkout_commit=$(make_checkout "$checkout" "$checkout")
        seed_instance "$checkout" checkout "$checkout" "$checkout_commit"
        checkout_evidence=$(record_source "$checkout")
        checkout_receipt=$(json_field "$checkout_evidence" receipt)
        test "$(gateway_fixture finalize "$checkout")" = "$checkout_receipt"
        remote_script "$checkout" <<'BASH'
test ! -e "/home/orbit/apps/laravel-typed/$1"
BASH
        assert_receipt_file "$checkout_evidence"
        assert_control_state "$checkout"
        gateway_fixture cleanup "$checkout"

        root=orb180-final-root
        target=orb180-final-target
        sibling=orb180-final-sibling
        graph_commit=$(make_worktree_graph "$root" "$target" "$sibling")
        seed_instance "$target" worktree "$target" "$graph_commit"
        worktree_evidence=$(record_source "$target")
        common=$(json_field "$worktree_evidence" common_repository_path)
        before_refs=$(remote_script "$common" <<'BASH'
{ git -C "$1" show-ref; git -C "$1" ls-remote origin; } | sha256sum | cut -d ' ' -f 1
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
! git -C "$common" worktree list --porcelain | grep -F "/home/orbit/apps/laravel-typed/$target"
after_refs=$( { git -C "$common" show-ref; git -C "$common" ls-remote origin; } | sha256sum | cut -d ' ' -f 1 )
test "$after_refs" = "$before_refs"
BASH
        assert_receipt_file "$worktree_evidence"
        assert_control_state "$target"
        gateway_fixture cleanup "$target"
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
        gateway_fixture cleanup "$before"

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
        gateway_fixture cleanup "$partial"

        completed=orb180-recovery-completed
        completed_commit=$(make_checkout "$completed" "$completed")
        seed_instance "$completed" checkout "$completed" "$completed_commit"
        completed_evidence=$(record_source "$completed")
        completed_receipt=$(json_field "$completed_evidence" receipt)
        test "$(gateway_fixture finalize "$completed")" = "$completed_receipt"
        test "$(gateway_fixture revalidate "$completed")" = completed
        test "$(gateway_fixture finalize "$completed")" = "$completed_receipt"
        gateway_fixture cleanup "$completed"

        missing=orb180-recovery-missing
        missing_commit=$(make_checkout "$missing" "$missing")
        seed_instance "$missing" checkout "$missing" "$missing_commit"
        missing_evidence=$(record_source "$missing")
        remote_script "$(json_field "$missing_evidence" checkout_path)" "$missing" <<'BASH'
mv -- "$1" "/home/orbit/$2-preserved"
BASH
        gateway_fixture expect-revalidate-refusal "$missing"
        remote_script "$missing" <<'BASH'
test -d "/home/orbit/$1-preserved/.git"
BASH
        cleanup_case "$missing"

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
        remote_script \
            "$(json_field "$ambiguous_evidence" checkout_path)" \
            "$(json_field "$ambiguous_evidence" quarantine)" <<'BASH'
test -d "$1"
test -d "$2/.git"
rm -rf -- "$1" "$2"
BASH
        gateway_fixture cleanup "$ambiguous"

        mismatch=orb180-recovery-journal
        mismatch_commit=$(make_checkout "$mismatch" "$mismatch")
        seed_instance "$mismatch" checkout "$mismatch" "$mismatch_commit"
        mismatch_evidence=$(record_source "$mismatch")
        remote_script "$(json_field "$mismatch_evidence" journal)" <<'BASH'
printf 'mismatched\n' > "$1"
BASH
        gateway_fixture expect-revalidate-refusal "$mismatch"
        cleanup_case "$mismatch"

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
! git -C "$common" worktree list --porcelain | grep -F "/home/orbit/apps/laravel-typed/$target"
BASH
        gateway_fixture cleanup "$target"
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
        remote_script "$origin" <<'BASH'
test -d "/home/orbit/apps/laravel-typed/$1/.git"
BASH
        cleanup_case "$origin"

        race=orb180-drift-race
        race_commit=$(make_checkout "$race" "$race")
        seed_instance "$race" checkout "$race" "$race_commit"
        record_source "$race" >/dev/null
        remote_script "$race" <<'BASH'
git -C "/home/orbit/apps/laravel-typed/$1" branch -m "$1-changed"
BASH
        gateway_fixture expect-finalize-refusal "$race"
        remote_script "$race" <<'BASH'
test -d "/home/orbit/apps/laravel-typed/$1/.git"
BASH
        cleanup_case "$race"

        owner=orb180-drift-owner
        owner_commit=$(make_checkout "$owner" "$owner")
        seed_instance "$owner" checkout "$owner" "$owner_commit"
        record_source "$owner" >/dev/null
        remote_script "$owner" <<'BASH'
sudo chown root:root "/home/orbit/apps/laravel-typed/$1"
BASH
        gateway_fixture expect-revalidate-refusal "$owner"
        remote_script "$owner" <<'BASH'
sudo chown orbit:orbit "/home/orbit/apps/laravel-typed/$1"
BASH
        cleanup_case "$owner"

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
        remote_script "$(json_field "$quarantine_evidence" quarantine)" <<'BASH'
test -d "$1/.git"
BASH
        gateway_fixture cleanup "$quarantine"
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
        gateway_fixture cleanup "$linked"
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

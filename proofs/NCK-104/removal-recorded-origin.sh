#!/usr/bin/env bash
# Removal uses recorded path and origin; unsafe origin, grouping, and ownership fail closed.
source /var/lib/orbit-e2e/proof/lib.sh

orbit workspace:remove "$(workspace_id nck104-shared)" --json >/dev/null
test ! -e /srv/orbit/worktrees/laravel/nck104-shared
test ! -e /srv/orbit/worktrees/laravel
test -d /srv/orbit/worktrees

orbit workspace:remove "$(workspace_id nck104-explicit)" --json >/dev/null
test ! -e /home/orbit/custom-worktrees/nck104-explicit
test -d /home/orbit/custom-worktrees

dev_id=$(instance_id e2e-dev)
orbit workspace:remove "$(workspace_id nck104-legacy)" --json >/dev/null
test ! -e /home/orbit/custom-worktrees/nck104-legacy
test -d /home/orbit/custom-worktrees

expect_error workspace.checkout_path_unsafe orbit workspace:remove "$(workspace_id nck104-unsafe)" --json
test -d /home/orbit/custom-worktrees/nck104-unsafe

owned_instance=/srv/orbit/instances/nck104
sudo chown root:root -- "$owned_instance"
expect_error instance.remove_failed orbit instance:remove "$(instance_id nck104-dev)" --json
test -d "$owned_instance"
sudo chown orbit:orbit -- "$owned_instance"
orbit instance:remove "$(instance_id nck104-dev)" --json >/dev/null
test ! -e /srv/orbit/instances/nck104
test -d /srv/orbit/instances
if getfacl -cp /srv/orbit/instances | grep -Eq '^user:caddy:'; then
  fail "instance root kept a Caddy traversal grant after the last checkout"
fi

orbit workspace:new "$dev_id" nck104-grouping --json >/dev/null
grouping=/srv/orbit/worktrees/laravel
printf 'unexpected\n' > "$grouping/UNEXPECTED"
expect_error workspace.remove_failed orbit workspace:remove "$(workspace_id nck104-grouping)" --json
test -d "$grouping/nck104-grouping"
test -f "$grouping/UNEXPECTED"
rm -f -- "$grouping/UNEXPECTED"
orbit workspace:remove "$(workspace_id nck104-grouping)" --json >/dev/null
test ! -e "$grouping/nck104-grouping"

orbit workspace:new "$dev_id" nck104-owned --json >/dev/null
owned=/srv/orbit/worktrees/laravel/nck104-owned
sudo chown root:root -- "$owned"
expect_error workspace.remove_failed orbit workspace:remove "$(workspace_id nck104-owned)" --json
test -d "$owned"
sudo chown orbit:orbit -- "$owned"
orbit workspace:remove "$(workspace_id nck104-owned)" --json >/dev/null

orbit workspace:new "$dev_id" nck104-identity --json >/dev/null
checkout=/srv/orbit/worktrees/laravel/nck104-identity
git -C /home/orbit/apps/laravel worktree remove --force -- "$checkout"
mkdir -p -- "$checkout"
printf 'decoy\n' > "$checkout/KEEP"
expect_error workspace.remove_failed orbit workspace:remove "$(workspace_id nck104-identity)" --json
test -f "$checkout/KEEP"

rm -rf -- "$checkout"
ln -s /missing-orbit-checkout "$checkout"
expect_error workspace.remove_failed orbit workspace:remove "$(workspace_id nck104-identity)" --json
test -L "$checkout"

orbit workspace:new "$dev_id" nck104-registered-decoy --json >/dev/null
decoy=$(workspace_checkout nck104-registered-decoy)
git -C /home/orbit/apps/laravel worktree list --porcelain | grep -Fx -- "worktree $decoy" \
  || fail "registered decoy checkout is not in the instance worktree list"
rm -rf -- "$decoy"
mkdir -p -- "$decoy"
git -C "$decoy" init >/dev/null
git -C "$decoy" remote add origin git@github.com:foreign/decoy.git
printf 'decoy\n' > "$decoy/KEEP"
git -C /home/orbit/apps/laravel worktree list --porcelain | grep -Fx -- "worktree $decoy" \
  || fail "replaced decoy dropped off the instance worktree list"
expect_error workspace.remove_failed orbit workspace:remove "$(workspace_id nck104-registered-decoy)" --json
test -f "$decoy/KEEP" || fail "still-registered replaced checkout was deleted"

orbit workspace:new "$dev_id" nck104-branch-drift --json >/dev/null
drift=$(workspace_checkout nck104-branch-drift)
git -C /home/orbit/apps/laravel worktree list --porcelain | grep -Fx -- "worktree $drift" \
  || fail "branch-drift checkout is not in the instance worktree list"
git -C "$drift" checkout -b drifted >/dev/null
expect_error workspace.remove_failed orbit workspace:remove "$(workspace_id nck104-branch-drift)" --json
test -d "$drift" || fail "branch-drifted checkout was deleted"
[[ "$(git -C "$drift" symbolic-ref --quiet --short HEAD)" == drifted ]] \
  || fail "branch-drifted checkout HEAD changed"

sql_set_workspace_origin nck104-unsafe explicit
orbit workspace:remove "$(workspace_id nck104-unsafe)" --json >/dev/null

instance_checkout_path=$(instance_checkout e2e-dev)
identity=/srv/orbit/worktrees/laravel/nck104-identity
rm -f -- "$identity"
git -C "$instance_checkout_path" worktree prune
git -C "$instance_checkout_path" worktree add -- "$identity" nck104-identity >/dev/null
orbit workspace:remove "$(workspace_id nck104-identity)" --json >/dev/null

rm -rf -- "$decoy"
git -C "$instance_checkout_path" worktree prune --expire=now
git -C "$instance_checkout_path" worktree add -- "$decoy" nck104-registered-decoy >/dev/null
orbit workspace:remove "$(workspace_id nck104-registered-decoy)" --json >/dev/null

git -C "$drift" checkout nck104-branch-drift >/dev/null
orbit workspace:remove "$(workspace_id nck104-branch-drift)" --json >/dev/null

orbit instance:remove "$dev_id" --json >/dev/null
test ! -e /home/orbit/apps/laravel

orbit node:settings app-dev --setting=instance.path: --setting=worktree.path: --json >/dev/null
original_home_acl=$(sudo getfacl -cp /home)
restore_home_acl() {
  printf '%s\n' "$original_home_acl" | sudo setfacl --set-file=- /home
}
trap restore_home_acl EXIT
sudo setfacl -m u:caddy:--- /home
nontraversable_home_acl=$(sudo getfacl -cp /home)
sudo getfacl -cp /home | grep -Fqx 'user:caddy:---' \
  || fail "managed-home ancestor remained traversable before the fresh checkout"

home_app=$(orbit app:new nck104-home-ancestor https://github.com/laravel/laravel.git --json)
home_app_id=$(echo "$home_app" | json_get id)
home_instance=$(orbit instance:new "$home_app_id" "$(node_id app-dev)" nck104-home-ancestor --json)
[[ "$(echo "$home_instance" | json_get checkout_path)" == /home/orbit/apps/nck104-home-ancestor ]] \
  || fail "fresh managed-home instance used the wrong checkout: $home_instance"
sudo getfacl -cp /home | grep -Fqx 'user:caddy:--x' \
  || fail "fresh managed-home checkout did not grant Caddy traversal on /home"

orbit instance:remove "$(instance_id nck104-home-ancestor)" --json >/dev/null
test ! -e /home/orbit/apps/nck104-home-ancestor
[[ "$(sudo getfacl -cp /home)" == "$nontraversable_home_acl" ]] \
  || fail "last managed-home dependent did not restore the non-traversable /home ACL"
restore_home_acl
trap - EXIT
[[ "$(sudo getfacl -cp /home)" == "$original_home_acl" ]] \
  || fail "proof did not restore the original /home ACL"

recreated_instance=$(orbit instance:new "$(app_id laravel)" "$(node_id app-dev)" e2e-dev \
  --environment=development --json)
recreated_instance_id=$(echo "$recreated_instance" | json_get id)
[[ "$(echo "$recreated_instance" | json_get checkout_path)" == /home/orbit/apps/laravel ]] \
  || fail "recreated e2e-dev used the wrong checkout: $recreated_instance"
orbit workspace:new "$recreated_instance_id" e2e --branch=e2e --json >/dev/null
sql_clear_workspace_origin e2e
[[ "$(sql_workspace_origin e2e)" == null ]] || fail "recreated e2e workspace is not a legacy row"

echo "removal: fail-closed fixtures survived; exact checkout removal restored the managed-home ancestor ACL"

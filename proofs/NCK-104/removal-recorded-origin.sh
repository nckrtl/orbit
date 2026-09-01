#!/usr/bin/env bash
# Removal uses recorded path and origin; unsafe origin, grouping, and ownership fail closed.
proof_root=${ORBIT_E2E_PROOF_ROOT:-/var/lib/orbit-e2e/proof}
source "$proof_root/lib.sh"

orb7_traps removal-recorded-origin gateway
orb7_arm_paths removal-recorded-origin /srv/orbit/worktrees/laravel/nck104-shared /home/orbit/apps/laravel/.git/worktrees
orb7_arm_remote_database removal-recorded-origin
orb7_checkpoint removal-recorded-origin post-record
orbit workspace:remove "$(workspace_id nck104-shared)" --json >/dev/null
orb7_mark_active removal-recorded-origin gateway
orb7_checkpoint removal-recorded-origin post-mutation
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
if grep -Eq '^user:caddy:' <<<"$(getfacl -cp /srv/orbit/instances)"; then
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

orb7_complete removal-recorded-origin gateway
echo "removal: unsafe origin, grouping, ownership, checkout identity, and branch drift fail closed"

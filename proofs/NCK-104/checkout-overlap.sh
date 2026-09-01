#!/usr/bin/env bash
# Checkout overlap uses directory boundaries, not string prefixes.
proof_root=${ORBIT_E2E_PROOF_ROOT:-/var/lib/orbit-e2e/proof}
source "$proof_root/lib.sh"

dev_id=$(instance_id e2e-dev)

expect_error workspace.path_taken orbit workspace:new "$dev_id" nck104-overlap \
  --path=/home/orbit/.orbit/worktrees/laravel/e2e --json
expect_error workspace.path_taken orbit workspace:new "$dev_id" nck104-parent-overlap \
  --path=/home/orbit/.orbit/worktrees/laravel --json
expect_error workspace.path_taken orbit workspace:new "$dev_id" nck104-child-overlap \
  --path=/home/orbit/.orbit/worktrees/laravel/e2e/child --json
expect_error workspace.path_taken orbit workspace:new "$dev_id" nck104-instance-child \
  --path=/home/orbit/apps/laravel/nested --json

orb7_traps checkout-overlap gateway
orb7_arm_paths checkout-overlap /home/orbit/.orbit/worktrees/laravel/e2e-extra /home/orbit/apps/laravel/.git/worktrees
orb7_arm_remote_database checkout-overlap
orb7_checkpoint checkout-overlap post-record
lookalike=$(orbit workspace:new "$dev_id" nck104-lookalike \
  --path=/home/orbit/.orbit/worktrees/laravel/e2e-extra --json)
orb7_mark_active checkout-overlap gateway
orb7_checkpoint checkout-overlap post-mutation
[[ "$(echo "$lookalike" | json_get checkout_path)" == /home/orbit/.orbit/worktrees/laravel/e2e-extra ]] \
  || fail "lookalike prefix was treated as overlap: $lookalike"

orbit workspace:remove "$(workspace_id nck104-lookalike)" --json >/dev/null
test ! -e /home/orbit/.orbit/worktrees/laravel/e2e-extra

orb7_complete checkout-overlap gateway
echo "overlap: equal, parent, child, and child-of-instance paths rejected; e2e-extra prefix allowed"

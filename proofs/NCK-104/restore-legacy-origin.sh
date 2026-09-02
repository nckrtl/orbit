#!/usr/bin/env bash
# Restore the recreated fixture to the legacy null-origin state on the Gateway.
proof_root=${ORBIT_E2E_PROOF_ROOT:-/var/lib/orbit-e2e/proof}
source "$proof_root/lib.sh"

orb7_traps restore-legacy-origin app-dev
orb7_arm_database restore-legacy-origin
orb7_arm_remote_paths app-dev restore-legacy-origin /home/orbit/.orbit/worktrees/laravel/e2e /home/orbit/apps/laravel/.git/worktrees
orb7_checkpoint restore-legacy-origin post-record
sql_clear_workspace_origin e2e
orb7_mark_active restore-legacy-origin app-dev
orb7_checkpoint restore-legacy-origin post-mutation
[[ "$(sql_workspace_origin e2e)" == null ]] || fail "recreated e2e workspace is not a legacy row"

orb7_remote_state app-dev restore caddy-acl-sharing
bash "$ORB7_STATE_HELPER" restore nck104-original-database
orb7_remote_state app-dev restore nck104-original-paths
orb7_complete restore-legacy-origin app-dev
echo "removal: recreated e2e workspace restored as a legacy row"

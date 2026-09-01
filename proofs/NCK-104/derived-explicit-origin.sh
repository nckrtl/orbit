#!/usr/bin/env bash
# New workspaces record derived or explicit origin; legacy rows stay SQL null.
source /var/lib/orbit-e2e/proof/lib.sh

[[ "$(sql_workspace_origin e2e)" == null ]] || fail "legacy e2e origin was rewritten"

dev_id=$(instance_id e2e-dev)
[[ -n "$dev_id" ]] || fail "missing e2e-dev instance"

orb7_arm_database derived-explicit-origin
orb7_arm_remote_paths app-dev derived-explicit-origin \
  /srv/orbit/worktrees /home/orbit/custom-worktrees /home/orbit/apps/laravel/.git/worktrees
orb7_traps derived-explicit-origin app-dev
derived=$(orbit workspace:new "$dev_id" nck104-derived --json)
orb7_mark_active derived-explicit-origin app-dev
orb7_checkpoint derived-explicit-origin
[[ "$(echo "$derived" | json_get checkout_path)" == /srv/orbit/worktrees/laravel/nck104-derived ]] \
  || fail "derived checkout was not under the configured worktree root: $derived"
[[ "$(sql_workspace_origin nck104-derived)" == derived ]] || fail "derived origin was not stored"

explicit=$(orbit workspace:new "$dev_id" nck104-explicit --path=/home/orbit/custom-worktrees/nck104-explicit --json)
[[ "$(echo "$explicit" | json_get checkout_path)" == /home/orbit/custom-worktrees/nck104-explicit ]] \
  || fail "explicit checkout path was not recorded: $explicit"
[[ "$(sql_workspace_origin nck104-explicit)" == explicit ]] || fail "explicit origin was not stored"

legacy=$(orbit workspace:new "$dev_id" nck104-legacy --path=/home/orbit/custom-worktrees/nck104-legacy --json)
[[ "$(echo "$legacy" | json_get checkout_path)" == /home/orbit/custom-worktrees/nck104-legacy ]] \
  || fail "legacy fixture workspace was not created: $legacy"
sql_clear_workspace_origin nck104-legacy
[[ "$(sql_workspace_origin nck104-legacy)" == null ]] || fail "legacy origin was not cleared"

unsafe=$(orbit workspace:new "$dev_id" nck104-unsafe --path=/home/orbit/custom-worktrees/nck104-unsafe --json)
[[ "$(echo "$unsafe" | json_get checkout_path)" == /home/orbit/custom-worktrees/nck104-unsafe ]] \
  || fail "unsafe-origin fixture workspace was not created: $unsafe"
sql_set_workspace_origin nck104-unsafe bogus
[[ "$(sql_workspace_origin nck104-unsafe)" == bogus ]] || fail "unsafe origin was not stored"

orb7_complete derived-explicit-origin app-dev
echo "origin: derived, explicit, and legacy null"

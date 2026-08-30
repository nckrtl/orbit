#!/usr/bin/env bash
# New workspaces record derived or explicit origin; legacy rows stay SQL null.
source /var/lib/orbit-e2e/proof/lib.sh

[[ "$(sql_workspace_origin e2e)" == null ]] || fail "legacy e2e origin was rewritten"

dev_id=$(instance_id e2e-dev)
[[ -n "$dev_id" ]] || fail "missing e2e-dev instance"

derived=$(orbit workspace:new "$dev_id" nck104-derived --json)
[[ "$(echo "$derived" | json_get checkout_path)" == /srv/orbit/worktrees/laravel/nck104-derived ]] \
  || fail "derived checkout was not under the configured worktree root: $derived"
[[ "$(sql_workspace_origin nck104-derived)" == derived ]] || fail "derived origin was not stored"

explicit=$(orbit workspace:new "$dev_id" nck104-explicit --path=/home/orbit/custom-worktrees/nck104-explicit --json)
[[ "$(echo "$explicit" | json_get checkout_path)" == /home/orbit/custom-worktrees/nck104-explicit ]] \
  || fail "explicit checkout path was not recorded: $explicit"
[[ "$(sql_workspace_origin nck104-explicit)" == explicit ]] || fail "explicit origin was not stored"

echo "origin: derived, explicit, and legacy null"

#!/usr/bin/env bash
# Removal uses recorded path and origin; derived grouping dirs go away; explicit parents stay; identity fails closed.
source /var/lib/orbit-e2e/proof/lib.sh

orbit workspace:remove "$(workspace_id nck104-shared)" --json >/dev/null
test ! -e /srv/orbit/worktrees/laravel/nck104-shared
test ! -e /srv/orbit/worktrees/laravel
test -d /srv/orbit/worktrees

orbit workspace:remove "$(workspace_id nck104-explicit)" --json >/dev/null
test ! -e /home/orbit/custom-worktrees/nck104-explicit
test -d /home/orbit/custom-worktrees

dev_id=$(instance_id e2e-dev)
orbit workspace:new "$dev_id" nck104-identity --json >/dev/null
checkout=/srv/orbit/worktrees/laravel/nck104-identity
git -C /home/orbit/apps/laravel worktree remove --force -- "$checkout"
mkdir -p -- "$checkout"
printf 'decoy\n' > "$checkout/KEEP"

expect_error workspace.remove_failed orbit workspace:remove "$(workspace_id nck104-identity)" --json
test -f "$checkout/KEEP"

echo "removal: derived grouping gone, explicit parent kept, broken identity left in place"

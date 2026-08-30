#!/usr/bin/env bash
# Removal uses recorded path and origin; legacy and instance removals stay fail-closed.
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

orbit instance:remove "$(instance_id nck104-dev)" --json >/dev/null
test ! -e /srv/orbit/instances/nck104
test -d /srv/orbit/instances
if getfacl -cp /srv/orbit/instances | grep -Eq '^user:caddy:'; then
  fail "instance root kept a Caddy traversal grant after the last checkout"
fi

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

echo "removal: derived grouping gone, explicit parent kept, legacy and instance removed, identity fails closed"

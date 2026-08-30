#!/usr/bin/env bash
# Caddy traverse ACLs are shared across dependent sites and released after the last one.
source /var/lib/orbit-e2e/proof/lib.sh

dev_id=$(instance_id e2e-dev)
shared=$(orbit workspace:new "$dev_id" nck104-shared --json)
[[ "$(echo "$shared" | json_get checkout_path)" == /srv/orbit/worktrees/laravel/nck104-shared ]] \
  || fail "shared workspace was not derived: $shared"

getfacl -cp /srv/orbit/worktrees/laravel | grep -Eq '^user:caddy:--x$' \
  || fail "shared grouping dir missing Caddy traverse ACL"
getfacl -cp /srv/orbit/worktrees | grep -Eq '^user:caddy:--x$' \
  || fail "worktree root missing Caddy traverse ACL"

orbit workspace:remove "$(workspace_id nck104-derived)" --json >/dev/null
test ! -e /srv/orbit/worktrees/laravel/nck104-derived
test -d /srv/orbit/worktrees/laravel
getfacl -cp /srv/orbit/worktrees/laravel | grep -Eq '^user:caddy:--x$' \
  || fail "shared Caddy ACL was released before the last workspace"

echo "caddy: shared traverse ACL survived the first derived removal"

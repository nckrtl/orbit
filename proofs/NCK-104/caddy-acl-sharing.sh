#!/usr/bin/env bash
# Caddy traverse ACLs are shared, restore after the last dependent, and preserve pre-existing ACLs.
source /var/lib/orbit-e2e/proof/lib.sh

orb7_arm_paths caddy-acl-sharing /home/orbit/.orbit/worktrees/laravel/e2e /home/orbit/apps/laravel/.git/worktrees
orb7_arm_remote_database caddy-acl-sharing
orb7_traps caddy-acl-sharing gateway
dev_id=$(instance_id e2e-dev)
orbit workspace:remove "$(workspace_id e2e)" --json >/dev/null
orb7_mark_active caddy-acl-sharing gateway
orb7_checkpoint caddy-acl-sharing
test ! -e /home/orbit/.orbit/worktrees/laravel/e2e

setfacl -m u:caddy:r-x /home/orbit/.orbit/worktrees
original_default_worktrees=$(getfacl -cp /home/orbit/.orbit/worktrees)
orbit node:role:add app-dev app-dev --converge --json >/dev/null
[[ "$(getfacl -cp /home/orbit/.orbit/worktrees)" == "$original_default_worktrees" ]] \
  || fail "app-dev role convergence changed the pre-existing default-worktree Caddy ACL"

shared=$(orbit workspace:new "$dev_id" nck104-shared --json)
[[ "$(echo "$shared" | json_get checkout_path)" == /srv/orbit/worktrees/laravel/nck104-shared ]] \
  || fail "shared workspace was not derived: $shared"

grep -Eq '^user:caddy:--x$' <<<"$(getfacl -cp /srv/orbit/worktrees/laravel)" \
  || fail "shared grouping dir missing Caddy traverse ACL"
grep -Eq '^user:caddy:--x$' <<<"$(getfacl -cp /srv/orbit/worktrees)" \
  || fail "worktree root missing Caddy traverse ACL"

orbit workspace:remove "$(workspace_id nck104-derived)" --json >/dev/null
test ! -e /srv/orbit/worktrees/laravel/nck104-derived
test -d /srv/orbit/worktrees/laravel
grep -Eq '^user:caddy:--x$' <<<"$(getfacl -cp /srv/orbit/worktrees/laravel)" \
  || fail "shared Caddy ACL was released before the last workspace"

mkdir -p /home/orbit/projects-nck104
setfacl -m u:nobody:r-x /home/orbit/projects-nck104
original=$(getfacl -cp /home/orbit/projects-nck104)
orbit workspace:new "$dev_id" nck104-acl-a --path=/home/orbit/projects-nck104/a --json >/dev/null
orbit workspace:new "$dev_id" nck104-acl-b --path=/home/orbit/projects-nck104/b --json >/dev/null
grep -Eq '^user:caddy:--x$' <<<"$(getfacl -cp /home/orbit/projects-nck104)" \
  || fail "custom parent missing Caddy traverse ACL after first dependents"
orbit workspace:remove "$(workspace_id nck104-acl-a)" --json >/dev/null
grep -Eq '^user:caddy:--x$' <<<"$(getfacl -cp /home/orbit/projects-nck104)" \
  || fail "pre-existing parent ACL sharing was released too early"
orbit workspace:remove "$(workspace_id nck104-acl-b)" --json >/dev/null
[[ "$(getfacl -cp /home/orbit/projects-nck104)" == "$original" ]] \
  || fail "pre-existing parent ACL was not restored after the last dependent"

mkdir -p /home/orbit/projects-nck104-caddy
setfacl -m u:caddy:r-x /home/orbit/projects-nck104-caddy
original_caddy=$(getfacl -cp /home/orbit/projects-nck104-caddy)
orbit workspace:new "$dev_id" nck104-caddy-a --path=/home/orbit/projects-nck104-caddy/a --json >/dev/null
grep -Fqx 'user:caddy:r-x' <<<"$(getfacl -cp /home/orbit/projects-nck104-caddy)" \
  || fail "pre-existing Caddy r-x was narrowed with one dependent"
orbit workspace:new "$dev_id" nck104-caddy-b --path=/home/orbit/projects-nck104-caddy/b --json >/dev/null
grep -Fqx 'user:caddy:r-x' <<<"$(getfacl -cp /home/orbit/projects-nck104-caddy)" \
  || fail "pre-existing Caddy r-x was narrowed with multiple dependents"
orbit workspace:remove "$(workspace_id nck104-caddy-a)" --json >/dev/null
grep -Fqx 'user:caddy:r-x' <<<"$(getfacl -cp /home/orbit/projects-nck104-caddy)" \
  || fail "pre-existing Caddy r-x was narrowed after removing one dependent"
orbit workspace:remove "$(workspace_id nck104-caddy-b)" --json >/dev/null
[[ "$(getfacl -cp /home/orbit/projects-nck104-caddy)" == "$original_caddy" ]] \
  || fail "pre-existing Caddy ACL was not restored after the last dependent"

setfacl -m u:caddy:r-x /home/orbit /home/orbit/apps /home/orbit/.orbit
orbit workspace:new "$dev_id" nck104-home-a --path=/home/orbit/.orbit/worktrees/nck104-home-a --json >/dev/null
grep -Fqx 'user:caddy:r-x' <<<"$(getfacl -cp /home/orbit/.orbit/worktrees)" \
  || fail "managed-home worktrees Caddy r-x was narrowed with one dependent"
grep -Fqx 'user:caddy:r-x' <<<"$(getfacl -cp /home/orbit/.orbit)" \
  || fail "managed-home .orbit Caddy r-x was narrowed with one dependent"
grep -Fqx 'user:caddy:r-x' <<<"$(getfacl -cp /home/orbit)" \
  || fail "managed home Caddy r-x was narrowed with one extra dependent"
grep -Fqx 'user:caddy:r-x' <<<"$(getfacl -cp /home/orbit/apps)" \
  || fail "managed-home apps Caddy r-x was narrowed with one extra dependent"
orbit workspace:new "$dev_id" nck104-home-b --path=/home/orbit/.orbit/worktrees/nck104-home-b --json >/dev/null
grep -Fqx 'user:caddy:r-x' <<<"$(getfacl -cp /home/orbit/.orbit/worktrees)" \
  || fail "managed-home worktrees Caddy r-x was narrowed with multiple dependents"
orbit workspace:remove "$(workspace_id nck104-home-a)" --json >/dev/null
grep -Fqx 'user:caddy:r-x' <<<"$(getfacl -cp /home/orbit/.orbit/worktrees)" \
  || fail "managed-home worktrees Caddy r-x was released before the last extra dependent"
grep -Fqx 'user:caddy:r-x' <<<"$(getfacl -cp /home/orbit)" \
  || fail "managed home Caddy r-x was released while the instance remained"
orbit workspace:remove "$(workspace_id nck104-home-b)" --json >/dev/null
[[ "$(getfacl -cp /home/orbit/.orbit/worktrees)" == "$original_default_worktrees" ]] \
  || fail "managed-home worktrees ACL was not restored after the last dependent"
grep -Fqx 'user:caddy:r-x' <<<"$(getfacl -cp /home/orbit)" \
  || fail "managed home Caddy r-x was released after extra workspace dependents were removed"
grep -Fqx 'user:caddy:r-x' <<<"$(getfacl -cp /home/orbit/apps)" \
  || fail "managed-home apps Caddy r-x was released after extra workspace dependents were removed"

sudo install -d -o root -g root -m 0700 -- /srv/restricted
original_restricted=$(sudo getfacl -cp /srv/restricted)
orbit node:settings app-dev --setting=worktree.path:/srv/restricted/root --json >/dev/null
restricted=$(orbit workspace:new "$dev_id" nck104-restricted --json)
[[ "$(echo "$restricted" | json_get checkout_path)" == /srv/restricted/root/laravel/nck104-restricted ]] \
  || fail "restricted workspace was not derived under the foreign ancestor: $restricted"
grep -Eq '^user:caddy:--x$' <<<"$(sudo getfacl -cp /srv/restricted)" \
  || fail "non-traversable ancestor above the configured root missing Caddy traverse ACL"
grep -Eq '^user:caddy:--x$' <<<"$(getfacl -cp /srv)" \
  || fail "filesystem ancestor above the configured root missing Caddy traverse ACL"
orbit workspace:remove "$(workspace_id nck104-restricted)" --json >/dev/null
test ! -e /srv/restricted/root/laravel/nck104-restricted
[[ "$(sudo getfacl -cp /srv/restricted)" == "$original_restricted" ]] \
  || fail "restricted ancestor ACL was not restored after the last dependent"
restore_default_roots

orb7_remote_state gateway discard caddy-acl-sharing
orb7_publish caddy-acl-sharing
echo "caddy: role convergence preserves broad ACLs; shared grants survive; last removal restores exact ACLs"

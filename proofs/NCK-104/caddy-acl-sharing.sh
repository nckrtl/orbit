#!/usr/bin/env bash
# Caddy traverse ACLs are shared, restore after the last dependent, and preserve pre-existing ACLs.
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

mkdir -p /home/orbit/projects-nck104
setfacl -m u:nobody:r-x /home/orbit/projects-nck104
original=$(getfacl -cp /home/orbit/projects-nck104)
orbit workspace:new "$dev_id" nck104-acl-a --path=/home/orbit/projects-nck104/a --json >/dev/null
orbit workspace:new "$dev_id" nck104-acl-b --path=/home/orbit/projects-nck104/b --json >/dev/null
getfacl -cp /home/orbit/projects-nck104 | grep -Eq '^user:caddy:--x$' \
  || fail "custom parent missing Caddy traverse ACL after first dependents"
orbit workspace:remove "$(workspace_id nck104-acl-a)" --json >/dev/null
getfacl -cp /home/orbit/projects-nck104 | grep -Eq '^user:caddy:--x$' \
  || fail "pre-existing parent ACL sharing was released too early"
orbit workspace:remove "$(workspace_id nck104-acl-b)" --json >/dev/null
[[ "$(getfacl -cp /home/orbit/projects-nck104)" == "$original" ]] \
  || fail "pre-existing parent ACL was not restored after the last dependent"

mkdir -p /home/orbit/projects-nck104-caddy
setfacl -m u:caddy:r-x /home/orbit/projects-nck104-caddy
original_caddy=$(getfacl -cp /home/orbit/projects-nck104-caddy)
orbit workspace:new "$dev_id" nck104-caddy-a --path=/home/orbit/projects-nck104-caddy/a --json >/dev/null
getfacl -cp /home/orbit/projects-nck104-caddy | grep -Fqx 'user:caddy:r-x' \
  || fail "pre-existing Caddy r-x was narrowed with one dependent"
orbit workspace:new "$dev_id" nck104-caddy-b --path=/home/orbit/projects-nck104-caddy/b --json >/dev/null
getfacl -cp /home/orbit/projects-nck104-caddy | grep -Fqx 'user:caddy:r-x' \
  || fail "pre-existing Caddy r-x was narrowed with multiple dependents"
orbit workspace:remove "$(workspace_id nck104-caddy-a)" --json >/dev/null
getfacl -cp /home/orbit/projects-nck104-caddy | grep -Fqx 'user:caddy:r-x' \
  || fail "pre-existing Caddy r-x was narrowed after removing one dependent"
orbit workspace:remove "$(workspace_id nck104-caddy-b)" --json >/dev/null
[[ "$(getfacl -cp /home/orbit/projects-nck104-caddy)" == "$original_caddy" ]] \
  || fail "pre-existing Caddy ACL was not restored after the last dependent"

setfacl -m u:caddy:r-x /home/orbit /home/orbit/apps /home/orbit/.orbit /home/orbit/.orbit/worktrees
orbit workspace:new "$dev_id" nck104-home-a --path=/home/orbit/.orbit/worktrees/nck104-home-a --json >/dev/null
getfacl -cp /home/orbit/.orbit/worktrees | grep -Fqx 'user:caddy:r-x' \
  || fail "managed-home worktrees Caddy r-x was narrowed with one dependent"
getfacl -cp /home/orbit/.orbit | grep -Fqx 'user:caddy:r-x' \
  || fail "managed-home .orbit Caddy r-x was narrowed with one dependent"
getfacl -cp /home/orbit | grep -Fqx 'user:caddy:r-x' \
  || fail "managed home Caddy r-x was narrowed with one extra dependent"
getfacl -cp /home/orbit/apps | grep -Fqx 'user:caddy:r-x' \
  || fail "managed-home apps Caddy r-x was narrowed with one extra dependent"
orbit workspace:new "$dev_id" nck104-home-b --path=/home/orbit/.orbit/worktrees/nck104-home-b --json >/dev/null
getfacl -cp /home/orbit/.orbit/worktrees | grep -Fqx 'user:caddy:r-x' \
  || fail "managed-home worktrees Caddy r-x was narrowed with multiple dependents"
orbit workspace:remove "$(workspace_id nck104-home-a)" --json >/dev/null
getfacl -cp /home/orbit/.orbit/worktrees | grep -Fqx 'user:caddy:r-x' \
  || fail "managed-home worktrees Caddy r-x was released before the last extra dependent"
getfacl -cp /home/orbit | grep -Fqx 'user:caddy:r-x' \
  || fail "managed home Caddy r-x was released while the instance remained"
orbit workspace:remove "$(workspace_id nck104-home-b)" --json >/dev/null
getfacl -cp /home/orbit/.orbit/worktrees | grep -Fqx 'user:caddy:r-x' \
  || fail "managed-home worktrees Caddy r-x was released while the fixture workspace remained"
getfacl -cp /home/orbit/.orbit | grep -Fqx 'user:caddy:r-x' \
  || fail "managed-home .orbit Caddy r-x was released while the fixture workspace remained"
getfacl -cp /home/orbit | grep -Fqx 'user:caddy:r-x' \
  || fail "managed home Caddy r-x was released after extra workspace dependents were removed"
getfacl -cp /home/orbit/apps | grep -Fqx 'user:caddy:r-x' \
  || fail "managed-home apps Caddy r-x was released after extra workspace dependents were removed"

sudo install -d -o root -g root -m 0700 -- /srv/restricted
original_restricted=$(sudo getfacl -cp /srv/restricted)
orbit node:settings app-dev --setting=worktree.path:/srv/restricted/root --json >/dev/null
restricted=$(orbit workspace:new "$dev_id" nck104-restricted --json)
[[ "$(echo "$restricted" | json_get checkout_path)" == /srv/restricted/root/laravel/nck104-restricted ]] \
  || fail "restricted workspace was not derived under the foreign ancestor: $restricted"
sudo getfacl -cp /srv/restricted | grep -Eq '^user:caddy:--x$' \
  || fail "non-traversable ancestor above the configured root missing Caddy traverse ACL"
getfacl -cp /srv | grep -Eq '^user:caddy:--x$' \
  || fail "filesystem ancestor above the configured root missing Caddy traverse ACL"
orbit workspace:remove "$(workspace_id nck104-restricted)" --json >/dev/null
test ! -e /srv/restricted/root/laravel/nck104-restricted
[[ "$(sudo getfacl -cp /srv/restricted)" == "$original_restricted" ]] \
  || fail "restricted ancestor ACL was not restored after the last dependent"
restore_default_roots

echo "caddy: shared grants survive; last dependent restores pre-existing ACLs"

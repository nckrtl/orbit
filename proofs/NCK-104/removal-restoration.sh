#!/usr/bin/env bash
# Clean up fail-closed fixtures and prove managed-home ancestor restoration.
source /var/lib/orbit-e2e/proof/lib.sh

orbit workspace:remove "$(workspace_id nck104-unsafe)" --json >/dev/null

instance_checkout_path=$(instance_checkout e2e-dev)
identity=/srv/orbit/worktrees/laravel/nck104-identity
rm -f -- "$identity"
git -C "$instance_checkout_path" worktree prune
git -C "$instance_checkout_path" worktree add -- "$identity" nck104-identity >/dev/null
orbit workspace:remove "$(workspace_id nck104-identity)" --json >/dev/null

decoy=$(workspace_checkout nck104-registered-decoy)
rm -rf -- "$decoy"
git -C "$instance_checkout_path" worktree prune --expire=now
git -C "$instance_checkout_path" worktree add -- "$decoy" nck104-registered-decoy >/dev/null
orbit workspace:remove "$(workspace_id nck104-registered-decoy)" --json >/dev/null

drift=$(workspace_checkout nck104-branch-drift)
git -C "$drift" checkout nck104-branch-drift >/dev/null
orbit workspace:remove "$(workspace_id nck104-branch-drift)" --json >/dev/null

runtime_state=$(mktemp -d /tmp/orbit-nck104-laravel.XXXXXX)
test -d /home/orbit/apps/laravel/vendor
mv /home/orbit/apps/laravel/vendor "$runtime_state/vendor"
if [[ -e /home/orbit/apps/laravel/.env || -L /home/orbit/apps/laravel/.env ]]; then
  mv /home/orbit/apps/laravel/.env "$runtime_state/.env"
fi
orbit instance:remove "$(instance_id e2e-dev)" --json >/dev/null
test ! -e /home/orbit/apps/laravel

orbit node:settings app-dev --setting=instance.path: --setting=worktree.path: --json >/dev/null
original_home_acl=$(sudo getfacl -cp /home)
restore_home_acl() {
  printf '%s\n' "$original_home_acl" | sudo setfacl --set-file=- /home
}
trap restore_home_acl EXIT
sudo setfacl -m u:caddy:--- /home
nontraversable_home_acl=$(sudo getfacl -cp /home)
sudo getfacl -cp /home | grep -Fqx 'user:caddy:---' \
  || fail "managed-home ancestor remained traversable before the fresh checkout"

home_app=$(orbit app:new nck104-home-ancestor https://github.com/laravel/laravel.git --json)
home_app_id=$(echo "$home_app" | json_get id)
home_instance=$(orbit instance:new "$home_app_id" "$(node_id app-dev)" nck104-home-ancestor --json)
[[ "$(echo "$home_instance" | json_get checkout_path)" == /home/orbit/apps/nck104-home-ancestor ]] \
  || fail "fresh managed-home instance used the wrong checkout: $home_instance"
sudo getfacl -cp /home | grep -Fqx 'user:caddy:--x' \
  || fail "fresh managed-home checkout did not grant Caddy traversal on /home"

orbit instance:remove "$(instance_id nck104-home-ancestor)" --json >/dev/null
test ! -e /home/orbit/apps/nck104-home-ancestor
[[ "$(sudo getfacl -cp /home)" == "$nontraversable_home_acl" ]] \
  || fail "last managed-home dependent did not restore the non-traversable /home ACL"
restore_home_acl
trap - EXIT
[[ "$(sudo getfacl -cp /home)" == "$original_home_acl" ]] \
  || fail "proof did not restore the original /home ACL"

recreated_instance=$(orbit instance:new "$(app_id laravel)" "$(node_id app-dev)" e2e-dev \
  --environment=development --json)
recreated_instance_id=$(echo "$recreated_instance" | json_get id)
[[ "$(echo "$recreated_instance" | json_get checkout_path)" == /home/orbit/apps/laravel ]] \
  || fail "recreated e2e-dev used the wrong checkout: $recreated_instance"
mv "$runtime_state/vendor" /home/orbit/apps/laravel/vendor
if [[ -e "$runtime_state/.env" || -L "$runtime_state/.env" ]]; then
  mv "$runtime_state/.env" /home/orbit/apps/laravel/.env
fi
rmdir "$runtime_state"
php /home/orbit/apps/laravel/artisan --version >/dev/null
orbit workspace:new "$recreated_instance_id" e2e --branch=e2e --json >/dev/null

echo "removal: fixtures cleaned; exact checkout removal restored the managed-home ancestor ACL"

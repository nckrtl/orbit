#!/usr/bin/env bash
# Missing roots are created 0755 orbit-owned; unsafe existing owners and modes fail closed.
source /var/lib/orbit-e2e/proof/lib.sh

test "$(stat -c '%U:%G %a' /srv/orbit/instances)" = 'orbit:orbit 755'
test "$(stat -c '%U:%G %a' /srv/orbit/worktrees)" = 'orbit:orbit 755'

before=$(app_dev_settings)
expect_error node.settings_root_failed orbit node:settings app-dev --setting=instance.path:/mnt/orbit-loose --json
[[ "$(app_dev_settings)" == "$before" ]] || fail "group-writable root was persisted by node:settings"
test "$(stat -c '%U:%G %a' /mnt/orbit-loose)" = 'orbit:orbit 775'

expect_error node.settings_root_failed provision_app_dev --setting=instance.path:/mnt/orbit-loose --json
[[ "$(app_dev_settings)" == "$before" ]] || fail "group-writable root was persisted by node:provision"
test "$(stat -c '%U:%G %a' /mnt/orbit-loose)" = 'orbit:orbit 775'

expect_error node.settings_root_failed orbit node:settings app-dev --setting=instance.path:/mnt/orbit-foreign --json
[[ "$(app_dev_settings)" == "$before" ]] || fail "foreign-owned root was persisted"
test "$(stat -c '%U:%G %a' /mnt/orbit-foreign)" = 'root:root 755'

out=$(orbit node:settings app-dev --setting=instance.path:/mnt/orbit-ok --json)
[[ "$(echo "$out" | json_get settings.instance.path)" == /mnt/orbit-ok ]] || fail "pre-existing orbit root was rejected: $out"
test "$(stat -c '%U:%G %a' /mnt/orbit-ok)" = 'orbit:orbit 750'

app=$(orbit app:new nck104-ok https://github.com/laravel/laravel.git --json)
app_id=$(echo "$app" | json_get id)
[[ -n "$app_id" ]] || fail "failed to create nck104-ok app: $app"
created=$(orbit instance:new "$app_id" "$(node_id app-dev)" nck104-ok --json) \
  || fail "failed to create instance under /mnt/orbit-ok"
[[ "$(echo "$created" | json_get checkout_path)" == /mnt/orbit-ok/nck104-ok ]] \
  || fail "instance was not placed under /mnt/orbit-ok: $created"
test "$(stat -c '%U:%G %a' /mnt/orbit-ok)" = 'orbit:orbit 750'
test -d /mnt/orbit-ok/nck104-ok
orbit instance:remove "$(echo "$created" | json_get id)" --json >/dev/null
test ! -e /mnt/orbit-ok/nck104-ok
test "$(stat -c '%U:%G %a' /mnt/orbit-ok)" = 'orbit:orbit 750'

restore_default_roots

orbit node:settings app-dev --setting=instance.path: --json >/dev/null
before=$(app_dev_settings)
test -d /home/orbit/apps
sudo chown root:root -- /home/orbit/apps
expect_error node.settings_root_failed orbit node:settings app-dev --setting=worktree.path: --json
[[ "$(app_dev_settings)" == "$before" ]] || fail "failed last unset persisted settings"
sudo chown orbit:orbit -- /home/orbit/apps
restore_default_roots

echo "ownership: missing 755, pre-existing 750 unchanged, loose mode and foreign owner rejected, last unset fails closed"

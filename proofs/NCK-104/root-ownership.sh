#!/usr/bin/env bash
# Missing roots are created 0755 orbit-owned; pre-existing roots keep owner and mode; foreign owners fail closed.
source /var/lib/orbit-e2e/proof/lib.sh

test "$(stat -c '%U:%G %a' /srv/orbit/instances)" = 'orbit:orbit 755'
test "$(stat -c '%U:%G %a' /srv/orbit/worktrees)" = 'orbit:orbit 755'

before=$(app_dev_settings)
expect_error node.settings_root_failed orbit node:settings app-dev --setting=instance.path:/mnt/orbit-foreign --json
[[ "$(app_dev_settings)" == "$before" ]] || fail "foreign-owned root was persisted"
test "$(stat -c '%U:%G %a' /mnt/orbit-foreign)" = 'root:root 755'

out=$(orbit node:settings app-dev --setting=instance.path:/mnt/orbit-ok --json)
[[ "$(echo "$out" | json_get settings.instance.path)" == /mnt/orbit-ok ]] || fail "pre-existing orbit root was rejected: $out"
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

echo "ownership: missing 755, pre-existing 750 unchanged, foreign rejected, last unset fails closed"

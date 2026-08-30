#!/usr/bin/env bash
# CLI --setting splits on the first colon and rejects unknown, duplicate, and malformed keys.
source /var/lib/orbit-e2e/proof/lib.sh

expect_local_error node.setting_unknown orbit node:settings app-dev --setting=packages.path:/srv/orbit/packages --json
expect_local_error node.setting_duplicate orbit node:settings app-dev \
  --setting=instance.path:/srv/a --setting=instance.path:/srv/b --json
expect_local_error node.setting_invalid orbit node:settings app-dev --setting=instance.path --json
expect_local_error node.setting_invalid orbit node:settings app-dev --setting=:/srv/a --json

out=$(orbit node:settings app-dev \
  --setting=instance.path:/srv/orbit:data/instances \
  --setting=worktree.path: \
  --json)
[[ "$(echo "$out" | json_get settings.instance.path)" == '/srv/orbit:data/instances' ]] \
  || fail "first-colon split dropped later colons: $out"
[[ "$(echo "$out" | json_get settings.worktree)" == null ]] \
  || fail "empty unset did not clear worktree: $out"

restore_default_roots

out=$(provision_app_dev \
  --setting=instance.path:/srv/orbit:data/instances \
  --setting=worktree.path: \
  --json) || fail "provision later-colon/empty-unset failed: $out"
[[ "$(echo "$out" | json_get settings.instance.path)" == '/srv/orbit:data/instances' ]] \
  || fail "provision dropped later colons: $out"
[[ "$(echo "$out" | json_get settings.worktree)" == null ]] \
  || fail "provision empty unset did not clear worktree: $out"
[[ "$(sql_node_settings app-dev)" == '{"instance":{"path":"/srv/orbit:data/instances"}}' ]] \
  || fail "provision later-colon/empty-unset did not persist"

restore_default_roots
echo "cli: closed keys, duplicates, and first-colon values"

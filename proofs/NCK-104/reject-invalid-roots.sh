#!/usr/bin/env bash
# Closed keys, protected paths, symlinks, overlap, grammar, and managed checkouts fail without persisting.
source /var/lib/orbit-e2e/proof/lib.sh

before=$(sql_node_settings app-dev)

expect_error node.setting_unknown orbit node:settings app-dev --setting=packages.path:/srv/orbit/packages --json
expect_error node.setting_invalid orbit node:settings app-dev --setting=instance.path --json
expect_error node.settings_path_protected orbit node:settings app-dev --setting=instance.path:/etc/orbit --json
expect_error node.settings_path_protected orbit node:settings gateway --setting=instance.path:/etc/orbit --json
expect_error node.settings_roots_overlap orbit node:settings app-dev \
  --setting=instance.path:/srv/orbit/source --setting=worktree.path:/srv/orbit/source/worktrees --json
expect_error node.settings_root_failed orbit node:settings app-dev --setting=instance.path:/mnt/orbit-link --json
expect_error node.settings_path_invalid orbit node:settings app-dev --setting=instance.path:/srv/orbit/instances/ --json
expect_error node.settings_path_invalid orbit node:settings app-dev --setting=instance.path:/srv/../orbit/instances --json
expect_error node.settings_path_managed orbit node:settings app-dev --setting=instance.path:/home/orbit/apps/laravel --json

gateway_checkout=${ORBIT_GATEWAY_CHECKOUT:-/home/orbit/orbit-gateway}
gateway_checkout=${gateway_checkout%/}
expect_error node.settings_path_protected orbit node:settings app-dev --setting=instance.path:"$gateway_checkout" --json
expect_error node.settings_path_protected orbit node:settings app-dev --setting=instance.path:"$gateway_checkout/nested" --json
expect_error node.settings_path_protected orbit node:settings app-dev --setting=instance.path:"$(dirname "$gateway_checkout")" --json

expect_error node.setting_unknown provision_app_dev --setting=packages.path:/srv/orbit/packages --json
expect_error node.setting_invalid provision_app_dev --setting=instance.path --json
expect_error node.settings_path_protected provision_app_dev --setting=instance.path:/etc/orbit --json

[[ "$(sql_node_settings app-dev)" == "$before" ]] || fail "rejected settings were persisted"

echo "reject: closed, protected, overlap, symlink, grammar, and managed paths did not persist"

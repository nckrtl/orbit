#!/usr/bin/env bash
# Closed keys, protected paths, symlinks, and overlapping roots fail without persisting.
source /var/lib/orbit-e2e/proof/lib.sh

before=$(sql_node_settings app-dev)

expect_error node.settings_path_protected orbit node:settings app-dev --setting=instance.path:/etc/orbit --json
expect_error node.settings_roots_overlap orbit node:settings app-dev \
  --setting=instance.path:/srv/orbit/source --setting=worktree.path:/srv/orbit/source/worktrees --json
expect_error node.settings_root_failed orbit node:settings app-dev --setting=instance.path:/mnt/orbit-link --json
expect_error node.settings_path_invalid orbit node:settings app-dev --setting=instance.path:/srv/orbit/instances/ --json

[[ "$(sql_node_settings app-dev)" == "$before" ]] || fail "rejected settings were persisted"

echo "reject: protected, overlap, symlink, and trailing-separator paths did not persist"

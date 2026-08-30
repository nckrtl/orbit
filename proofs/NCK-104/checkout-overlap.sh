#!/usr/bin/env bash
# Checkout overlap uses directory boundaries, not string prefixes.
source /var/lib/orbit-e2e/proof/lib.sh

dev_id=$(instance_id e2e-dev)

expect_error workspace.path_taken orbit workspace:new "$dev_id" nck104-overlap \
  --path=/home/orbit/.orbit/worktrees/laravel/e2e --json
expect_error workspace.path_taken orbit workspace:new "$dev_id" nck104-parent-overlap \
  --path=/home/orbit/.orbit/worktrees/laravel --json

lookalike=$(orbit workspace:new "$dev_id" nck104-lookalike \
  --path=/home/orbit/.orbit/worktrees/laravel/e2e-extra --json)
[[ "$(echo "$lookalike" | json_get checkout_path)" == /home/orbit/.orbit/worktrees/laravel/e2e-extra ]] \
  || fail "lookalike prefix was treated as overlap: $lookalike"

orbit workspace:remove "$(workspace_id nck104-lookalike)" --json >/dev/null
test ! -e /home/orbit/.orbit/worktrees/laravel/e2e-extra

echo "overlap: equal and parent paths rejected; e2e-extra prefix allowed"

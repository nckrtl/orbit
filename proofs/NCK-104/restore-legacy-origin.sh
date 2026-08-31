#!/usr/bin/env bash
# Restore the recreated fixture to the legacy null-origin state on the Gateway.
source /var/lib/orbit-e2e/proof/lib.sh

sql_clear_workspace_origin e2e
[[ "$(sql_workspace_origin e2e)" == null ]] || fail "recreated e2e workspace is not a legacy row"

echo "removal: recreated e2e workspace restored as a legacy row"

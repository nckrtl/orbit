#!/usr/bin/env bash
# One command removes an unreachable node that still holds a role. Orbit sheds
# the role itself, and the response names what it shed and what it had to leave
# on the box.
source /var/lib/orbit-e2e/proof/lib.sh

prod_id=$(node_id app-prod)
[[ -n "$prod_id" ]] || fail "could not resolve the app-prod node id"
[[ "$(node_roles app-prod)" == app-prod ]] || fail "app-prod does not hold the app-prod role"

removed=$(orbit node:remove "$prod_id" --force --offline --json) || fail "offline node removal failed: $removed"

[[ "$(echo "$removed" | json_get removed)" == true ]] || fail "removal not reported: $removed"
[[ "$(echo "$removed" | json_get degradation)" == unreachable ]] || fail "degradation not reported: $removed"
[[ "$(echo "$removed" | json_get roles_shed)" == '["app-prod"]' ]] || fail "shed roles not reported: $removed"
[[ "$(echo "$removed" | json_get retained_on_node)" == *app-prod* ]] || fail "retained state not reported: $removed"
[[ -n "$(echo "$removed" | json_get follow_up)" ]] || fail "node-local follow-up not reported: $removed"

[[ "$(node_present app-prod)" == no ]] || fail "app-prod is still registered"

echo "node-remove-offline: one command shed [app-prod] and removed the unreachable node"

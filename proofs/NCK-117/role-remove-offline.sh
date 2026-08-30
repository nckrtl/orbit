#!/usr/bin/env bash
# An operator who only wants the role gone gets the same treatment: the role is
# shed, the node stays registered, and the report names what was left on it.
source /var/lib/orbit-e2e/proof/lib.sh

dev_id=$(node_id app-dev)
[[ -n "$dev_id" ]] || fail "could not resolve the app-dev node id"

removed=$(orbit node:role:remove "$dev_id" metrics --force --offline --json) \
  || fail "offline role removal failed: $removed"

[[ "$(echo "$removed" | json_get removed)" == true ]] || fail "role removal not reported: $removed"
[[ "$(echo "$removed" | json_get degradation)" == unreachable ]] || fail "degradation not reported: $removed"
[[ "$(echo "$removed" | json_get retained_on_node)" == *Grafana* ]] || fail "retained state not reported: $removed"
[[ -n "$(echo "$removed" | json_get follow_up)" ]] || fail "node-local follow-up not reported: $removed"

[[ "$(node_present app-dev)" == yes ]] || fail "the node was removed by a role removal"
[[ "$(node_roles app-dev)" == app-dev ]] || fail "app-dev kept the wrong roles: $(node_roles app-dev)"

echo "role-remove-offline: metrics shed from an unreachable app-dev, node still registered"

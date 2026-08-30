#!/usr/bin/env bash
# Role convergence and role removal must both succeed while one fleet node is
# unreachable. Each of these reconciles the Metrics fleet, and each of them
# failed the moment a peer stopped answering: a role add on the healthy
# app-dev node left its own assignment in `failed`.
source /var/lib/orbit-e2e/proof/lib.sh

dev_id=$(node_id app-dev)
[[ -n "$dev_id" ]] || fail "could not resolve the app-dev node id"

# A non-Metrics role convergence: NativeRoleBaselineConverger::converge().
added=$(orbit node:role:add app-dev app-dev --converge --json) || fail "role add failed: $added"
[[ "$(echo "$added" | json_get assignment.status)" == active ]] || fail "role add did not activate: $added"

# A role removal: MetricsRoleBaseline::remove() converges the whole fleet.
removed=$(orbit node:role:remove "$dev_id" metrics --force --json) || fail "role remove failed: $removed"
[[ "$(echo "$removed" | json_get removed)" == true ]] || fail "role remove did not report removal: $removed"
[[ "$(orbit metrics:status --json | json_get enabled)" == false ]] || fail "metrics still enabled after role removal"

echo "role-mutation: role add and Metrics role removal both succeeded with app-prod unreachable"

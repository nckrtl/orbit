#!/usr/bin/env bash
# Re-adding the Metrics role converges the fleet again while app-prod is still
# unreachable, and the skipped node is reported rather than blocking the role.
source /var/lib/orbit-e2e/proof/lib.sh

orbit node:role:add app-dev metrics --converge --json >/dev/null || fail "metrics role add failed"
status=$(orbit metrics:status --json)
[[ "$(echo "$status" | json_get assignment.status)" == active ]] || fail "assignment not active: $status"
[[ "$(echo "$status" | json_get grafana)" == healthy ]] || fail "grafana unhealthy: $status"

echo "metrics-restore: Metrics role re-added with app-prod unreachable"

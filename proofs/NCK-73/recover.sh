#!/usr/bin/env bash
# A failed assignment (Docker stopped) is recovered by node:role:add app-dev metrics --converge.
source /var/lib/orbit-e2e/proof/lib.sh

sudo systemctl stop docker.socket docker.service
attempt=$(orbit metrics:enable app-dev --json || true)
sudo systemctl start docker.socket docker.service
echo "$attempt" | grep -q '"code":"node_role.convergence_failed"' || fail "enable did not fail as expected: $attempt"
[[ "$(orbit metrics:status --json | json_get assignment.status)" == failed ]] || fail "assignment not failed"

orbit node:role:add app-dev metrics --converge --json >/dev/null
status=$(orbit metrics:status --json)
[[ "$(echo "$status" | json_get assignment.status)" == active ]] || fail "recovery did not activate: $status"
[[ "$(echo "$status" | json_get grafana)" == healthy ]] || fail "grafana unhealthy after recovery: $status"
echo "recover: failed assignment recovered through node:role:add --converge"

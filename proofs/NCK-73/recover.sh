#!/usr/bin/env bash
# A failed assignment (Docker stopped) is recovered by node:role:add app-dev metrics --converge.
proof_root=${ORBIT_E2E_PROOF_ROOT:-/var/lib/orbit-e2e/proof}
source "$proof_root/lib.sh"

orb7_service_traps recover
orb7_service_record recover docker.socket docker.service
orb7_checkpoint recover post-record
sudo systemctl stop docker.socket docker.service
orb7_checkpoint recover post-mutation
attempt=$(orbit metrics:enable app-dev --json || true)
orb7_restore_services recover
trap - EXIT INT TERM
grep -q '"code":"node_role.convergence_failed"' <<<"$attempt" || fail "enable did not fail as expected: $attempt"
[[ "$(orbit metrics:status --json | json_get assignment.status)" == failed ]] || fail "assignment not failed"

orbit node:role:add app-dev metrics --converge --json >/dev/null
status=$(orbit metrics:status --json)
[[ "$(echo "$status" | json_get assignment.status)" == active ]] || fail "recovery did not activate: $status"
[[ "$(echo "$status" | json_get grafana)" == healthy ]] || fail "grafana unhealthy after recovery: $status"
echo "recover: failed assignment recovered through node:role:add --converge"

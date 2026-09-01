#!/usr/bin/env bash
# The Metrics node is never degraded. With its own sshd down, a fleet mutation
# must fail closed rather than skip the node that owns the projection.
proof_root=${ORBIT_E2E_PROOF_ROOT:-/var/lib/orbit-e2e/proof}
source "$proof_root/lib.sh"

orb7_service_traps metrics-node-fails-closed
orb7_service_record metrics-node-fails-closed ssh.socket ssh.service
orb7_checkpoint metrics-node-fails-closed post-record
for unit in ssh.socket ssh.service; do
  systemctl cat "$unit" >/dev/null 2>&1 && sudo systemctl stop "$unit" || true
done
orb7_checkpoint metrics-node-fails-closed post-mutation

attempt=$(orbit metrics:exporter:disable gateway --json || true)
grep -q '"code":"metrics.exporter_configuration_inspection_failed"' <<<"$attempt" \
  || fail "fleet mutation did not fail closed on the Metrics node: $attempt"

orb7_restore_services metrics-node-fails-closed
trap - EXIT INT TERM

# The same mutation succeeds again once the Metrics node answers, which proves
# the failure was the node and not the command.
recovered=$(orbit metrics:exporter:disable gateway --json) || fail "mutation still failing: $recovered"
[[ "$(echo "$recovered" | json_get status)" == disabled ]] || fail "unexpected result: $recovered"
orbit metrics:exporter:enable gateway --json >/dev/null || fail "could not restore the gateway preference"

echo "metrics-node-fails-closed: fleet mutation refused while the Metrics node was unreachable"

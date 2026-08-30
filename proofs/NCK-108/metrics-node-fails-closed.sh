#!/usr/bin/env bash
# The Metrics node is never degraded. With its own sshd down, a fleet mutation
# must fail closed rather than skip the node that owns the projection.
source /var/lib/orbit-e2e/proof/lib.sh

restore_ssh() {
  for unit in ssh.socket ssh.service; do
    systemctl cat "$unit" >/dev/null 2>&1 && sudo systemctl start "$unit" || true
  done
}
trap restore_ssh EXIT

for unit in ssh.socket ssh.service; do
  systemctl cat "$unit" >/dev/null 2>&1 && sudo systemctl stop "$unit" || true
done

attempt=$(orbit metrics:exporter:disable gateway --json || true)
echo "$attempt" | grep -q '"code":"metrics.exporter_configuration_inspection_failed"' \
  || fail "fleet mutation did not fail closed on the Metrics node: $attempt"

restore_ssh
trap - EXIT

# The same mutation succeeds again once the Metrics node answers, which proves
# the failure was the node and not the command.
recovered=$(orbit metrics:exporter:disable gateway --json) || fail "mutation still failing: $recovered"
[[ "$(echo "$recovered" | json_get status)" == disabled ]] || fail "unexpected result: $recovered"
orbit metrics:exporter:enable gateway --json >/dev/null || fail "could not restore the gateway preference"

echo "metrics-node-fails-closed: fleet mutation refused while the Metrics node was unreachable"

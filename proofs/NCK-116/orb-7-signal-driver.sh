#!/usr/bin/env bash
proof_root=${ORBIT_E2E_PROOF_ROOT:-/var/lib/orbit-e2e/proof}
source "$proof_root/lib.sh"

action=${1:?action required}
event=${2:?event required}
window=${3:?window required}
deadline=${4:?deadline required}
[[ "$action" =~ ^(escape-exporter-node|escape-metrics-node|escape-without-wireguard-address|refuses-a-shifted-rule-number|refuses-without-proof)$ ]]
[[ "$event" =~ ^(EXIT|INT|TERM)$ && "$window" =~ ^(post-record|post-mutation)$ \
  && "$deadline" =~ ^[1-9][0-9]*$ ]]
expected=130
[[ "$event" == EXIT ]] && expected=0
[[ "$event" == TERM ]] && expected=143
checkpoint="/var/tmp/orbit-e2e-orb7-${action}-${window}-${event}.ready"

driver_cleanup() {
  sudo rm -f -- "$checkpoint"
}
trap driver_cleanup EXIT INT TERM
sudo test ! -e "$checkpoint" || fail "checkpoint already exists: $checkpoint"

env ORBIT_E2E_PROOF_ROOT="$proof_root" ORBIT_E2E_ORB7_MODE=signal \
  ORBIT_E2E_ORB7_CASE="$action" ORBIT_E2E_ORB7_EVENT="$event" ORBIT_E2E_ORB7_WINDOW="$window" \
  ORBIT_E2E_ORB7_CHECKPOINT="$checkpoint" python3 - "$proof_root/$action.sh" <<'PY' &
import os
import signal
import sys

os.setsid()
signal.signal(signal.SIGINT, signal.SIG_DFL)
signal.signal(signal.SIGTERM, signal.SIG_DFL)
os.execv('/usr/bin/bash', ['bash', sys.argv[1]])
PY
pid=$!
for _ in $(seq 1 300); do
  sudo test ! -f "$checkpoint" || break
  kill -0 "$pid" 2>/dev/null || break
  sleep 0.1
done
sudo test -f "$checkpoint" || fail "$action did not reach its $window checkpoint"
if [[ "$event" != EXIT ]]; then
  kill -s "$event" -- "-$pid"
fi
timeout "$((deadline + 7))s" tail --pid="$pid" -f /dev/null || fail "$action did not exit within its deadline and cleanup grace"
set +e
wait "$pid"
status=$?
set -e
[[ "$status" -eq "$expected" ]] || fail "$action returned $status after $event, expected $expected"
orb7_restore_owned "$action"
sudo test ! -e "$ORB7_CLEANUP_ROOT/$action" || fail "$action cleanup was not idempotent"
echo "$action: $event at $window restored its exact owned delta and returned $status"
